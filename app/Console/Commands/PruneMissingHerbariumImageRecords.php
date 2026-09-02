<?php

namespace App\Console\Commands;

use App\Models\HerbariumImages;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Throwable;

class PruneMissingHerbariumImageRecords extends Command
{
    private const MANIFEST_FORMAT = 'dryherbarium.herbarium-missing-image-records';

    private const MANIFEST_VERSION = 1;

    private const DIRECTORY = 'herbarium';

    private const EXPECTED_PRODUCTION_MISSING = 1337;

    private const EXPECTED_PRODUCTION_PRESENT = 2653;

    private const EXPECTED_PRODUCTION_PHYSICAL_FILES = 2654;

    protected $signature = 'herbarium:prune-missing-image-records
        {--export= : Write a recovery manifest during dry run}
        {--apply : Delete rows from a reviewed manifest}
        {--manifest= : Reviewed manifest required in apply mode}
        {--manifest-sha256= : Reviewed manifest digest required in apply mode}
        {--expected-missing= : Required confirmed-missing count for export/apply}
        {--expected-present= : Required accessible referenced-file count for export/apply}
        {--expected-physical-files= : Required physical herbarium-file count for export/apply}';

    protected $description = 'Audit and, from a reviewed manifest, prune confirmed missing herbarium image records';

    public function handle(): int
    {
        try {
            return $this->executePrune();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $this->warn('NO DATA CHANGED.');

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The missing-image audit could not be completed safely. No data was changed.');

            return self::FAILURE;
        }
    }

    private function executePrune(): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && ! app()->isDownForMaintenance()) {
            throw new RuntimeException(
                'Apply mode requires a controlled maintenance window. Run php artisan down and pause other writers before retrying.',
            );
        }

        $exportPath = $this->stringOption('export');
        $manifestPath = $this->stringOption('manifest');
        $manifestDigest = $this->stringOption('manifest-sha256');

        if ($apply && $exportPath !== null) {
            throw new RuntimeException('--export cannot be combined with --apply.');
        }

        if (! $apply && ($manifestPath !== null || $manifestDigest !== null)) {
            throw new RuntimeException('--manifest and --manifest-sha256 are valid only with --apply.');
        }

        $requiresExpectedCounts = $apply || $exportPath !== null;
        $expected = $this->expectedCounts($requiresExpectedCounts);

        if ($apply && ($manifestPath === null || $manifestDigest === null)) {
            throw new RuntimeException('Apply mode requires --manifest and --manifest-sha256.');
        }

        $this->warn($apply
            ? 'APPLY MODE: only exact rows from the reviewed manifest may be deleted; image files are never changed.'
            : 'DRY RUN ONLY: no database data or image files will be changed.');

        $analysis = $this->analyzeCurrentState();
        $this->renderAnalysis($analysis);

        if ($analysis['inaccessible_checks'] > 0) {
            throw new RuntimeException('Storage contains inaccessible or indeterminate paths; refusing to continue.');
        }

        if ($expected !== null) {
            $this->assertExpectedCounts($analysis, $expected);
            $this->info('Supplied expected counts match the current storage and database state.');
        }

        $productionCountsMatch = $analysis['confirmed_missing'] === self::EXPECTED_PRODUCTION_MISSING
            && $analysis['accessible_records'] === self::EXPECTED_PRODUCTION_PRESENT
            && $analysis['physical_file_count'] === self::EXPECTED_PRODUCTION_PHYSICAL_FILES;
        $this->line('Reviewed production counts match: '.($productionCountsMatch ? 'yes' : 'no').'.');

        if (! $apply) {
            if ($exportPath !== null) {
                $manifest = $this->buildManifest($analysis);
                $digest = $this->writeManifest($exportPath, $manifest);
                $this->info('Recovery manifest written with mode 0600.');
                $this->line('Manifest SHA-256: '.$digest);
            }

            $this->info('DRY RUN COMPLETE — NO DATA CHANGED.');

            return self::SUCCESS;
        }

        if ($analysis['unsafe_filenames'] > 0 || $analysis['duplicate_database_filenames'] > 0) {
            throw new RuntimeException('Unsafe or duplicate database filenames make apply mode indeterminate.');
        }

        $manifest = $this->readAndValidateManifest($manifestPath, $manifestDigest, $expected);
        $this->assertManifestMatchesAnalysis($manifest, $analysis);

        if ($analysis['activity_references'] > 0 || $analysis['dependency_references'] > 0) {
            throw new RuntimeException('A candidate has an activity or dependent reference; refusing deletion.');
        }

        $finalAnalysis = $this->analyzeCurrentState();
        $this->assertExpectedCounts($finalAnalysis, $expected);
        $this->assertManifestMatchesAnalysis($manifest, $finalAnalysis);

        if ($finalAnalysis['inaccessible_checks'] > 0
            || $finalAnalysis['activity_references'] > 0
            || $finalAnalysis['dependency_references'] > 0) {
            throw new RuntimeException('The final storage or dependency recheck failed; refusing deletion.');
        }

        $deleted = $this->deleteManifestRows($manifest, $expected);
        $this->info("Deleted {$deleted} exact manifest records. Image files and activities were not changed.");

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("--{$name} must be a non-empty string.");
        }

        return trim($value);
    }

    /** @return array{missing: int, present: int, physical: int}|null */
    private function expectedCounts(bool $required): ?array
    {
        $options = [
            'missing' => 'expected-missing',
            'present' => 'expected-present',
            'physical' => 'expected-physical-files',
        ];
        $result = [];

        foreach ($options as $key => $option) {
            $value = $this->option($option);

            if ($value === null) {
                if ($required) {
                    throw new RuntimeException("--{$option} is required for export and apply modes.");
                }

                continue;
            }

            if ((! is_string($value) && ! is_int($value))
                || filter_var($value, FILTER_VALIDATE_INT) === false
                || (int) $value < 0) {
                throw new RuntimeException("--{$option} must be a non-negative integer.");
            }

            $result[$key] = (int) $value;
        }

        if ($result !== [] && count($result) !== count($options)) {
            throw new RuntimeException('Supply all three expected-count options together.');
        }

        return $result === [] ? null : $result;
    }

    /** @return array<string, mixed> */
    private function analyzeCurrentState(): array
    {
        $disk = Storage::disk('public');
        $physicalPaths = $this->healthyPhysicalFileListing($disk);
        $physicalSet = array_fill_keys($physicalPaths, true);
        $rows = DB::table($this->imageTable())
            ->orderBy('id')
            ->get();

        $candidates = [];
        $referencedPaths = [];
        $readablePaths = [];
        $accessibleRecords = 0;
        $completeRows = 0;
        $conflictRows = 0;
        $unsafe = 0;
        $inaccessible = 0;
        $filenameCounts = [];

        foreach ($rows as $row) {
            if ($row->original_filename !== null && $row->checksum !== null) {
                $completeRows++;
            }

            $filename = is_string($row->filename) ? $row->filename : '';

            if (! $this->isSafeStoredFilename($filename)) {
                $unsafe++;
                $this->warn("Image record {$row->id}: unsafe filename; not classified as missing.");

                continue;
            }

            $filenameCounts[$filename] = ($filenameCounts[$filename] ?? 0) + 1;
            $path = self::DIRECTORY.'/'.$filename;
            $referencedPaths[$path] = true;

            if (isset($physicalSet[$path])) {
                if (! array_key_exists($path, $readablePaths)) {
                    $readablePaths[$path] = $this->isReadableFile($disk, $path);
                }

                if (! $readablePaths[$path]) {
                    $inaccessible++;
                    $this->warn("Image record {$row->id}: referenced file is inaccessible; not classified as missing.");

                    continue;
                }

                $accessibleRecords++;

                if ($row->original_filename !== null && $row->checksum === null) {
                    $conflictRows++;
                }

                continue;
            }

            try {
                if ($disk->exists($path)) {
                    $inaccessible++;
                    $this->warn("Image record {$row->id}: storage listing and existence checks disagree; not classified as missing.");

                    continue;
                }
            } catch (Throwable) {
                $inaccessible++;
                $this->warn("Image record {$row->id}: file absence is indeterminate; not classified as missing.");

                continue;
            }

            if ($row->original_filename === null && $row->checksum === null) {
                $candidates[] = $this->snapshot($row);
            }
        }

        $unreferenced = [];

        foreach ($physicalPaths as $path) {
            if (! isset($referencedPaths[$path])) {
                try {
                    $unreferenced[] = $this->inspectUnreferencedFile($disk, $path);
                } catch (Throwable) {
                    $inaccessible++;
                    $this->warn('An unreferenced physical file could not be inspected safely.');
                }
            }
        }

        $candidateIds = array_column($candidates, 'id');
        $references = $this->dependencyReferences($candidateIds);
        $duplicateFilenameGroups = count(array_filter($filenameCounts, static fn (int $count): bool => $count > 1));

        return [
            'rows_scanned' => count($rows),
            'accessible_records' => $accessibleRecords,
            'confirmed_missing' => count($candidates),
            'complete_rows' => $completeRows,
            'checksum_conflict_rows' => $conflictRows,
            'unsafe_filenames' => $unsafe,
            'inaccessible_checks' => $inaccessible,
            'physical_file_count' => count($physicalPaths),
            'referenced_physical_files' => count(array_intersect_key($physicalSet, $referencedPaths)),
            'unreferenced_files' => $unreferenced,
            'duplicate_database_filenames' => $duplicateFilenameGroups,
            'activity_references' => $references['activity_count'],
            'dependency_references' => $references['dependency_count'],
            'dependency_details' => $references['details'],
            'candidates' => $candidates,
        ];
    }

    /** @return list<string> */
    private function healthyPhysicalFileListing(FilesystemAdapter $disk): array
    {
        try {
            if (! $disk->directoryExists(self::DIRECTORY)) {
                throw new RuntimeException('The public herbarium directory is unavailable.');
            }

            $directory = $disk->path(self::DIRECTORY);

            if (! is_dir($directory) || ! is_readable($directory) || scandir($directory) === false) {
                throw new RuntimeException('The public herbarium directory is not readable or listable.');
            }

            $files = $disk->allFiles(self::DIRECTORY);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RuntimeException('The public herbarium disk could not be inspected safely.');
        }

        if (! is_array($files) || $files === []) {
            throw new RuntimeException('The public herbarium directory is empty; refusing to classify records as missing.');
        }

        $files = array_values(array_unique(array_map('strval', $files)));
        sort($files, SORT_STRING);

        return $files;
    }

    private function isReadableFile(FilesystemAdapter $disk, string $path): bool
    {
        try {
            $stream = $disk->readStream($path);

            if (! is_resource($stream)) {
                return false;
            }

            try {
                return fread($stream, 1) !== false;
            } finally {
                fclose($stream);
            }
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function inspectUnreferencedFile(FilesystemAdapter $disk, string $path): array
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException('The unreferenced file is unreadable.');
        }

        try {
            $context = hash_init('sha256');
            $bytes = hash_update_stream($context, $stream);

            if (! is_int($bytes) || $bytes < 0) {
                throw new RuntimeException('The unreferenced file could not be hashed.');
            }

            $checksum = hash_final($context);
        } finally {
            fclose($stream);
        }

        $relative = str_starts_with($path, self::DIRECTORY.'/')
            ? substr($path, strlen(self::DIRECTORY) + 1)
            : $path;
        $modified = $disk->lastModified($path);

        return [
            'relative_filename' => $relative,
            'size' => $disk->size($path),
            'checksum' => $checksum,
            'modified_at' => is_int($modified) ? gmdate('c', $modified) : null,
            'resembles_collision_safe_name' => preg_match(
                '/-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(?:jpe?g|png)$/i',
                $relative,
            ) === 1,
            'checksum_matches_database' => DB::table($this->imageTable())
                ->where('checksum', $checksum)
                ->exists(),
            'appears_in_activity_stored_filename' => $this->activityContainsStoredFilename($relative),
            'other_database_reference' => $this->otherFilenameReferenceExists($relative),
            'configured_public_path_exists' => $this->configuredPublicPathExists($path),
        ];
    }

    private function configuredPublicPathExists(string $storagePath): bool
    {
        $storageRoot = realpath(Storage::disk('public')->path(''));

        if ($storageRoot === false) {
            return false;
        }

        foreach ((array) config('filesystems.links', []) as $publicPath => $targetPath) {
            if (! is_string($publicPath) || ! is_string($targetPath)) {
                continue;
            }

            $target = realpath($targetPath);

            if ($target === $storageRoot && is_file($publicPath.DIRECTORY_SEPARATOR.$storagePath)) {
                return true;
            }

            if ($target === realpath($storageRoot.DIRECTORY_SEPARATOR.self::DIRECTORY)
                && str_starts_with($storagePath, self::DIRECTORY.'/')
                && is_file($publicPath.DIRECTORY_SEPARATOR.substr($storagePath, strlen(self::DIRECTORY) + 1))) {
                return true;
            }
        }

        return false;
    }

    private function activityContainsStoredFilename(string $filename): bool
    {
        $table = (string) config('activitylog.table_name', 'activity_log');
        $connection = config('activitylog.database_connection');

        return DB::connection(is_string($connection) && $connection !== '' ? $connection : null)
            ->table($table)
            ->get(['properties'])
            ->contains(function (object $activity) use ($filename): bool {
                $properties = is_string($activity->properties)
                    ? json_decode($activity->properties, true)
                    : null;

                return is_array($properties)
                    && ($properties['stored_filename'] ?? null) === $filename;
            });
    }

    private function otherFilenameReferenceExists(string $filename): bool
    {
        foreach (Schema::getTables() as $tableInfo) {
            $table = (string) ($tableInfo['name'] ?? '');

            if ($table === '' || in_array($table, [$this->imageTable(), (string) config('activitylog.table_name', 'activity_log')], true)) {
                continue;
            }

            foreach (Schema::getColumns($table) as $columnInfo) {
                $column = (string) ($columnInfo['name'] ?? '');

                if (! in_array($column, ['filename', 'stored_filename'], true)) {
                    continue;
                }

                if (DB::table($table)->where($column, $filename)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<int> $candidateIds
     *  @return array{activity_count: int, dependency_count: int, details: list<array<string, mixed>>}
     */
    private function dependencyReferences(array $candidateIds): array
    {
        if ($candidateIds === []) {
            return ['activity_count' => 0, 'dependency_count' => 0, 'details' => []];
        }

        $activityTable = (string) config('activitylog.table_name', 'activity_log');
        $activityConnection = config('activitylog.database_connection');
        $activityCount = $this->countWhereIn(
            DB::connection(is_string($activityConnection) && $activityConnection !== '' ? $activityConnection : null),
            $activityTable,
            'subject_id',
            $candidateIds,
            static fn ($query) => $query->where('subject_type', HerbariumImages::class),
        );

        $dependencyCount = 0;
        $details = [];
        $inspectedColumns = [];

        foreach (Schema::getTables() as $tableInfo) {
            $table = (string) ($tableInfo['name'] ?? '');

            if ($table === '' || in_array($table, [$this->imageTable(), $activityTable], true)) {
                continue;
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) !== $this->imageTable()) {
                    continue;
                }

                $columns = $foreignKey['columns'] ?? [];
                $foreignColumns = $foreignKey['foreign_columns'] ?? [];

                foreach ($foreignColumns as $index => $foreignColumn) {
                    if ($foreignColumn !== 'id' || ! isset($columns[$index])) {
                        continue;
                    }

                    $column = (string) $columns[$index];
                    $key = $table.'.'.$column;
                    $count = $this->countWhereIn(DB::connection(), $table, $column, $candidateIds);
                    $inspectedColumns[$key] = true;

                    if ($count > 0) {
                        $dependencyCount += $count;
                        $details[] = ['table' => $table, 'column' => $column, 'count' => $count, 'kind' => 'foreign_key'];
                    }
                }
            }

            foreach (Schema::getColumns($table) as $columnInfo) {
                $column = (string) ($columnInfo['name'] ?? '');
                $key = $table.'.'.$column;

                if (! in_array($column, ['herbarium_image_id', 'herbarium_images_id'], true)
                    || isset($inspectedColumns[$key])) {
                    continue;
                }

                $count = $this->countWhereIn(DB::connection(), $table, $column, $candidateIds);

                if ($count > 0) {
                    $dependencyCount += $count;
                    $details[] = ['table' => $table, 'column' => $column, 'count' => $count, 'kind' => 'conventional_column'];
                }
            }
        }

        return [
            'activity_count' => $activityCount,
            'dependency_count' => $dependencyCount,
            'details' => $details,
        ];
    }

    private function countWhereIn(
        ConnectionInterface $connection,
        string $table,
        string $column,
        array $ids,
        ?callable $scope = null,
    ): int {
        $count = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            $query = $connection->table($table)->whereIn($column, $chunk);

            if ($scope !== null) {
                $scope($query);
            }

            $count += $query->count();
        }

        return $count;
    }

    /** @return array<string, int|string|null> */
    private function snapshot(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'herbarium_id' => (int) $row->herbarium_id,
            'genus_id' => (int) $row->genus_id,
            'filename' => (string) $row->filename,
            'original_filename' => $row->original_filename === null ? null : (string) $row->original_filename,
            'checksum' => $row->checksum === null ? null : (string) $row->checksum,
            'created_at' => $row->created_at === null ? null : (string) $row->created_at,
            'updated_at' => $row->updated_at === null ? null : (string) $row->updated_at,
        ];
    }

    /** @param array<string, mixed> $analysis */
    private function renderAnalysis(array $analysis): void
    {
        $this->table(['Result', 'Count'], [
            ['Database rows scanned', $analysis['rows_scanned']],
            ['File-backed accessible records', $analysis['accessible_records']],
            ['Confirmed missing candidates', $analysis['confirmed_missing']],
            ['Complete metadata rows', $analysis['complete_rows']],
            ['Checksum-conflict rows', $analysis['checksum_conflict_rows']],
            ['Unsafe filenames', $analysis['unsafe_filenames']],
            ['Inaccessible/indeterminate checks', $analysis['inaccessible_checks']],
            ['Physical files', $analysis['physical_file_count']],
            ['Referenced physical files', $analysis['referenced_physical_files']],
            ['Unreferenced physical files', count($analysis['unreferenced_files'])],
            ['Duplicate database filenames', $analysis['duplicate_database_filenames']],
            ['Candidate activity references', $analysis['activity_references']],
            ['Candidate foreign/dependent references', $analysis['dependency_references']],
        ]);

        foreach ($analysis['dependency_details'] as $detail) {
            $this->warn("Dependency: {$detail['table']}.{$detail['column']} ({$detail['kind']}) references {$detail['count']} candidate row(s).");
        }

        foreach ($analysis['unreferenced_files'] as $file) {
            $name = json_encode($file['relative_filename'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $this->warn("Unreferenced physical file {$name}:");
            $this->line('  Size: '.$file['size'].' bytes');
            $this->line('  SHA-256: '.$file['checksum']);
            $this->line('  Modified: '.($file['modified_at'] ?? 'unavailable'));
            $this->line('  Collision-safe format: '.($file['resembles_collision_safe_name'] ? 'yes' : 'no'));
            $this->line('  Checksum matches database: '.($file['checksum_matches_database'] ? 'yes' : 'no'));
            $this->line('  Activity stored_filename reference: '.($file['appears_in_activity_stored_filename'] ? 'yes' : 'no'));
            $this->line('  Other database filename reference: '.($file['other_database_reference'] ? 'yes' : 'no'));
            $this->line('  Configured public application path exists: '.($file['configured_public_path_exists'] ? 'yes' : 'no'));
        }
    }

    /** @param array<string, mixed> $analysis
     *  @param array{missing: int, present: int, physical: int} $expected
     */
    private function assertExpectedCounts(array $analysis, array $expected): void
    {
        if ($analysis['confirmed_missing'] !== $expected['missing']
            || $analysis['accessible_records'] !== $expected['present']
            || $analysis['physical_file_count'] !== $expected['physical']) {
            throw new RuntimeException('The supplied expected counts do not match the current database and storage state.');
        }
    }

    /** @param array<string, mixed> $analysis
     *  @return array<string, mixed>
     */
    private function buildManifest(array $analysis): array
    {
        return [
            'format' => self::MANIFEST_FORMAT,
            'version' => self::MANIFEST_VERSION,
            'generated_at' => now()->toIso8601String(),
            'timezone' => (string) config('app.timezone'),
            'application' => [
                'name' => (string) config('app.name'),
                'environment' => app()->environment(),
            ],
            'counts' => [
                'total_image_rows' => $analysis['rows_scanned'],
                'confirmed_missing' => $analysis['confirmed_missing'],
                'accessible_referenced_records' => $analysis['accessible_records'],
                'physical_files' => $analysis['physical_file_count'],
                'activity_references' => $analysis['activity_references'],
                'dependency_references' => $analysis['dependency_references'],
            ],
            'unreferenced_files' => $analysis['unreferenced_files'],
            'candidates' => $analysis['candidates'],
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(string $path, array $manifest): string
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('--export must be an absolute path outside the public web root.');
        }

        $directory = realpath(dirname($path));
        $public = realpath(public_path());

        if ($directory === false || ! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('The manifest parent directory does not exist or is not writable.');
        }

        if ($public !== false && ($directory === $public || str_starts_with($directory, $public.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('The recovery manifest must be stored outside the public web root.');
        }

        $target = $directory.DIRECTORY_SEPARATOR.basename($path);

        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('The recovery manifest already exists and will not be overwritten.');
        }

        try {
            $json = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )."\n";
        } catch (JsonException) {
            throw new RuntimeException('The recovery manifest could not be encoded safely.');
        }

        $temporary = $directory.DIRECTORY_SEPARATOR.'.'.basename($path).'.'.bin2hex(random_bytes(8)).'.incomplete';
        $handle = null;

        try {
            $handle = fopen($temporary, 'xb');

            if (! is_resource($handle) || ! chmod($temporary, 0600)) {
                throw new RuntimeException('The incomplete recovery manifest could not be created safely.');
            }

            $written = 0;
            $length = strlen($json);

            while ($written < $length) {
                $bytes = fwrite($handle, substr($json, $written));

                if (! is_int($bytes) || $bytes < 1) {
                    throw new RuntimeException('The recovery manifest could not be written completely.');
                }

                $written += $bytes;
            }

            if (! fflush($handle)) {
                throw new RuntimeException('The recovery manifest could not be flushed safely.');
            }

            fclose($handle);
            $handle = null;

            $digest = hash('sha256', $json);
            $this->publishManifest($temporary, $target, $length, $digest);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (file_exists($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
        }

        return $digest;
    }

    /**
     * Publish without a check-then-rename race. A same-filesystem hard link is
     * atomic and link() refuses to replace any existing directory entry.
     */
    protected function publishManifest(string $temporary, string $target, int $expectedSize, string $expectedDigest): void
    {
        $temporaryStat = lstat($temporary);

        if (! is_array($temporaryStat)
            || ($temporaryStat['mode'] & 0170000) !== 0100000
            || ($temporaryStat['mode'] & 0777) !== 0600
            || $temporaryStat['size'] !== $expectedSize) {
            throw new RuntimeException('The completed recovery manifest is not safe to publish.');
        }

        if (! @link($temporary, $target)) {
            throw new RuntimeException('The recovery manifest destination already exists or cannot be published safely.');
        }

        $publishedStat = lstat($target);
        $publishedIsTemporaryInode = is_array($publishedStat)
            && $publishedStat['dev'] === $temporaryStat['dev']
            && $publishedStat['ino'] === $temporaryStat['ino'];
        $publishedHandle = null;

        try {
            if (! $publishedIsTemporaryInode
                || is_link($target)
                || ($publishedStat['mode'] & 0170000) !== 0100000
                || ($publishedStat['mode'] & 0777) !== 0600
                || $publishedStat['size'] !== $expectedSize) {
                throw new RuntimeException('The recovery manifest publication could not be verified safely.');
            }

            $publishedHandle = @fopen($target, 'rb');
            $openedStat = is_resource($publishedHandle) ? fstat($publishedHandle) : false;

            if (! is_resource($publishedHandle)
                || ! is_array($openedStat)
                || $openedStat['dev'] !== $temporaryStat['dev']
                || $openedStat['ino'] !== $temporaryStat['ino']) {
                throw new RuntimeException('The recovery manifest publication could not be verified safely.');
            }

            $context = hash_init('sha256');
            $bytes = hash_update_stream($context, $publishedHandle);
            $actualDigest = hash_final($context);
            $finalStat = lstat($target);

            if ($bytes !== $expectedSize
                || ! hash_equals($expectedDigest, $actualDigest)
                || ! is_array($finalStat)
                || $finalStat['dev'] !== $temporaryStat['dev']
                || $finalStat['ino'] !== $temporaryStat['ino']
                || ($finalStat['mode'] & 0170000) !== 0100000
                || ($finalStat['mode'] & 0777) !== 0600
                || $finalStat['size'] !== $expectedSize) {
                throw new RuntimeException('The recovery manifest publication could not be verified safely.');
            }
        } catch (Throwable $exception) {
            // Remove only the directory entry proven to be our hard-linked inode.
            $currentStat = lstat($target);

            if (is_array($currentStat)
                && $currentStat['dev'] === $temporaryStat['dev']
                && $currentStat['ino'] === $temporaryStat['ino']) {
                @unlink($target);
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('The recovery manifest publication could not be verified safely.');
        } finally {
            if (is_resource($publishedHandle)) {
                fclose($publishedHandle);
            }
        }
    }

    /** @param array{missing: int, present: int, physical: int} $expected
     *  @return array<string, mixed>
     */
    private function readAndValidateManifest(string $path, string $suppliedDigest, array $expected): array
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/i', $suppliedDigest)) {
            throw new RuntimeException('--manifest-sha256 must be a full SHA-256 digest.');
        }

        if (is_link($path)) {
            throw new RuntimeException('The reviewed manifest must be a restrictive regular file, not a symbolic link.');
        }

        $pathStat = lstat($path);
        $realPath = realpath($path);
        $public = realpath(public_path());

        if (! is_array($pathStat)
            || ($pathStat['mode'] & 0170000) !== 0100000
            || $realPath === false
            || ! is_file($realPath)
            || ! is_readable($realPath)) {
            throw new RuntimeException('The reviewed manifest is missing or unreadable.');
        }

        if (($pathStat['mode'] & 0077) !== 0) {
            throw new RuntimeException('The reviewed manifest has group- or world-accessible permissions.');
        }

        if ($public !== false && ($realPath === $public || str_starts_with($realPath, $public.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('The reviewed manifest must be stored outside the public web root.');
        }

        $handle = @fopen($realPath, 'rb');

        if (! is_resource($handle)) {
            throw new RuntimeException('The reviewed manifest is missing or unreadable.');
        }

        try {
            $openedStat = fstat($handle);
            $maximumSize = 20 * 1024 * 1024;

            if (! is_array($openedStat)
                || $openedStat['dev'] !== $pathStat['dev']
                || $openedStat['ino'] !== $pathStat['ino']
                || ($openedStat['mode'] & 0170000) !== 0100000
                || ($openedStat['mode'] & 0077) !== 0
                || $openedStat['size'] < 1
                || $openedStat['size'] > $maximumSize) {
                throw new RuntimeException('The reviewed manifest file or size is invalid.');
            }

            $contents = stream_get_contents($handle, $maximumSize + 1);

            if (! is_string($contents) || strlen($contents) !== $openedStat['size']) {
                throw new RuntimeException('The reviewed manifest could not be read consistently.');
            }
        } finally {
            fclose($handle);
        }

        if (! is_string($contents) || ! hash_equals(strtolower($suppliedDigest), hash('sha256', $contents))) {
            throw new RuntimeException('The reviewed manifest SHA-256 digest does not match.');
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The reviewed manifest is not valid JSON.');
        }

        $topLevelKeys = ['format', 'version', 'generated_at', 'timezone', 'application', 'counts', 'unreferenced_files', 'candidates'];
        $countKeys = [
            'total_image_rows',
            'confirmed_missing',
            'accessible_referenced_records',
            'physical_files',
            'activity_references',
            'dependency_references',
        ];

        if (! is_array($manifest)
            || array_keys($manifest) !== $topLevelKeys
            || ($manifest['format'] ?? null) !== self::MANIFEST_FORMAT
            || ($manifest['version'] ?? null) !== self::MANIFEST_VERSION
            || ! is_string($manifest['generated_at'] ?? null)
            || ! is_string($manifest['timezone'] ?? null)
            || ! is_array($manifest['application'] ?? null)
            || array_keys($manifest['application']) !== ['name', 'environment']
            || ($manifest['application']['name'] ?? null) !== (string) config('app.name')
            || ($manifest['application']['environment'] ?? null) !== app()->environment()
            || ! is_array($manifest['counts'] ?? null)
            || array_keys($manifest['counts']) !== $countKeys
            || count(array_filter($manifest['counts'], static fn ($count): bool => ! is_int($count) || $count < 0)) > 0
            || ! is_array($manifest['unreferenced_files'] ?? null)
            || ! is_array($manifest['candidates'] ?? null)) {
            throw new RuntimeException('The reviewed manifest format, version, or environment is invalid.');
        }

        $counts = $manifest['counts'];

        if (($counts['confirmed_missing'] ?? null) !== $expected['missing']
            || ($counts['accessible_referenced_records'] ?? null) !== $expected['present']
            || ($counts['physical_files'] ?? null) !== $expected['physical']) {
            throw new RuntimeException('The reviewed manifest counts do not match the supplied expected counts.');
        }

        $normalized = [];
        $ids = [];
        $requiredKeys = [
            'id',
            'herbarium_id',
            'genus_id',
            'filename',
            'original_filename',
            'checksum',
            'created_at',
            'updated_at',
        ];

        foreach ($manifest['candidates'] as $candidate) {
            if (! is_array($candidate) || array_keys($candidate) !== $requiredKeys
                || ! is_int($candidate['id'] ?? null)
                || ! is_int($candidate['herbarium_id'] ?? null)
                || ! is_int($candidate['genus_id'] ?? null)
                || ! is_string($candidate['filename'] ?? null)
                || ! array_key_exists('original_filename', $candidate)
                || $candidate['original_filename'] !== null
                || ! array_key_exists('checksum', $candidate)
                || $candidate['checksum'] !== null
                || (! is_null($candidate['created_at']) && ! is_string($candidate['created_at']))
                || (! is_null($candidate['updated_at']) && ! is_string($candidate['updated_at']))) {
                throw new RuntimeException('The reviewed manifest contains an invalid candidate snapshot.');
            }

            if (isset($ids[$candidate['id']])) {
                throw new RuntimeException('The reviewed manifest contains duplicate candidate IDs.');
            }

            $ids[$candidate['id']] = true;
            $normalized[] = $candidate;
        }

        if (count($normalized) !== $expected['missing']) {
            throw new RuntimeException('The reviewed manifest candidate count is invalid.');
        }

        $orderedIds = array_column($normalized, 'id');
        $sortedIds = $orderedIds;
        sort($sortedIds, SORT_NUMERIC);

        if ($orderedIds !== $sortedIds) {
            throw new RuntimeException('The reviewed manifest candidates are not in deterministic ID order.');
        }

        $manifest['candidates'] = $normalized;

        return $manifest;
    }

    /** @param array<string, mixed> $manifest
     *  @param array<string, mixed> $analysis
     */
    private function assertManifestMatchesAnalysis(array $manifest, array $analysis): void
    {
        if (($manifest['counts']['total_image_rows'] ?? null) !== $analysis['rows_scanned']
            || ($manifest['counts']['activity_references'] ?? null) !== $analysis['activity_references']
            || ($manifest['counts']['dependency_references'] ?? null) !== $analysis['dependency_references']
            || $manifest['unreferenced_files'] !== $analysis['unreferenced_files']
            || $manifest['candidates'] !== $analysis['candidates']) {
            throw new RuntimeException('The reviewed manifest no longer matches the current candidate rows.');
        }

        $expectedUnreferenced = $analysis['physical_file_count'] - $analysis['referenced_physical_files'];

        if (count($analysis['unreferenced_files']) !== $expectedUnreferenced) {
            throw new RuntimeException('The unreferenced physical-file count is inconsistent with the reviewed state.');
        }
    }

    /** @param array<string, mixed> $manifest
     *  @param array{missing: int, present: int, physical: int} $expected
     */
    private function deleteManifestRows(array $manifest, array $expected): int
    {
        $ids = array_column($manifest['candidates'], 'id');
        $disk = Storage::disk('public');

        return DB::transaction(function () use ($ids, $manifest, $expected, $disk): int {
            $locked = DB::table($this->imageTable())
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($locked->count() !== $expected['missing']) {
                throw new RuntimeException('A manifest row disappeared before deletion; transaction rolled back.');
            }

            $snapshots = $locked->map(fn (object $row): array => $this->snapshot($row))->all();

            if ($snapshots !== $manifest['candidates']) {
                throw new RuntimeException('A manifest row changed before deletion; transaction rolled back.');
            }

            foreach ($locked as $row) {
                if ($row->original_filename !== null || $row->checksum !== null
                    || $disk->exists(self::DIRECTORY.'/'.(string) $row->filename)) {
                    throw new RuntimeException('A manifest row gained metadata or a recovered file; transaction rolled back.');
                }
            }

            $references = $this->dependencyReferences($ids);

            if ($references['activity_count'] > 0 || $references['dependency_count'] > 0) {
                throw new RuntimeException('A candidate gained an activity or dependent reference; transaction rolled back.');
            }

            $deleted = $this->deleteRows($ids);

            if ($deleted !== $expected['missing']) {
                throw new RuntimeException('The exact manifest deletion count failed; transaction rolled back.');
            }

            if (DB::table($this->imageTable())->count() !== $expected['present']) {
                throw new RuntimeException('The expected surviving image-row count failed; transaction rolled back.');
            }

            if (count($this->healthyPhysicalFileListing($disk)) !== $expected['physical']) {
                throw new RuntimeException('The reviewed physical-file count changed; transaction rolled back.');
            }

            return $deleted;
        }, 1);
    }

    /** @param list<int> $ids */
    protected function deleteRows(array $ids): int
    {
        return DB::table($this->imageTable())->whereIn('id', $ids)->delete();
    }

    private function imageTable(): string
    {
        return (new HerbariumImages())->getTable();
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
}
