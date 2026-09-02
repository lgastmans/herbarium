<?php

namespace Tests\Feature;

use App\Console\Commands\PruneMissingHerbariumImageRecords;
use App\Models\Family;
use App\Models\Genus;
use App\Models\Herbarium;
use App\Models\HerbariumImages;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class PruneMissingHerbariumImageRecordsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryManifests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->restoreApplicationOnline();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->restoreApplicationOnline();

        foreach ($this->temporaryManifests as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_online_audit_and_export_succeed_but_apply_requires_maintenance_without_side_effects(): void
    {
        $state = $this->basicReviewedState('P13000');
        $path = $this->manifestPath();
        $expected = $this->expectedOptions(1, 1, 2);
        $rowsBefore = DB::table('herbarium_images')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        $timestampsBefore = $this->timestampsById();
        $filesBefore = $this->fileState();
        $activitiesBefore = Activity::count();

        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertSame(0, Artisan::call('herbarium:prune-missing-image-records'));
        $this->assertSame(0, Artisan::call('herbarium:prune-missing-image-records', [
            '--export' => $path,
            ...$expected,
        ]));
        $digest = hash_file('sha256', $path);
        $apply = $this->applyOptions($path, $digest, 1, 1, 2);

        $this->artisan('herbarium:prune-missing-image-records', $apply)
            ->expectsOutputToContain('requires a controlled maintenance window. Run php artisan down')
            ->expectsOutputToContain('NO DATA CHANGED')
            ->assertFailed();
        $this->assertSame($rowsBefore, DB::table('herbarium_images')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
        $this->assertSame($timestampsBefore, $this->timestampsById());
        $this->assertSame($filesBefore, $this->fileState());
        $this->assertSame($activitiesBefore, Activity::count());
        $this->assertDatabaseHas('herbarium_images', ['id' => $state['candidate']->id]);

        $this->enterMaintenanceMode();
        $this->assertSame(0, Artisan::call('herbarium:prune-missing-image-records', $apply), Artisan::output());
        $this->assertDatabaseMissing('herbarium_images', ['id' => $state['candidate']->id]);
    }

    public function test_maintenance_cleanup_restores_online_mode_and_removes_all_markers(): void
    {
        $this->enterMaintenanceMode();
        $this->assertFileExists(storage_path('framework/down'));
        $this->assertFileExists(storage_path('framework/maintenance.php'));

        $this->restoreApplicationOnline();

        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertFileDoesNotExist(storage_path('framework/down'));
        $this->assertFileDoesNotExist(storage_path('framework/maintenance.php'));
    }

    public function test_default_dry_run_classifies_only_confirmed_missing_null_metadata_rows_without_writes(): void
    {
        $herbarium = $this->herbarium('P13001');
        $candidate = $this->image($herbarium, 'missing.jpg');
        $conflict = $this->image($herbarium, 'conflict.jpg', 'conflict.jpg');
        $recovered = $this->image($herbarium, 'recovered.jpg');
        $complete = $this->image($herbarium, 'complete.jpg', 'complete.jpg', str_repeat('a', 64));
        $unsafe = $this->image($herbarium, '../unsafe.jpg');
        $timestamps = $this->timestampsById();
        Storage::disk('public')->put('herbarium/conflict.jpg', 'conflict bytes');
        Storage::disk('public')->put('herbarium/recovered.jpg', 'recovered bytes');
        Storage::disk('public')->put('herbarium/complete.jpg', 'complete bytes');
        Storage::disk('public')->put('herbarium/unreferenced.bin', 'orphan bytes');
        $files = $this->fileState();

        $this->artisan('herbarium:prune-missing-image-records')
            ->expectsOutputToContain('Confirmed missing candidates')
            ->expectsOutputToContain('Checksum-conflict rows')
            ->expectsOutputToContain('Unsafe filenames')
            ->expectsOutputToContain('DRY RUN COMPLETE — NO DATA CHANGED')
            ->assertSuccessful();
        $this->assertDatabaseHas('herbarium_images', ['id' => $candidate->id]);
        $this->assertDatabaseHas('herbarium_images', ['id' => $conflict->id, 'original_filename' => 'conflict.jpg']);
        $this->assertDatabaseHas('herbarium_images', ['id' => $recovered->id, 'original_filename' => null]);
        $this->assertDatabaseHas('herbarium_images', ['id' => $complete->id, 'checksum' => str_repeat('a', 64)]);
        $this->assertDatabaseHas('herbarium_images', ['id' => $unsafe->id]);
        $this->assertSame($timestamps, $this->timestampsById());
        $this->assertSame($files, $this->fileState());
        $this->assertSame(0, Activity::count());
    }

    public function test_empty_or_unavailable_storage_aborts_instead_of_classifying_rows_as_missing(): void
    {
        $candidate = $this->image($this->herbarium('P13002'), 'missing.jpg');

        $this->assertSame(1, Artisan::call('herbarium:prune-missing-image-records'));
        $this->assertStringContainsString('unavailable', Artisan::output());
        $this->assertDatabaseHas('herbarium_images', ['id' => $candidate->id]);

        $disk = $this->mock(FilesystemAdapter::class);
        $disk->shouldReceive('directoryExists')->once()->with('herbarium')->andReturnFalse();
        Storage::shouldReceive('disk')->once()->with('public')->andReturn($disk);

        $this->assertSame(1, Artisan::call('herbarium:prune-missing-image-records'));
        $this->assertStringContainsString('unavailable', Artisan::output());
    }

    public function test_inaccessible_referenced_file_is_indeterminate_and_never_a_candidate(): void
    {
        $row = $this->image($this->herbarium('P13003'), 'inaccessible.jpg');
        $directory = sys_get_temp_dir().'/herbarium-prune-disk-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/sentinel', 'sentinel');

        try {
            $disk = $this->mock(FilesystemAdapter::class);
            $disk->shouldReceive('directoryExists')->once()->with('herbarium')->andReturnTrue();
            $disk->shouldReceive('path')->once()->with('herbarium')->andReturn($directory);
            $disk->shouldReceive('allFiles')->once()->with('herbarium')->andReturn(['herbarium/inaccessible.jpg']);
            $disk->shouldReceive('readStream')->once()->with('herbarium/inaccessible.jpg')->andReturnFalse();
            Storage::shouldReceive('disk')->once()->with('public')->andReturn($disk);

            $this->assertSame(1, Artisan::call('herbarium:prune-missing-image-records'));
            $this->assertStringContainsString('inaccessible or indeterminate', Artisan::output());
            $this->assertDatabaseHas('herbarium_images', ['id' => $row->id]);
        } finally {
            unlink($directory.'/sentinel');
            rmdir($directory);
        }
    }

    public function test_export_requires_matching_counts_and_writes_complete_deterministic_manifest_atomically(): void
    {
        Carbon::setTestNow('2026-09-02 12:34:56');
        $herbarium = $this->herbarium('P13004');
        $first = $this->image($herbarium, 'missing-first.jpg');
        $second = $this->image($herbarium, 'missing-second.jpg');
        $survivor = $this->image($herbarium, 'present.jpg', 'present-original.jpg', str_repeat('b', 64));
        $this->setTimestamps($first, '2010-01-02 03:04:05', '2011-02-03 04:05:06');
        $this->setTimestamps($second, '2012-03-04 05:06:07', '2013-04-05 06:07:08');
        Storage::disk('public')->put('herbarium/present.jpg', 'present bytes');
        Storage::disk('public')->put('herbarium/unreferenced.jpg', 'unreferenced bytes');

        $mismatch = $this->manifestPath();
        $this->assertSame(1, Artisan::call('herbarium:prune-missing-image-records', [
            '--export' => $mismatch,
            ...$this->expectedOptions(3, 1, 2),
        ]));
        $this->assertFileDoesNotExist($mismatch);

        $firstPath = $this->manifestPath();
        $secondPath = $this->manifestPath();
        $options = $this->expectedOptions(2, 1, 2);
        $this->assertSame(0, Artisan::call('herbarium:prune-missing-image-records', ['--export' => $firstPath, ...$options]));
        $firstOutput = Artisan::output();
        $this->assertSame(0, Artisan::call('herbarium:prune-missing-image-records', ['--export' => $secondPath, ...$options]));

        $firstContents = file_get_contents($firstPath);
        $secondContents = file_get_contents($secondPath);
        $this->assertIsString($firstContents);
        $this->assertSame($firstContents, $secondContents);
        $this->assertSame(sprintf('%04o', fileperms($firstPath) & 0777), '0600');
        $this->assertStringContainsString(hash('sha256', $firstContents), $firstOutput);
        $this->assertSame([], glob(dirname($firstPath).'/.'.basename($firstPath).'.*.incomplete') ?: []);

        $manifest = json_decode($firstContents, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('dryherbarium.herbarium-missing-image-records', $manifest['format']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame(['name', 'environment'], array_keys($manifest['application']));
        $this->assertSame([$first->id, $second->id], array_column($manifest['candidates'], 'id'));
        $this->assertSame([
            'id', 'herbarium_id', 'genus_id', 'filename', 'original_filename', 'checksum', 'created_at', 'updated_at',
        ], array_keys($manifest['candidates'][0]));
        $this->assertSame('2010-01-02 03:04:05', $manifest['candidates'][0]['created_at']);
        $this->assertSame('2011-02-03 04:05:06', $manifest['candidates'][0]['updated_at']);
        $this->assertSame(3, $manifest['counts']['total_image_rows']);
        $this->assertSame(2, $manifest['counts']['confirmed_missing']);
        $this->assertSame($survivor->id, HerbariumImages::where('filename', 'present.jpg')->value('id'));
        $this->assertCount(1, $manifest['unreferenced_files']);
    }

    public function test_existing_manifest_is_never_overwritten(): void
    {
        $this->basicReviewedState();
        $path = $this->manifestPath();
        $options = ['--export' => $path, ...$this->expectedOptions(1, 1, 2)];
        $this->assertSame(0, Artisan::call('herbarium:prune-missing-image-records', $options));
        $contents = file_get_contents($path);

        $this->assertSame(1, Artisan::call('herbarium:prune-missing-image-records', $options));
        $this->assertStringContainsString('already exists', Artisan::output());
        $this->assertSame($contents, file_get_contents($path));
    }

    public function test_destination_created_during_publication_is_never_overwritten_and_incomplete_file_is_removed(): void
    {
        $state = $this->basicReviewedState();
        $path = $this->manifestPath();
        $competingContents = 'competing destination contents';
        $command = new class($competingContents) extends PruneMissingHerbariumImageRecords
        {
            public function __construct(private readonly string $competingContents)
            {
                parent::__construct();
            }

            protected function publishManifest(
                string $temporary,
                string $target,
                int $expectedSize,
                string $expectedDigest,
            ): void {
                file_put_contents($target, $this->competingContents);
                chmod($target, 0600);

                parent::publishManifest($temporary, $target, $expectedSize, $expectedDigest);
            }
        };
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        $this->assertSame(1, $tester->execute([
            '--export' => $path,
            ...$this->expectedOptions(1, 1, 2),
        ]));
        $this->assertSame($competingContents, file_get_contents($path));
        $this->assertSame([], glob(dirname($path).'/.'.basename($path).'.*.incomplete') ?: []);
        $this->assertDatabaseHas('herbarium_images', ['id' => $state['candidate']->id]);
    }

    public function test_apply_rejects_symlink_and_accessible_permissions_but_accepts_generated_manifest(): void
    {
        $this->enterMaintenanceMode();
        ['candidate' => $candidate, 'manifest' => $path, 'digest' => $digest] = $this->exportBasicManifest();
        $options = $this->applyOptions($path, $digest, 1, 1, 2);
        $link = $this->manifestPath();
        symlink($path, $link);

        $this->assertSame(1, Artisan::call('herbarium:prune-missing-image-records', [
            ...$options,
            '--manifest' => $link,
        ]));
        $this->assertStringContainsString('symbolic link', Artisan::output());

        chmod($path, 0640);
        clearstatcache(true, $path);
        $this->assertSame(1, Artisan::call('herbarium:prune-missing-image-records', $options));
        $this->assertStringContainsString('group- or world-accessible', Artisan::output());

        chmod($path, 0604);
        clearstatcache(true, $path);
        $this->assertSame(1, Artisan::call('herbarium:prune-missing-image-records', $options));
        $this->assertStringContainsString('group- or world-accessible', Artisan::output());

        chmod($path, 0600);
        clearstatcache(true, $path);
        $this->assertSame(0, Artisan::call('herbarium:prune-missing-image-records', $options), Artisan::output());
        $this->assertDatabaseMissing('herbarium_images', ['id' => $candidate->id]);
    }

    public function test_altered_unreferenced_file_audit_aborts_apply_without_deleting_candidates(): void
    {
        $this->enterMaintenanceMode();
        ['candidate' => $candidate, 'manifest' => $path] = $this->exportBasicManifest();
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $manifest['unreferenced_files'][0]['size']++;
        $alteredPath = $this->writeTemporaryManifest(json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n");

        $this->assertSame(1, Artisan::call(
            'herbarium:prune-missing-image-records',
            $this->applyOptions($alteredPath, hash_file('sha256', $alteredPath), 1, 1, 2),
        ));
        $this->assertStringContainsString('no longer matches', Artisan::output());
        $this->assertDatabaseHas('herbarium_images', ['id' => $candidate->id]);
    }

    public function test_unreferenced_count_uses_distinct_referenced_physical_files_not_accessible_record_count(): void
    {
        $herbarium = $this->herbarium('P13005');
        $this->image($herbarium, 'shared.jpg', 'first.jpg');
        $this->image($herbarium, 'shared.jpg', 'second.jpg');
        Storage::disk('public')->put('herbarium/shared.jpg', 'shared');
        Storage::disk('public')->put('herbarium/unreferenced.jpg', 'orphan');

        $this->artisan('herbarium:prune-missing-image-records')
            ->expectsTable(['Result', 'Count'], [
                ['Database rows scanned', 2],
                ['File-backed accessible records', 2],
                ['Confirmed missing candidates', 0],
                ['Complete metadata rows', 0],
                ['Checksum-conflict rows', 2],
                ['Unsafe filenames', 0],
                ['Inaccessible/indeterminate checks', 0],
                ['Physical files', 2],
                ['Referenced physical files', 1],
                ['Unreferenced physical files', 1],
                ['Duplicate database filenames', 1],
                ['Candidate activity references', 0],
                ['Candidate foreign/dependent references', 0],
            ])
            ->assertSuccessful();
    }

    public function test_wrong_digest_invalid_json_altered_snapshot_and_duplicate_ids_are_rejected(): void
    {
        $this->enterMaintenanceMode();
        ['candidate' => $candidate, 'manifest' => $path, 'digest' => $digest] = $this->exportBasicManifest();
        $apply = ['--apply' => true, '--manifest' => $path, ...$this->expectedOptions(1, 1, 2)];

        $this->assertSame(1, Artisan::call('herbarium:prune-missing-image-records', [
            ...$apply,
            '--manifest-sha256' => str_repeat('0', 64),
        ]));

        $invalidPath = $this->writeTemporaryManifest('{invalid json');
        $this->assertSame(1, Artisan::call('herbarium:prune-missing-image-records', [
            ...$apply,
            '--manifest' => $invalidPath,
            '--manifest-sha256' => hash_file('sha256', $invalidPath),
        ]));

        $altered = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $altered['candidates'][0]['filename'] = 'different.jpg';
        $alteredPath = $this->writeTemporaryManifest(json_encode($altered, JSON_THROW_ON_ERROR));
        $this->assertSame(1, Artisan::call('herbarium:prune-missing-image-records', [
            ...$apply,
            '--manifest' => $alteredPath,
            '--manifest-sha256' => hash_file('sha256', $alteredPath),
        ]));

        $duplicate = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $duplicate['candidates'][] = $duplicate['candidates'][0];
        $duplicate['counts']['confirmed_missing'] = 2;
        $duplicatePath = $this->writeTemporaryManifest(json_encode($duplicate, JSON_THROW_ON_ERROR));
        $this->image(Herbarium::findOrFail($candidate->herbarium_id), 'another-missing.jpg');
        $this->assertSame(1, Artisan::call('herbarium:prune-missing-image-records', [
            '--apply' => true,
            '--manifest' => $duplicatePath,
            '--manifest-sha256' => hash_file('sha256', $duplicatePath),
            ...$this->expectedOptions(2, 1, 2),
        ]));

        $this->assertDatabaseHas('herbarium_images', ['id' => $candidate->id]);
        $this->assertSame(hash_file('sha256', $path), $digest);
    }

    public function test_changed_missing_or_recovered_manifest_rows_abort_apply(): void
    {
        $this->enterMaintenanceMode();
        ['candidate' => $candidate, 'manifest' => $path, 'digest' => $digest] = $this->exportBasicManifest();
        $options = $this->applyOptions($path, $digest, 1, 1, 2);

        DB::table('herbarium_images')->where('id', $candidate->id)->update(['updated_at' => '2000-01-01 00:00:00']);
        $this->assertSame(1, Artisan::call('herbarium:prune-missing-image-records', $options));
        $this->assertDatabaseHas('herbarium_images', ['id' => $candidate->id]);

        DB::table('herbarium_images')->where('id', $candidate->id)->delete();
        $this->assertSame(1, Artisan::call('herbarium:prune-missing-image-records', $options));

        DB::table('herbarium_images')->delete();
        Storage::disk('public')->deleteDirectory('herbarium');
        ['candidate' => $restored, 'manifest' => $restoredPath, 'digest' => $restoredDigest] = $this->exportBasicManifest('P13007');
        Storage::disk('public')->put('herbarium/missing.jpg', 'recovered bytes');
        $this->assertSame(1, Artisan::call(
            'herbarium:prune-missing-image-records',
            $this->applyOptions($restoredPath, $restoredDigest, 1, 1, 2),
        ));
        $this->assertDatabaseHas('herbarium_images', ['id' => $restored->id]);
    }

    public function test_activity_reference_aborts_apply_without_deleting_anything(): void
    {
        $this->enterMaintenanceMode();
        $state = $this->basicReviewedState();
        DB::table('activity_log')->insert([
            'log_name' => 'default',
            'description' => 'historical reference',
            'subject_type' => HerbariumImages::class,
            'subject_id' => $state['candidate']->id,
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $path = $this->manifestPath();
        $this->assertSame(0, Artisan::call('herbarium:prune-missing-image-records', [
            '--export' => $path,
            ...$this->expectedOptions(1, 1, 2),
        ]));
        $digest = hash_file('sha256', $path);

        $this->assertSame(1, Artisan::call(
            'herbarium:prune-missing-image-records',
            $this->applyOptions($path, $digest, 1, 1, 2),
        ));
        $this->assertStringContainsString('activity or dependent reference', Artisan::output());
        $this->assertDatabaseHas('herbarium_images', ['id' => $state['candidate']->id]);
        $this->assertSame(1, Activity::count());
    }

    public function test_foreign_key_reference_aborts_apply_without_cascading_or_nulling(): void
    {
        $this->enterMaintenanceMode();
        $state = $this->basicReviewedState();
        Schema::create('herbarium_image_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('herbarium_image_id');
            $table->foreign('herbarium_image_id')->references('id')->on('herbarium_images');
        });

        try {
            DB::table('herbarium_image_dependencies')->insert(['herbarium_image_id' => $state['candidate']->id]);
            $path = $this->manifestPath();
            $this->assertSame(0, Artisan::call('herbarium:prune-missing-image-records', [
                '--export' => $path,
                ...$this->expectedOptions(1, 1, 2),
            ]));
            $digest = hash_file('sha256', $path);

            $this->assertSame(1, Artisan::call(
                'herbarium:prune-missing-image-records',
                $this->applyOptions($path, $digest, 1, 1, 2),
            ));
            $this->assertStringContainsString('activity or dependent reference', Artisan::output());
            $this->assertDatabaseHas('herbarium_images', ['id' => $state['candidate']->id]);
            $this->assertDatabaseHas('herbarium_image_dependencies', ['herbarium_image_id' => $state['candidate']->id]);
        } finally {
            DB::table('herbarium_image_dependencies')->delete();
            Schema::dropIfExists('herbarium_image_dependencies');
            DB::table('herbarium_images')->delete();
            DB::table('activity_log')->delete();
        }
    }

    public function test_apply_deletes_only_exact_manifest_rows_and_preserves_survivors_files_timestamps_and_activities(): void
    {
        $this->enterMaintenanceMode();
        $herbarium = $this->herbarium('P13010');
        $first = $this->image($herbarium, 'missing-one.jpg');
        $second = $this->image($herbarium, 'missing-two.jpg');
        $survivor = $this->image($herbarium, 'survivor.jpg', 'survivor.jpg', str_repeat('c', 64));
        $this->setTimestamps($survivor, '2001-02-03 04:05:06', '2002-03-04 05:06:07');
        Storage::disk('public')->put('herbarium/survivor.jpg', 'surviving bytes');
        Storage::disk('public')->put('herbarium/unreferenced.jpg', 'untouched orphan bytes');
        $filesBefore = $this->fileState();
        $survivorBefore = $survivor->fresh()->getRawOriginal();
        $path = $this->manifestPath();
        $expected = $this->expectedOptions(2, 1, 2);

        $this->assertSame(
            0,
            Artisan::call('herbarium:prune-missing-image-records', ['--export' => $path, ...$expected]),
            Artisan::output(),
        );
        $digest = hash_file('sha256', $path);
        $this->assertSame(0, Artisan::call('herbarium:prune-missing-image-records', [
            '--apply' => true,
            '--manifest' => $path,
            '--manifest-sha256' => $digest,
            ...$expected,
        ]));

        $this->assertDatabaseMissing('herbarium_images', ['id' => $first->id]);
        $this->assertDatabaseMissing('herbarium_images', ['id' => $second->id]);
        $this->assertSame($survivorBefore, $survivor->fresh()->getRawOriginal());
        $this->assertSame($filesBefore, $this->fileState());
        $this->assertSame(1, HerbariumImages::count());
        $this->assertSame(0, Activity::count());

        $unrelated = $this->image($herbarium, 'later-missing.jpg');
        $this->assertSame(1, Artisan::call('herbarium:prune-missing-image-records', [
            '--apply' => true,
            '--manifest' => $path,
            '--manifest-sha256' => $digest,
            ...$expected,
        ]));
        $this->assertDatabaseHas('herbarium_images', ['id' => $unrelated->id]);
    }

    public function test_delete_failure_rolls_back_the_entire_manifest_transaction(): void
    {
        $this->enterMaintenanceMode();
        $herbarium = $this->herbarium('P13011');
        $first = $this->image($herbarium, 'missing-one.jpg');
        $second = $this->image($herbarium, 'missing-two.jpg');
        $this->image($herbarium, 'survivor.jpg', 'survivor.jpg', str_repeat('d', 64));
        Storage::disk('public')->put('herbarium/survivor.jpg', 'survivor');
        Storage::disk('public')->put('herbarium/unreferenced.jpg', 'orphan');
        $path = $this->manifestPath();
        $expected = $this->expectedOptions(2, 1, 2);
        $this->assertSame(
            0,
            Artisan::call('herbarium:prune-missing-image-records', ['--export' => $path, ...$expected]),
            Artisan::output(),
        );
        $digest = hash_file('sha256', $path);
        $command = new class extends PruneMissingHerbariumImageRecords
        {
            protected function deleteRows(array $ids): int
            {
                DB::table('herbarium_images')->where('id', $ids[0])->delete();

                throw new \RuntimeException('Simulated deletion failure.');
            }
        };
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        $this->assertSame(1, $tester->execute([
                '--apply' => true,
                '--manifest' => $path,
                '--manifest-sha256' => $digest,
                ...$expected,
        ]));
        $this->assertDatabaseHas('herbarium_images', ['id' => $first->id]);
        $this->assertDatabaseHas('herbarium_images', ['id' => $second->id]);
    }

    public function test_unreferenced_file_audit_is_complete_and_apply_never_deletes_it(): void
    {
        $this->enterMaintenanceMode();
        $state = $this->basicReviewedState();
        DB::table('activity_log')->insert([
            'log_name' => 'default',
            'description' => 'orphan filename reference',
            'properties' => json_encode(['stored_filename' => 'unreferenced.bin'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('genus_images')->insert([
            'genus_id' => $state['candidate']->genus_id,
            'filename' => 'unreferenced.bin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $path = $this->manifestPath();
        $options = $this->expectedOptions(1, 1, 2);

        $this->artisan('herbarium:prune-missing-image-records', ['--export' => $path, ...$options])
            ->expectsOutputToContain('"unreferenced.bin"')
            ->expectsOutputToContain('Size:')
            ->expectsOutputToContain('SHA-256:')
            ->expectsOutputToContain('Modified:')
            ->expectsOutputToContain('Collision-safe format:')
            ->expectsOutputToContain('Checksum matches database:')
            ->expectsOutputToContain('Activity stored_filename reference: yes')
            ->expectsOutputToContain('Other database filename reference: yes')
            ->expectsOutputToContain('Configured public application path exists:')
            ->assertSuccessful();
        $orphanBefore = Storage::disk('public')->get('herbarium/unreferenced.bin');

        $this->assertSame(0, Artisan::call(
            'herbarium:prune-missing-image-records',
            $this->applyOptions($path, hash_file('sha256', $path), 1, 1, 2),
        ));
        Storage::disk('public')->assertExists('herbarium/unreferenced.bin');
        $this->assertSame($orphanBefore, Storage::disk('public')->get('herbarium/unreferenced.bin'));
        $this->assertDatabaseMissing('herbarium_images', ['id' => $state['candidate']->id]);
    }

    /** @return array{candidate: HerbariumImages, survivor: HerbariumImages} */
    private function basicReviewedState(string $collection = 'P13999'): array
    {
        $herbarium = $this->herbarium($collection);
        $candidate = $this->image($herbarium, 'missing.jpg');
        $survivor = $this->image($herbarium, 'present.jpg', 'present.jpg', str_repeat('e', 64));
        Storage::disk('public')->put('herbarium/present.jpg', 'present');
        Storage::disk('public')->put('herbarium/unreferenced.bin', 'unreferenced');

        return compact('candidate', 'survivor');
    }

    /** @return array{candidate: HerbariumImages, manifest: string, digest: string} */
    private function exportBasicManifest(string $collection = 'P13998'): array
    {
        $state = $this->basicReviewedState($collection);
        $path = $this->manifestPath();
        $this->assertSame(0, Artisan::call('herbarium:prune-missing-image-records', [
            '--export' => $path,
            ...$this->expectedOptions(1, 1, 2),
        ]));

        return [
            'candidate' => $state['candidate'],
            'manifest' => $path,
            'digest' => hash_file('sha256', $path),
        ];
    }

    /** @return array<string, int> */
    private function expectedOptions(int $missing, int $present, int $physical): array
    {
        return [
            '--expected-missing' => $missing,
            '--expected-present' => $present,
            '--expected-physical-files' => $physical,
        ];
    }

    /** @return array<string, int|string|bool> */
    private function applyOptions(string $path, string $digest, int $missing, int $present, int $physical): array
    {
        return [
            '--apply' => true,
            '--manifest' => $path,
            '--manifest-sha256' => $digest,
            ...$this->expectedOptions($missing, $present, $physical),
        ];
    }

    private function enterMaintenanceMode(): void
    {
        $this->assertSame(0, Artisan::call('down'));
        $this->assertTrue(app()->isDownForMaintenance());
    }

    private function restoreApplicationOnline(): void
    {
        try {
            if (isset($this->app)) {
                $this->app->maintenanceMode()->deactivate();
            }
        } finally {
            foreach ([storage_path('framework/down'), storage_path('framework/maintenance.php')] as $marker) {
                if (is_file($marker) || is_link($marker)) {
                    unlink($marker);
                }
            }
        }
    }

    private function manifestPath(): string
    {
        $path = sys_get_temp_dir().'/herbarium-prune-manifest-'.bin2hex(random_bytes(8)).'.json';
        $this->temporaryManifests[] = $path;

        return $path;
    }

    private function writeTemporaryManifest(string $contents): string
    {
        $path = $this->manifestPath();
        file_put_contents($path, $contents);
        chmod($path, 0600);

        return $path;
    }

    private function image(
        Herbarium $herbarium,
        string $filename,
        ?string $originalFilename = null,
        ?string $checksum = null,
    ): HerbariumImages {
        return HerbariumImages::create([
            'herbarium_id' => $herbarium->id,
            'genus_id' => $herbarium->genus_id,
            'filename' => $filename,
            'original_filename' => $originalFilename,
            'checksum' => $checksum,
        ]);
    }

    private function herbarium(string $collectionNumber): Herbarium
    {
        $family = Family::create(['family' => 'Family '.uniqid()]);
        $genus = Genus::create(['name' => 'Genus '.uniqid()]);

        return Herbarium::create([
            'family_id' => $family->id,
            'genus_id' => $genus->id,
            'collection_number' => $collectionNumber,
        ]);
    }

    private function setTimestamps(HerbariumImages $image, string $createdAt, string $updatedAt): void
    {
        DB::table('herbarium_images')->where('id', $image->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);
    }

    /** @return array<int, array{created_at: mixed, updated_at: mixed}> */
    private function timestampsById(): array
    {
        return DB::table('herbarium_images')
            ->orderBy('id')
            ->get(['id', 'created_at', 'updated_at'])
            ->mapWithKeys(static fn (object $row): array => [
                (int) $row->id => [
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ],
            ])
            ->all();
    }

    /** @return array<string, array{size: int, checksum: string}> */
    private function fileState(): array
    {
        $disk = Storage::disk('public');
        $state = [];

        foreach ($disk->allFiles('herbarium') as $path) {
            $contents = $disk->get($path);
            $state[$path] = ['size' => strlen($contents), 'checksum' => hash('sha256', $contents)];
        }

        ksort($state);

        return $state;
    }
}
