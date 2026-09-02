<?php

namespace App\Http\Controllers;

use App\Services\HerbariumImageStorage\HerbariumImageStorageService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class HerbariumImportTemporaryPreviewController extends Controller
{
    public function __invoke(Request $request, string $token): StreamedResponse
    {
        Gate::authorize('import-herbarium-images');

        $filename = $request->query('filename');

        if (! $this->isSafeTemporaryFilename($filename)) {
            abort(404);
        }

        try {
            $disk = FileUploadConfiguration::storage();
            $path = FileUploadConfiguration::path($filename);

            if (! $disk->exists($path)) {
                abort(404);
            }

            $mimeType = $this->inspectImage($disk, $path);

            if ($mimeType === null) {
                abort(404);
            }

            $stream = $disk->readStream($path);

            if (! is_resource($stream)) {
                abort(404);
            }
        } catch (Throwable $exception) {
            if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $exception;
            }

            abort(404);
        }

        return response()->stream(
            static function () use ($stream): void {
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ],
        );
    }

    private function isSafeTemporaryFilename(mixed $filename): bool
    {
        if (! is_string($filename) || $filename === '' || strlen($filename) > 255) {
            return false;
        }

        if (str_contains($filename, "\0")
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || str_contains($filename, '..')
            || preg_match('/[\x00-\x1F\x7F]/', $filename) === 1
        ) {
            return false;
        }

        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+=-]*\z/D', $filename) === 1;
    }

    private function inspectImage(FilesystemAdapter $disk, string $path): ?string
    {
        $source = $disk->readStream($path);
        $temporary = tmpfile();

        if (! is_resource($source) || ! is_resource($temporary)) {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($temporary)) {
                fclose($temporary);
            }

            return null;
        }

        try {
            $bytesCopied = stream_copy_to_stream(
                $source,
                $temporary,
                HerbariumImageStorageService::MAX_FILE_SIZE + 1,
            );

            if (! is_int($bytesCopied)
                || $bytesCopied === 0
                || $bytesCopied > HerbariumImageStorageService::MAX_FILE_SIZE
            ) {
                return null;
            }

            $metadata = stream_get_meta_data($temporary);
            $temporaryPath = $metadata['uri'] ?? null;

            if (! is_string($temporaryPath)) {
                return null;
            }

            $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
            $imageInfo = @getimagesize($temporaryPath);
            $contents = file_get_contents($temporaryPath);
            $decodedImage = is_string($contents) ? @imagecreatefromstring($contents) : false;

            if ($decodedImage !== false) {
                imagedestroy($decodedImage);
            }

            return match (true) {
                $mimeType === 'image/jpeg'
                    && is_array($imageInfo)
                    && ($imageInfo[2] ?? null) === IMAGETYPE_JPEG
                    && $decodedImage !== false => 'image/jpeg',
                $mimeType === 'image/png'
                    && is_array($imageInfo)
                    && ($imageInfo[2] ?? null) === IMAGETYPE_PNG
                    && $decodedImage !== false => 'image/png',
                default => null,
            };
        } finally {
            fclose($source);
            fclose($temporary);
        }
    }
}
