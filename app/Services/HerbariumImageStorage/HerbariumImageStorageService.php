<?php

namespace App\Services\HerbariumImageStorage;

use App\Exceptions\HerbariumImageImportException;
use App\Models\Herbarium;
use App\Models\HerbariumImages;
use App\Services\HerbariumImageMatching\HerbariumImageMatchType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\File;
use Throwable;

class HerbariumImageStorageService
{
    public const MAX_FILE_SIZE = 5 * 1024 * 1024;

    private const DISK = 'public';

    private const DIRECTORY = 'herbarium';

    private const CHECKSUM_UNIQUE_INDEX = 'herbarium_images_herbarium_checksum_unique';

    private const MAX_FILENAME_LENGTH = 255;

    private const MAX_FILENAME_ATTEMPTS = 10;

    public function __construct(
        private readonly HerbariumImageActivityLogger $activityLogger,
    ) {
    }

    public function import(
        Herbarium $herbarium,
        File $file,
        string $originalFilename,
        HerbariumImageAssignmentType $assignmentType,
        HerbariumImageImportSource $importSource,
        ?HerbariumImageMatchType $matchType = null,
        ?Model $causer = null,
    ): HerbariumImageStorageResult {
        $path = $file->getPathname();
        [$extension, $size] = $this->validateAndInspectFile($path);
        $checksum = hash_file('sha256', $path);

        if (! is_string($checksum)) {
            throw new HerbariumImageImportException('Unable to calculate the image checksum.');
        }

        $existing = HerbariumImages::query()
            ->where('herbarium_id', $herbarium->getKey())
            ->where('checksum', $checksum)
            ->first();

        if ($existing !== null) {
            return HerbariumImageStorageResult::duplicate($existing, $checksum);
        }

        $sanitizedOriginalFilename = $this->sanitizeOriginalFilename($originalFilename);
        [$storedFilename, $storedPath] = $this->storeWithoutOverwrite(
            $file,
            $sanitizedOriginalFilename,
            $extension,
            $size,
        );

        try {
            $image = DB::transaction(function () use (
                $herbarium,
                $storedFilename,
                $sanitizedOriginalFilename,
                $checksum,
                $assignmentType,
                $importSource,
                $matchType,
                $causer,
            ): HerbariumImages {
                $image = HerbariumImages::create([
                    'herbarium_id' => $herbarium->getKey(),
                    'genus_id' => $herbarium->genus_id,
                    'filename' => $storedFilename,
                    'original_filename' => $sanitizedOriginalFilename,
                    'checksum' => $checksum,
                ]);

                $this->activityLogger->log($image, [
                    'original_filename' => $sanitizedOriginalFilename,
                    'stored_filename' => $storedFilename,
                    'collection_number' => (string) $herbarium->collection_number,
                    'checksum' => $checksum,
                    'assignment' => $assignmentType->value,
                    'import_source' => $importSource->value,
                    'match_type' => $matchType?->value,
                ], $causer);

                return $image;
            });
        } catch (Throwable $exception) {
            $this->removeStoredFile($storedPath, $exception);

            if ($this->isChecksumRace($exception)) {
                $duplicate = HerbariumImages::query()
                    ->where('herbarium_id', $herbarium->getKey())
                    ->where('checksum', $checksum)
                    ->first();

                if ($duplicate !== null) {
                    return HerbariumImageStorageResult::duplicate($duplicate, $checksum);
                }
            }

            throw new HerbariumImageImportException(
                'The image import could not be completed.',
                previous: $exception,
            );
        }

        return HerbariumImageStorageResult::imported($image, $checksum);
    }

    /** @return array{string, int} */
    private function validateAndInspectFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new HerbariumImageImportException('The source is not a readable regular file.');
        }

        $size = filesize($path);

        if (! is_int($size) || $size === 0) {
            throw new HerbariumImageImportException('The source image is empty.');
        }

        if ($size > self::MAX_FILE_SIZE) {
            throw new HerbariumImageImportException('The source image exceeds the 5 MiB limit.');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $imageInfo = @getimagesize($path);
        $contents = file_get_contents($path);
        $decodedImage = is_string($contents) ? @imagecreatefromstring($contents) : false;

        if ($decodedImage !== false) {
            imagedestroy($decodedImage);
        }

        $extension = match (true) {
            $mimeType === 'image/jpeg'
                && is_array($imageInfo)
                && ($imageInfo[2] ?? null) === IMAGETYPE_JPEG
                && $decodedImage !== false => 'jpg',
            $mimeType === 'image/png'
                && is_array($imageInfo)
                && ($imageInfo[2] ?? null) === IMAGETYPE_PNG
                && $decodedImage !== false => 'png',
            default => null,
        };

        if ($extension === null) {
            throw new HerbariumImageImportException('The source is not a valid JPEG or PNG image.');
        }

        return [$extension, $size];
    }

    private function sanitizeOriginalFilename(string $originalFilename): string
    {
        $filename = basename(str_replace('\\', '/', $originalFilename));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? '';
        $filename = trim($filename);

        if ($filename === '') {
            $filename = 'herbarium-image';
        }

        return Str::limit($filename, self::MAX_FILENAME_LENGTH, '');
    }

    /** @return array{string, string} */
    private function storeWithoutOverwrite(
        File $file,
        string $originalFilename,
        string $extension,
        int $expectedSize,
    ): array {
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(self::DIRECTORY);

        for ($attempt = 0; $attempt < self::MAX_FILENAME_ATTEMPTS; $attempt++) {
            $filename = $this->generateFilename($originalFilename, $extension);
            $storedPath = self::DIRECTORY.'/'.$filename;

            if ($disk->exists($storedPath)) {
                continue;
            }

            $source = @fopen($file->getPathname(), 'rb');

            if ($source === false) {
                throw new HerbariumImageImportException('The source image could not be opened for storage.');
            }

            $target = @fopen($disk->path($storedPath), 'xb');

            if ($target === false) {
                fclose($source);

                if ($disk->exists($storedPath)) {
                    continue;
                }

                throw new HerbariumImageImportException('The image could not be stored safely.');
            }

            try {
                $bytesCopied = stream_copy_to_stream($source, $target);
            } catch (Throwable $exception) {
                fclose($source);
                fclose($target);
                $this->removeStoredFile($storedPath, $exception);

                throw new HerbariumImageImportException(
                    'The image could not be stored safely.',
                    previous: $exception,
                );
            }

            fclose($source);
            fclose($target);

            try {
                $storageVerified = $bytesCopied === $expectedSize
                    && $disk->setVisibility($storedPath, 'public')
                    && $disk->exists($storedPath)
                    && $disk->size($storedPath) === $expectedSize;
            } catch (Throwable $exception) {
                $this->removeStoredFile($storedPath, $exception);

                throw new HerbariumImageImportException(
                    'The image could not be stored safely.',
                    previous: $exception,
                );
            }

            if (! $storageVerified) {
                $this->removeStoredFile($storedPath);

                throw new HerbariumImageImportException('The image could not be stored safely.');
            }

            return [$filename, $storedPath];
        }

        throw new HerbariumImageImportException('Unable to allocate a collision-safe image filename.');
    }

    private function generateFilename(string $originalFilename, string $extension): string
    {
        $stem = Str::ascii(pathinfo($originalFilename, PATHINFO_FILENAME));
        $stem = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem) ?? '';
        $stem = trim(preg_replace('/_+/', '_', $stem) ?? '', '_-');
        $stem = $stem === '' ? 'herbarium-image' : $stem;

        $uuidLength = 36;
        $maxStemLength = self::MAX_FILENAME_LENGTH - $uuidLength - strlen($extension) - 2;
        $stem = substr($stem, 0, $maxStemLength);

        return $stem.'-'.Str::uuid().'.'.$extension;
    }

    private function isChecksumRace(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            && ($exception->errorInfo[1] ?? null) === 1062
            && str_contains($exception->getMessage(), self::CHECKSUM_UNIQUE_INDEX);
    }

    private function removeStoredFile(string $storedPath, ?Throwable $previous = null): void
    {
        $disk = Storage::disk(self::DISK);

        try {
            if (! $disk->exists($storedPath)) {
                return;
            }

            if (! $disk->delete($storedPath) || $disk->exists($storedPath)) {
                throw new \RuntimeException('The stored file could not be deleted.');
            }
        } catch (Throwable $exception) {
            throw new HerbariumImageImportException(
                'The failed import could not clean up its stored file.',
                previous: $previous ?? $exception,
            );
        }
    }
}
