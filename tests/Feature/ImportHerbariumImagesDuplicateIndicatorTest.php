<?php

namespace Tests\Feature;

use App\Livewire\ImportHerbariumImages;
use App\Models\Family;
use App\Models\Genus;
use App\Models\Herbarium;
use App\Models\HerbariumImages;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ImportHerbariumImagesDuplicateIndicatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_automatic_review_flags_an_existing_checksum_without_creating_side_effects(): void
    {
        $herbarium = $this->herbarium('13536');
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('13536.jpg', 8, 8));
        $checksum = $this->stagedChecksum($component);
        $this->existingImage($herbarium, $checksum);

        $component->call('analyzePendingRows');
        $row = $this->onlyRow($component);

        $this->assertSame('matched', $row['match_status']);
        $this->assertSame('duplicate', $row['duplicate_status']);
        $this->assertStringContainsString('Already imported', $row['duplicate_message']);
        $this->assertStringContainsString('collection 13536', $row['duplicate_message']);
        $this->assertArrayNotHasKey('checksum', $row);
        $component->assertSee('Already imported');
        $this->assertStringNotContainsString($checksum, $component->html());
        $this->assertSame(1, HerbariumImages::count());
        $this->assertSame(0, Activity::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_unique_automatic_match_is_not_flagged(): void
    {
        $this->herbarium('13537');
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('13537.png', 8, 8));

        $component->call('analyzePendingRows');
        $row = $this->onlyRow($component);

        $this->assertSame('unique', $row['duplicate_status']);
        $this->assertNull($row['duplicate_message']);
        $component->assertDontSee('Already imported');
    }

    public function test_manual_selection_recalculates_duplicate_scope_and_clearing_removes_the_indicator(): void
    {
        $duplicateHerbarium = $this->herbarium('13538');
        $otherHerbarium = $this->herbarium('13539');
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('manual-name.jpg', 8, 8));
        $checksum = $this->stagedChecksum($component);
        $this->existingImage($duplicateHerbarium, $checksum);
        $component->call('analyzePendingRows');
        $rowKey = array_key_first($component->get('stagedImages'));

        $component->set('selectedHerbaria.'.$rowKey, $duplicateHerbarium->id);
        $this->assertSame('duplicate', $this->onlyRow($component)['duplicate_status']);

        $component->set('selectedHerbaria.'.$rowKey, $otherHerbarium->id);
        $this->assertSame('unique', $this->onlyRow($component)['duplicate_status']);
        $this->assertNull($this->onlyRow($component)['duplicate_message']);

        $component->set('selectedHerbaria.'.$rowKey, null);
        $this->assertNull($this->onlyRow($component)['duplicate_status']);
        $this->assertNull($this->onlyRow($component)['duplicate_message']);
    }

    public function test_automatic_duplicate_analysis_uses_one_batched_duplicate_query(): void
    {
        $component = Livewire::test(ImportHerbariumImages::class);

        foreach (['14001', '14002', '14003'] as $index => $collectionNumber) {
            $this->herbarium($collectionNumber);
            $this->stage(
                $component,
                UploadedFile::fake()->image($collectionNumber.'.jpg', 8 + $index, 8 + $index),
            );
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->call('analyzePendingRows');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $duplicateQueries = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains(strtolower($query['query']), 'herbarium_images')
                && str_contains(strtolower($query['query']), 'checksum'),
        ));

        $this->assertCount(1, $duplicateQueries);
        $this->assertSame(
            ['unique'],
            array_values(array_unique(array_column($component->get('stagedImages'), 'duplicate_status'))),
        );
    }

    public function test_advisory_tampering_cannot_force_a_skip_or_bypass_an_authoritative_duplicate(): void
    {
        $herbarium = $this->herbarium('15001');
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('15001.jpg', 8, 8));
        $contents = file_get_contents($this->temporaryFile($component)->getPathname());
        $component->call('analyzePendingRows');
        $rowKey = array_key_first($component->get('stagedImages'));
        $rows = $component->get('stagedImages');
        $rows[$rowKey]['duplicate_status'] = 'duplicate';
        $rows[$rowKey]['duplicate_message'] = 'Forged duplicate state.';

        $component
            ->set('stagedImages', $rows)
            ->call('importBatch')
            ->assertSet('importedCount', 1)
            ->assertSet('skippedCount', 0);

        $this->assertIsString($contents);
        $duplicate = Livewire::test(ImportHerbariumImages::class);
        $this->stage($duplicate, UploadedFile::fake()->createWithContent('copy.jpg', $contents));
        $duplicate->call('analyzePendingRows');
        $duplicateKey = array_key_first($duplicate->get('stagedImages'));
        $duplicate->set('selectedHerbaria.'.$duplicateKey, $herbarium->id);
        $duplicateRows = $duplicate->get('stagedImages');
        $duplicateRows[$duplicateKey]['duplicate_status'] = 'unique';
        $duplicateRows[$duplicateKey]['duplicate_message'] = null;

        $duplicate
            ->set('stagedImages', $duplicateRows)
            ->call('importBatch')
            ->assertSet('importedCount', 0)
            ->assertSet('skippedCount', 1);

        $this->assertSame(1, HerbariumImages::count());
        $this->assertSame(1, Activity::count());
        $this->assertCount(1, Storage::disk('public')->allFiles('herbarium'));
    }

    private function stage($component, UploadedFile $file): void
    {
        $component
            ->set('incomingFile', $file)
            ->call('stageIncomingUpload')
            ->assertSet('incomingFile', null);
    }

    /** @return array<string, mixed> */
    private function onlyRow($component): array
    {
        return array_values($component->get('stagedImages'))[0];
    }

    private function temporaryFile($component)
    {
        return $this->onlyRow($component)['temporary_file'];
    }

    private function stagedChecksum($component): string
    {
        $checksum = hash_file('sha256', $this->temporaryFile($component)->getPathname());
        $this->assertIsString($checksum);

        return $checksum;
    }

    private function existingImage(Herbarium $herbarium, string $checksum): HerbariumImages
    {
        return HerbariumImages::create([
            'herbarium_id' => $herbarium->id,
            'genus_id' => $herbarium->genus_id,
            'filename' => 'existing-'.$herbarium->id.'.jpg',
            'original_filename' => 'existing.jpg',
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
}
