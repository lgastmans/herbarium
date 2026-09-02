<?php

namespace App\Console\Commands;

use App\Models\HerbariumImages;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BackfillHerbariumImageMetadata extends Command
{
    protected $signature = 'herbarium:backfill-image-metadata
        {--apply : Persist the calculated metadata (the default is a dry run)}
        {--limit= : Process at most this many rows, in ascending ID order}';

    protected $description = 'Safely backfill checksums and original filenames for legacy herbarium images';

    /** @var array<string, int> */
    private array $checksumOwners = [];

    /** @var array<string, int> */
    private array $summary = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = $this->validatedLimit();

        if ($limit === false) {
            return self::FAILURE;
        }

        $this->resetSummary();

        if ($apply) {
            $this->warn('APPLY MODE: calculated metadata will be written; image files and historical rows will not be created or deleted.');
        } else {
            $this->warn('DRY RUN ONLY: no database data will be changed. Re-run with --apply only after reviewing this report and taking a backup.');
        }

        $disk = Storage::disk('public');
        $stop = false;

        HerbariumImages::query()
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($apply, $disk, $limit, &$stop): bool {
                foreach ($rows as $row) {
                    if ($limit !== null && $this->summary['rows_scanned'] >= $limit) {
                        $stop = true;

                        return false;
                    }

                    $this->summary['rows_scanned']++;

                    if ($row->checksum !== null && $row->original_filename !== null) {
                        $this->summary['already_complete']++;

                        continue;
                    }

                    $this->processRow($row, $disk, $apply);
                }

                return true;
            });

        if ($stop) {
            $this->line('The requested deterministic row limit was reached.');
        }

        $this->renderSummary($apply);

        return self::SUCCESS;
    }

    private function validatedLimit(): int|false|null
    {
        $value = $this->option('limit');

        if ($value === null) {
            return null;
        }

        if ((! is_string($value) && ! is_int($value))
            || filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value < 1) {
            $this->error('The --limit value must be a positive integer.');

            return false;
        }

        return (int) $value;
    }

    private function resetSummary(): void
    {
        $this->checksumOwners = [];
        $this->summary = [
            'rows_scanned' => 0,
            'already_complete' => 0,
            'rows_updated' => 0,
            'original_filenames' => 0,
            'checksums' => 0,
            'missing_files' => 0,
            'unsafe_filenames' => 0,
            'hashing_failures' => 0,
            'checksum_conflicts' => 0,
            'unexpected_failures' => 0,
        ];
    }

    private function processRow(HerbariumImages $row, FilesystemAdapter $disk, bool $apply): void
    {
        $filename = is_string($row->filename) ? $row->filename : '';

        if (! $this->isSafeStoredFilename($filename)) {
            $this->summary['unsafe_filenames']++;
            $this->warn("Image record {$row->id}: unsafe stored filename; skipped.");

            return;
        }

        $path = 'herbarium/'.$filename;

        try {
            if (! $disk->exists($path)) {
                $this->summary['missing_files']++;
                $this->warn("Image record {$row->id}: stored file is missing.");

                return;
            }

            $checksum = $this->hashStoredFile($disk, $path);
        } catch (Throwable) {
            $this->summary['hashing_failures']++;
            $this->warn("Image record {$row->id}: stored file is unreadable or could not be hashed.");

            return;
        }

        $populateOriginal = $row->original_filename === null;
        $populateChecksum = $row->checksum === null
            && $this->canOwnChecksum($row, $checksum);

        if (! $populateOriginal && ! $populateChecksum) {
            return;
        }

        if (! $apply) {
            $this->summary['rows_updated']++;
            $this->summary['original_filenames'] += (int) $populateOriginal;
            $this->summary['checksums'] += (int) $populateChecksum;
            $this->line("Image record {$row->id}: would populate ".$this->fieldDescription($populateOriginal, $populateChecksum).'.');

            return;
        }

        $originalUpdated = false;
        $checksumUpdated = false;
        $updates = [];

        if ($populateChecksum) {
            $updates['checksum'] = $checksum;
        }

        if ($populateOriginal) {
            $updates['original_filename'] = $filename;
        }

        try {
            $updated = $this->updateNullMetadata($row->id, $updates);
            $checksumUpdated = $updated && $populateChecksum;
            $originalUpdated = $updated && $populateOriginal;
        } catch (QueryException $exception) {
            if ($populateChecksum && $this->isUniqueConstraintFailure($exception)) {
                $this->summary['checksum_conflicts']++;
                $ownerId = $this->databaseChecksumOwner($row->herbarium_id, $checksum);
                $owner = $ownerId === null ? 'another concurrent record' : "image record {$ownerId}";
                $this->warn("Image record {$row->id}: checksum conflict with {$owner}; checksum left unchanged.");

                if ($populateOriginal) {
                    $originalUpdated = $this->updateOriginalFilenameAfterConflict($row, $filename);
                }
            } else {
                $this->summary['unexpected_failures']++;
                report($exception);
                $this->error("Image record {$row->id}: unexpected database failure; continuing.");
            }
        } catch (Throwable $exception) {
            $this->summary['unexpected_failures']++;
            report($exception);
            $this->error("Image record {$row->id}: unexpected database failure; continuing.");
        }

        if ($originalUpdated || $checksumUpdated) {
            $this->summary['rows_updated']++;
            $this->summary['original_filenames'] += (int) $originalUpdated;
            $this->summary['checksums'] += (int) $checksumUpdated;
            $this->line("Image record {$row->id}: populated ".$this->fieldDescription($originalUpdated, $checksumUpdated).'.');
        }
    }

    /** @param array{checksum?: string, original_filename?: string} $updates */
    private function updateNullMetadata(int $rowId, array $updates): bool
    {
        $query = DB::table((new HerbariumImages())->getTable())
            ->where('id', $rowId);

        foreach (array_keys($updates) as $column) {
            $query->whereNull($column);
        }

        return $query->update($updates) === 1;
    }

    private function updateOriginalFilenameAfterConflict(HerbariumImages $row, string $filename): bool
    {
        try {
            return $this->updateNullMetadata($row->id, ['original_filename' => $filename]);
        } catch (Throwable $exception) {
            $this->summary['unexpected_failures']++;
            report($exception);
            $this->error("Image record {$row->id}: original filename could not be updated; continuing.");

            return false;
        }
    }

    private function isSafeStoredFilename(string $filename): bool
    {
        return trim($filename) !== ''
            && ! str_contains($filename, "\0")
            && ! str_contains($filename, '/')
            && ! str_contains($filename, '\\')
            && ! in_array($filename, ['.', '..'], true)
            && basename($filename) === $filename;
    }

    private function hashStoredFile(FilesystemAdapter $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new \RuntimeException('The stored image stream could not be opened.');
        }

        try {
            $context = hash_init('sha256');
            $bytes = hash_update_stream($context, $stream);

            if (! is_int($bytes) || $bytes < 0) {
                throw new \RuntimeException('The stored image stream could not be hashed.');
            }

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function canOwnChecksum(HerbariumImages $row, string $checksum): bool
    {
        $key = $row->herbarium_id.':'.$checksum;
        $databaseOwner = $this->databaseChecksumOwner($row->herbarium_id, $checksum);

        if ($databaseOwner !== null && $databaseOwner !== $row->id) {
            $this->checksumOwners[$key] = $databaseOwner;
            $this->recordConflict($row->id, $databaseOwner);

            return false;
        }

        $owner = $this->checksumOwners[$key] ?? null;

        if ($owner === null) {
            $this->checksumOwners[$key] = $row->id;

            return true;
        }

        if ($owner !== $row->id) {
            $this->recordConflict($row->id, $owner);

            return false;
        }

        return true;
    }

    private function databaseChecksumOwner(int $herbariumId, string $checksum): ?int
    {
        $owner = HerbariumImages::query()
            ->where('herbarium_id', $herbariumId)
            ->where('checksum', $checksum)
            ->orderBy('id')
            ->value('id');

        return $owner === null ? null : (int) $owner;
    }

    private function recordConflict(int $rowId, int $ownerId): void
    {
        $this->summary['checksum_conflicts']++;
        $this->warn("Image record {$rowId}: checksum conflicts with owner image record {$ownerId}; checksum left null.");
    }

    private function isUniqueConstraintFailure(QueryException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1062
            || ($exception->errorInfo[0] ?? null) === '23000';
    }

    private function fieldDescription(bool $original, bool $checksum): string
    {
        return match (true) {
            $original && $checksum => 'original filename and checksum',
            $original => 'original filename',
            default => 'checksum',
        };
    }

    private function renderSummary(bool $apply): void
    {
        $verb = $apply ? 'updated' : 'would be updated';
        $fieldVerb = $apply ? 'populated' : 'would be populated';

        $this->newLine();
        $this->info($apply ? 'Backfill apply summary' : 'Backfill dry-run summary — NO DATA CHANGED');
        $this->table(['Result', 'Count'], [
            ['Rows scanned', $this->summary['rows_scanned']],
            ['Already complete', $this->summary['already_complete']],
            ["Rows {$verb}", $this->summary['rows_updated']],
            ["Original filenames {$fieldVerb}", $this->summary['original_filenames']],
            ["Checksums {$fieldVerb}", $this->summary['checksums']],
            ['Missing files', $this->summary['missing_files']],
            ['Unsafe filenames', $this->summary['unsafe_filenames']],
            ['Unreadable or hashing failures', $this->summary['hashing_failures']],
            ['Same-herbarium checksum conflicts', $this->summary['checksum_conflicts']],
            ['Unexpected failures', $this->summary['unexpected_failures']],
        ]);
    }
}
