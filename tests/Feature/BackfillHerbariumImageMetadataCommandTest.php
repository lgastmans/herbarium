<?php

namespace Tests\Feature;

use App\Livewire\ImportHerbariumImages;
use App\Models\Family;
use App\Models\Genus;
use App\Models\Herbarium;
use App\Models\HerbariumImages;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class BackfillHerbariumImageMetadataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_dry_run_reports_metadata_without_changing_the_database(): void
    {
        $row = $this->legacyImage($this->herbarium('9001'), 'legacy-9001.jpg');
        $timestamps = $this->setHistoricalTimestamps($row, '2012-01-02 03:04:05', '2013-02-03 04:05:06');
        Storage::disk('public')->put('herbarium/legacy-9001.jpg', 'historical image bytes');

        $this->artisan('herbarium:backfill-image-metadata')
            ->expectsOutputToContain('DRY RUN ONLY')
            ->expectsOutputToContain('would populate original filename and checksum')
            ->expectsOutputToContain('NO DATA CHANGED')
            ->assertSuccessful();

        $row->refresh();
        $this->assertNull($row->checksum);
        $this->assertNull($row->original_filename);
        $this->assertHistoricalTimestampsUnchanged($row, $timestamps);
    }

    public function test_apply_populates_metadata_from_the_stored_file_without_altering_files_or_rows(): void
    {
        $contents = 'actual stored bytes';
        $row = $this->legacyImage($this->herbarium('9002'), 'legacy-9002.png');
        $timestamps = $this->setHistoricalTimestamps($row, '2014-03-04 05:06:07', '2015-04-05 06:07:08');
        $recordBefore = $this->immutableImageState($row);
        Storage::disk('public')->put('herbarium/legacy-9002.png', $contents);
        $filesBefore = $this->storedFileState();
        $updates = [];
        DB::listen(function (QueryExecuted $query) use (&$updates): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update')
                && str_contains($query->sql, 'herbarium_images')) {
                $updates[] = $query->sql;
            }
        });

        $this->artisan('herbarium:backfill-image-metadata', ['--apply' => true])
            ->expectsOutputToContain('populated original filename and checksum')
            ->assertSuccessful();

        $row->refresh();
        $this->assertSame('legacy-9002.png', $row->original_filename);
        $this->assertSame(hash('sha256', $contents), $row->checksum);
        $this->assertHistoricalTimestampsUnchanged($row, $timestamps);
        $this->assertSame($recordBefore, $this->immutableImageState($row));
        $this->assertSame(1, HerbariumImages::count());
        $this->assertSame($filesBefore, $this->storedFileState());
        $this->assertSame(0, Activity::count());
        $this->assertCount(1, $updates, 'A normal row should receive both metadata values atomically.');
        $this->assertStringContainsString('checksum', $updates[0]);
        $this->assertStringContainsString('original_filename', $updates[0]);
        $this->assertStringNotContainsString('updated_at', $updates[0]);
    }

    public function test_existing_non_null_metadata_is_never_overwritten(): void
    {
        $checksum = str_repeat('a', 64);
        $row = $this->legacyImage(
            $this->herbarium('9003'),
            'legacy-9003.jpg',
            originalFilename: null,
            checksum: $checksum,
        );
        $timestamps = $this->setHistoricalTimestamps($row, '2016-05-06 07:08:09', '2017-06-07 08:09:10');
        Storage::disk('public')->put('herbarium/legacy-9003.jpg', 'different bytes');

        $this->artisan('herbarium:backfill-image-metadata', ['--apply' => true])->assertSuccessful();

        $row->refresh();
        $this->assertSame($checksum, $row->checksum);
        $this->assertSame('legacy-9003.jpg', $row->original_filename);
        $this->assertHistoricalTimestampsUnchanged($row, $timestamps);
    }

    public function test_apply_populates_only_checksum_without_changing_historical_timestamps(): void
    {
        $contents = 'checksum-only bytes';
        $row = $this->legacyImage(
            $this->herbarium('90031'),
            'checksum-only.jpg',
            originalFilename: 'historical-original.jpg',
        );
        $timestamps = $this->setHistoricalTimestamps($row, '2018-07-08 09:10:11', '2019-08-09 10:11:12');
        Storage::disk('public')->put('herbarium/checksum-only.jpg', $contents);

        $this->artisan('herbarium:backfill-image-metadata', ['--apply' => true])->assertSuccessful();

        $row->refresh();
        $this->assertSame('historical-original.jpg', $row->original_filename);
        $this->assertSame(hash('sha256', $contents), $row->checksum);
        $this->assertHistoricalTimestampsUnchanged($row, $timestamps);
    }

    public function test_missing_and_unsafe_files_are_reported_and_left_unchanged(): void
    {
        $herbarium = $this->herbarium('9004');
        $missing = $this->legacyImage($herbarium, 'missing.jpg');
        $unsafe = $this->legacyImage($herbarium, '../outside.jpg');

        $this->artisan('herbarium:backfill-image-metadata', ['--apply' => true])
            ->expectsOutputToContain("Image record {$missing->id}: stored file is missing")
            ->expectsOutputToContain("Image record {$unsafe->id}: unsafe stored filename")
            ->assertSuccessful();

        $this->assertNull($missing->fresh()->checksum);
        $this->assertNull($missing->fresh()->original_filename);
        $this->assertNull($unsafe->fresh()->checksum);
        $this->assertNull($unsafe->fresh()->original_filename);
    }

    public function test_unreadable_stream_failure_is_reported_safely(): void
    {
        $row = $this->legacyImage($this->herbarium('9005'), 'unreadable.jpg');
        $disk = $this->mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->with('herbarium/unreadable.jpg')->andReturnTrue();
        $disk->shouldReceive('readStream')->once()->andThrow(new \RuntimeException('private adapter detail'));
        Storage::shouldReceive('disk')->once()->with('public')->andReturn($disk);

        $this->artisan('herbarium:backfill-image-metadata', ['--apply' => true])
            ->expectsOutputToContain("Image record {$row->id}: stored file is unreadable or could not be hashed")
            ->doesntExpectOutputToContain('private adapter detail')
            ->assertSuccessful();

        $this->assertNull($row->fresh()->checksum);
    }

    public function test_same_herbarium_identical_files_assign_the_lowest_id_and_report_the_conflict(): void
    {
        $herbarium = $this->herbarium('9006');
        $first = $this->legacyImage($herbarium, 'first.jpg');
        $second = $this->legacyImage($herbarium, 'second.jpg');
        $firstTimestamps = $this->setHistoricalTimestamps($first, '2010-01-01 01:02:03', '2011-02-02 02:03:04');
        $secondTimestamps = $this->setHistoricalTimestamps($second, '2012-03-03 03:04:05', '2013-04-04 04:05:06');
        Storage::disk('public')->put('herbarium/first.jpg', 'same bytes');
        Storage::disk('public')->put('herbarium/second.jpg', 'same bytes');

        $this->artisan('herbarium:backfill-image-metadata', ['--apply' => true])
            ->expectsOutputToContain("Image record {$second->id}: checksum conflicts with owner image record {$first->id}")
            ->assertSuccessful();

        $this->assertSame(hash('sha256', 'same bytes'), $first->fresh()->checksum);
        $this->assertNull($second->fresh()->checksum);
        $this->assertSame('first.jpg', $first->fresh()->original_filename);
        $this->assertSame('second.jpg', $second->fresh()->original_filename);
        $this->assertHistoricalTimestampsUnchanged($first, $firstTimestamps);
        $this->assertHistoricalTimestampsUnchanged($second, $secondTimestamps);
    }

    public function test_an_existing_checksum_owner_blocks_a_later_candidate_but_not_its_original_filename(): void
    {
        $herbarium = $this->herbarium('9007');
        $checksum = hash('sha256', 'owned bytes');
        $owner = $this->legacyImage($herbarium, 'owner.jpg', 'owner-original.jpg', $checksum);
        $candidate = $this->legacyImage($herbarium, 'candidate.jpg');
        Storage::disk('public')->put('herbarium/candidate.jpg', 'owned bytes');

        $this->artisan('herbarium:backfill-image-metadata', ['--apply' => true])
            ->expectsOutputToContain("Image record {$candidate->id}: checksum conflicts with owner image record {$owner->id}")
            ->assertSuccessful();

        $this->assertNull($candidate->fresh()->checksum);
        $this->assertSame('candidate.jpg', $candidate->fresh()->original_filename);
        $this->assertSame($checksum, $owner->fresh()->checksum);
    }

    public function test_identical_bytes_for_different_herbaria_are_allowed(): void
    {
        $first = $this->legacyImage($this->herbarium('9008'), 'first-herbarium.jpg');
        $second = $this->legacyImage($this->herbarium('9009'), 'second-herbarium.jpg');
        Storage::disk('public')->put('herbarium/first-herbarium.jpg', 'shared bytes');
        Storage::disk('public')->put('herbarium/second-herbarium.jpg', 'shared bytes');

        $this->artisan('herbarium:backfill-image-metadata', ['--apply' => true])->assertSuccessful();

        $this->assertSame(hash('sha256', 'shared bytes'), $first->fresh()->checksum);
        $this->assertSame($first->fresh()->checksum, $second->fresh()->checksum);
    }

    public function test_repeated_apply_is_idempotent_and_limit_is_deterministic(): void
    {
        $herbarium = $this->herbarium('9010');
        $first = $this->legacyImage($herbarium, 'limit-first.jpg');
        $second = $this->legacyImage($herbarium, 'limit-second.jpg');
        $firstTimestamps = $this->setHistoricalTimestamps($first, '2008-01-02 03:04:05', '2009-02-03 04:05:06');
        $secondTimestamps = $this->setHistoricalTimestamps($second, '2010-03-04 05:06:07', '2011-04-05 06:07:08');
        Storage::disk('public')->put('herbarium/limit-first.jpg', 'first bytes');
        Storage::disk('public')->put('herbarium/limit-second.jpg', 'second bytes');

        $this->artisan('herbarium:backfill-image-metadata', ['--apply' => true, '--limit' => 1])
            ->assertSuccessful();

        $this->assertNotNull($first->fresh()->checksum);
        $this->assertNull($second->fresh()->checksum);
        $this->assertHistoricalTimestampsUnchanged($first, $firstTimestamps);
        $this->assertHistoricalTimestampsUnchanged($second, $secondTimestamps);

        $this->artisan('herbarium:backfill-image-metadata', ['--apply' => true])->assertSuccessful();
        $state = HerbariumImages::query()->orderBy('id')->get(['id', 'original_filename', 'checksum'])->toArray();

        $this->artisan('herbarium:backfill-image-metadata', ['--apply' => true])
            ->expectsOutputToContain('Already complete')
            ->assertSuccessful();

        $this->assertSame($state, HerbariumImages::query()->orderBy('id')->get(['id', 'original_filename', 'checksum'])->toArray());
        $this->assertHistoricalTimestampsUnchanged($first, $firstTimestamps);
        $this->assertHistoricalTimestampsUnchanged($second, $secondTimestamps);
        $this->assertSame(0, Activity::count());
    }

    public function test_backfilled_checksum_drives_advisory_and_authoritative_duplicate_skip(): void
    {
        $herbarium = $this->herbarium('9011');
        $upload = UploadedFile::fake()->image('9011.png', 8, 8);
        $contents = file_get_contents($upload->getPathname());
        $this->assertIsString($contents);
        $legacy = $this->legacyImage($herbarium, 'historical-9011.png');
        Storage::disk('public')->put('herbarium/historical-9011.png', $contents);

        $this->artisan('herbarium:backfill-image-metadata', ['--apply' => true])->assertSuccessful();
        $this->actingAs(User::factory()->admin()->create());

        $component = Livewire::test(ImportHerbariumImages::class)
            ->set('incomingFile', $upload)
            ->call('stageIncomingUpload')
            ->call('analyzePendingRows');
        $row = array_values($component->get('stagedImages'))[0];
        $this->assertSame('duplicate', $row['duplicate_status']);

        $component->call('importBatch')
            ->assertSet('importedCount', 0)
            ->assertSet('skippedCount', 1);

        $this->assertSame(1, HerbariumImages::count());
        $this->assertSame($legacy->id, HerbariumImages::sole()->id);
        $this->assertSame(0, Activity::count());
        $this->assertSame(['herbarium/historical-9011.png'], Storage::disk('public')->allFiles('herbarium'));
    }

    private function legacyImage(
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

    /** @return array{created_at: string, updated_at: string} */
    private function setHistoricalTimestamps(HerbariumImages $row, string $createdAt, string $updatedAt): array
    {
        DB::table($row->getTable())
            ->where('id', $row->id)
            ->update([
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);

        $row->refresh();

        return [
            'created_at' => $row->getRawOriginal('created_at'),
            'updated_at' => $row->getRawOriginal('updated_at'),
        ];
    }

    /** @param array{created_at: string, updated_at: string} $timestamps */
    private function assertHistoricalTimestampsUnchanged(HerbariumImages $row, array $timestamps): void
    {
        $row->refresh();
        $this->assertSame($timestamps['created_at'], $row->getRawOriginal('created_at'));
        $this->assertSame($timestamps['updated_at'], $row->getRawOriginal('updated_at'));
    }

    /** @return array{id: int, herbarium_id: int, genus_id: int, filename: string, created_at: mixed, updated_at: mixed} */
    private function immutableImageState(HerbariumImages $row): array
    {
        $row->refresh();

        return [
            'id' => $row->id,
            'herbarium_id' => $row->herbarium_id,
            'genus_id' => $row->genus_id,
            'filename' => $row->filename,
            'created_at' => $row->getRawOriginal('created_at'),
            'updated_at' => $row->getRawOriginal('updated_at'),
        ];
    }

    /** @return array<string, array{size: int, checksum: string}> */
    private function storedFileState(): array
    {
        $disk = Storage::disk('public');
        $state = [];

        foreach ($disk->allFiles() as $path) {
            $contents = $disk->get($path);
            $state[$path] = [
                'size' => strlen($contents),
                'checksum' => hash('sha256', $contents),
            ];
        }

        ksort($state);

        return $state;
    }
}
