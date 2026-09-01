<?php

namespace Tests\Feature;

use App\Livewire\ImportHerbariumImages;
use App\Models\Family;
use App\Models\Genus;
use App\Models\Herbarium;
use App\Models\HerbariumImages;
use App\Models\Specific;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ImportHerbariumImagesStagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_valid_jpeg_and_png_are_staged_sequentially_and_preserve_earlier_rows(): void
    {
        $component = Livewire::test(ImportHerbariumImages::class);

        $this->stage($component, UploadedFile::fake()->image('123.jpg', 8, 8));
        $firstRows = $component->get('stagedImages');
        $firstKey = array_key_first($firstRows);

        $this->stage($component, UploadedFile::fake()->image('124.PNG', 8, 8));
        $rows = $component->get('stagedImages');

        $this->assertCount(2, $rows);
        $this->assertArrayHasKey($firstKey, $rows);
        $this->assertSame('123.jpg', $rows[$firstKey]['original_filename']);
        $this->assertContains('124.PNG', array_column($rows, 'original_filename'));
        $this->assertContainsOnlyInstancesOf(
            TemporaryUploadedFile::class,
            array_column($rows, 'temporary_file'),
        );
    }

    public function test_invalid_filename_with_valid_image_remains_staged_as_invalid(): void
    {
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('not-a-collection.jpg', 8, 8));

        $component->call('analyzePendingRows');
        $row = array_values($component->get('stagedImages'))[0];

        $this->assertSame('invalid', $row['match_status']);
        $this->assertNull($row['selected_herbarium_id']);
        $this->assertNull($row['assignment_type']);
        $this->assertStringContainsString('filename', $row['message']);
    }

    public function test_unsupported_empty_and_oversized_files_are_rejected_without_losing_valid_rows(): void
    {
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('100.jpg', 8, 8));

        $invalidFiles = [
            UploadedFile::fake()->image('101.gif', 8, 8),
            UploadedFile::fake()->createWithContent('102.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->createWithContent('103.jpg', 'renamed text'),
            UploadedFile::fake()->create('104.jpg', 5121, 'image/jpeg'),
        ];

        foreach ($invalidFiles as $file) {
            $component
                ->set('incomingFile', $file)
                ->call('stageIncomingUpload')
                ->assertSet('incomingFile', null);

            $this->assertCount(1, $component->get('stagedImages'));
        }

        $emptyComponent = Livewire::test(ImportHerbariumImages::class)
            ->set('incomingFile', UploadedFile::fake()->createWithContent('105.png', ''))
            ->call('stageIncomingUpload')
            ->assertSet('incomingFile', null);

        $this->assertCount(1, $component->get('stagedImages'));
        $this->assertCount(0, $emptyComponent->get('stagedImages'));
    }

    public function test_server_enforces_the_maximum_of_100_rows(): void
    {
        $component = Livewire::test(ImportHerbariumImages::class);
        $instance = $component->instance();

        for ($index = 0; $index < ImportHerbariumImages::MAX_IMAGES; $index++) {
            $instance->stagedImages['row-'.$index] = ['selected_herbarium_id' => null];
        }

        $result = $instance->stageIncomingUpload();

        $this->assertFalse($result['accepted']);
        $this->assertSame(0, $result['remaining']);
        $this->assertStringContainsString('maximum of 100', $result['error']);
        $this->assertCount(ImportHerbariumImages::MAX_IMAGES, $instance->stagedImages);
    }

    public function test_analysis_assigns_exact_and_f_fallback_and_leaves_other_statuses_unassigned(): void
    {
        $exact = $this->herbarium('123', 'Acacia', 'nilotica');
        $fallback = $this->herbarium('F 124', 'Ficus', 'benghalensis');
        $ambiguousOne = $this->herbarium('F 00125', 'Albizia', 'lebbeck');
        $ambiguousTwo = $this->herbarium('F125', 'Albizia', 'amara');

        $component = Livewire::test(ImportHerbariumImages::class);

        foreach (['123.jpg', '124.jpeg', '125.jpg', '999.png', 'bad-name.jpg'] as $filename) {
            $this->stage($component, UploadedFile::fake()->image($filename, 8, 8));
        }

        $component->call('analyzePendingRows');
        $rows = $this->rowsByFilename($component->get('stagedImages'));

        $this->assertSame('matched', $rows['123.jpg']['match_status']);
        $this->assertSame('exact', $rows['123.jpg']['match_type']);
        $this->assertSame($exact->id, $rows['123.jpg']['suggested_herbarium_id']);
        $this->assertSame($exact->id, $rows['123.jpg']['selected_herbarium_id']);
        $this->assertSame('automatic', $rows['123.jpg']['assignment_type']);

        $this->assertSame('matched', $rows['124.jpeg']['match_status']);
        $this->assertSame('f_fallback', $rows['124.jpeg']['match_type']);
        $this->assertSame($fallback->id, $rows['124.jpeg']['selected_herbarium_id']);

        $this->assertSame('ambiguous', $rows['125.jpg']['match_status']);
        $this->assertNull($rows['125.jpg']['selected_herbarium_id']);
        $this->assertEqualsCanonicalizing(
            [$ambiguousOne->id, $ambiguousTwo->id],
            $rows['125.jpg']['candidate_ids'],
        );
        $this->assertCount(2, $rows['125.jpg']['candidate_options']);

        $this->assertSame('unmatched', $rows['999.png']['match_status']);
        $this->assertNull($rows['999.png']['selected_herbarium_id']);
        $this->assertSame('invalid', $rows['bad-name.jpg']['match_status']);
        $this->assertNull($rows['bad-name.jpg']['selected_herbarium_id']);

        $this->assertSame(2, $component->instance()->assignedCount());
        $this->assertSame(3, $component->instance()->unresolvedCount());
    }

    public function test_automatic_suggestion_can_be_overridden_and_restored_with_server_details(): void
    {
        $suggested = $this->herbarium('200', 'Acacia', 'auriculiformis');
        $override = $this->herbarium('201', 'Ficus');
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('200.jpg', 8, 8));
        $component->call('analyzePendingRows');

        $rowKey = array_key_first($component->get('stagedImages'));
        $component->set('selectedHerbaria.'.$rowKey, $override->id);
        $overridden = $component->get('stagedImages')[$rowKey];

        $this->assertSame($override->id, $overridden['selected_herbarium_id']);
        $this->assertSame('manual', $overridden['assignment_type']);
        $this->assertSame('201', $overridden['collection_number']);
        $this->assertSame('Ficus', $overridden['genus']);
        $this->assertNull($overridden['specific_name']);
        $this->assertSame('Ficus', $overridden['botanical_name']);
        $this->assertSame('200', $overridden['suggested_collection_number']);

        $component->set('selectedHerbaria.'.$rowKey, $suggested->id);
        $restored = $component->get('stagedImages')[$rowKey];

        $this->assertSame('automatic', $restored['assignment_type']);
        $this->assertSame('200', $restored['collection_number']);
        $this->assertSame('Acacia auriculiformis', $restored['botanical_name']);
    }

    public function test_invalid_manual_selection_is_rejected_and_previous_assignment_is_preserved(): void
    {
        $suggested = $this->herbarium('210', 'Ficus', 'religiosa');
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('210.jpg', 8, 8));
        $component->call('analyzePendingRows');
        $rowKey = array_key_first($component->get('stagedImages'));

        $component->set('selectedHerbaria.'.$rowKey, 999999);

        $this->assertSame($suggested->id, $component->get('selectedHerbaria')[$rowKey]);
        $this->assertSame($suggested->id, $component->get('stagedImages')[$rowKey]['selected_herbarium_id']);
        $component->assertHasErrors('selectedHerbaria.'.$rowKey);
    }

    public function test_removing_one_stable_row_deletes_its_temporary_file_and_preserves_other_rows(): void
    {
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('300.jpg', 8, 8));
        $this->stage($component, UploadedFile::fake()->image('301.png', 8, 8));
        $rows = $component->get('stagedImages');
        $keys = array_keys($rows);
        $removedFile = $rows[$keys[0]]['temporary_file'];

        $this->assertTrue($removedFile->exists());

        $component->call('removeStagedImage', $keys[0]);
        $remainingRows = $component->get('stagedImages');

        $this->assertFalse($removedFile->exists());
        $this->assertArrayNotHasKey($keys[0], $remainingRows);
        $this->assertArrayHasKey($keys[1], $remainingRows);
        $this->assertSame('301.png', $remainingRows[$keys[1]]['original_filename']);

        $component->call('removeStagedImage', $keys[0])->assertHasNoErrors();
    }

    public function test_rendered_rows_have_stable_keys_thumbnails_and_protected_async_selectors(): void
    {
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('400.jpg', 8, 8));
        $rowKey = array_key_first($component->get('stagedImages'));
        $asyncSelectorData = base64_encode(json_encode([
            'api' => route('ajax.herbaria'),
            'method' => 'GET',
            'params' => [],
            'alwaysFetch' => false,
        ]));

        $component
            ->assertSee('data-row-key="'.$rowKey.'"', false)
            ->assertSee('herbarium-image-row-'.$rowKey, false)
            ->assertSee('Temporary preview of 400.jpg')
            ->assertSee($asyncSelectorData, false)
            ->assertSee('Import assigned images');
    }

    public function test_phase_4a_creates_no_permanent_images_activities_or_public_files(): void
    {
        $herbarium = $this->herbarium('500', 'Ficus', 'elastica');
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('500.jpg', 8, 8));
        $component->call('analyzePendingRows');

        $this->assertSame($herbarium->id, array_values($component->get('stagedImages'))[0]['selected_herbarium_id']);
        $this->assertSame(0, HerbariumImages::count());
        $this->assertSame(0, Activity::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function stage($component, UploadedFile $file): void
    {
        $component
            ->set('incomingFile', $file)
            ->call('stageIncomingUpload')
            ->assertSet('incomingFile', null);
    }

    /** @return array<string, array<string, mixed>> */
    private function rowsByFilename(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $indexed[$row['original_filename']] = $row;
        }

        return $indexed;
    }

    private function herbarium(string $collectionNumber, string $genusName, ?string $specificName = null): Herbarium
    {
        $family = Family::create(['family' => 'Family '.uniqid()]);
        $genus = Genus::create(['name' => $genusName]);
        $specific = $specificName === null ? null : Specific::create(['name' => $specificName]);

        return Herbarium::create([
            'family_id' => $family->id,
            'genus_id' => $genus->id,
            'specific_id' => $specific?->id,
            'collection_number' => $collectionNumber,
        ]);
    }
}
