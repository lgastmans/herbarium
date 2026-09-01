<?php

namespace Tests\Feature;

use App\Livewire\ImportHerbariumImages;
use App\Models\Family;
use App\Models\Genus;
use App\Models\Herbarium;
use App\Models\HerbariumImages;
use App\Models\Specific;
use App\Models\User;
use App\Services\HerbariumImageStorage\HerbariumImageAssignmentType;
use App\Services\HerbariumImageStorage\HerbariumImageImportSource;
use App\Services\HerbariumImageStorage\HerbariumImageStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ImportHerbariumImagesBatchImportTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->administrator = User::factory()->admin()->create();
        $this->actingAs($this->administrator);
    }

    public function test_empty_and_oversized_batches_are_rejected_without_writes(): void
    {
        $empty = Livewire::test(ImportHerbariumImages::class)
            ->call('importBatch')
            ->assertHasErrors('batch')
            ->assertSet('totalProcessed', 0);

        $this->assertStringContainsString('no staged images', $empty->get('batchMessage'));

        $oversized = Livewire::test(ImportHerbariumImages::class)->instance();

        for ($index = 0; $index <= ImportHerbariumImages::MAX_IMAGES; $index++) {
            $oversized->stagedImages['row-'.$index] = [];
        }

        $oversized->importBatch(
            app(\App\Services\HerbariumImageMatching\HerbariumImageMatcher::class),
            app(HerbariumImageStorageService::class),
        );

        $this->assertStringContainsString('more than 100', (string) $oversized->batchMessage);
        $this->assertSame(0, $oversized->totalProcessed);
        $this->assertSame(0, HerbariumImages::count());
        $this->assertSame(0, Activity::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_incomplete_assignments_block_the_entire_batch_before_any_import(): void
    {
        $this->herbarium('100', 'Acacia');
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('100.jpg', 8, 8));
        $this->stage($component, UploadedFile::fake()->image('bad-name.png', 8, 8));
        $component->call('analyzePendingRows');

        $component
            ->call('importBatch')
            ->assertHasErrors('batch')
            ->assertSet('totalProcessed', 0)
            ->assertSet('importedCount', 0);

        $this->assertCount(2, $component->get('stagedImages'));
        $this->assertSame(0, HerbariumImages::count());
        $this->assertSame(0, Activity::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_exact_automatic_import_uses_administrator_and_complete_audit_properties(): void
    {
        $herbarium = $this->herbarium('123', 'Acacia', 'nilotica');
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('00123.jpg', 8, 8));
        $component->call('analyzePendingRows');
        $temporaryFile = $this->onlyTemporaryFile($component);
        $rows = $component->get('stagedImages');
        $rowKey = array_key_first($rows);
        $rows[$rowKey]['assignment_type'] = 'manual';
        $component->set('stagedImages', $rows);

        $component
            ->call('importBatch')
            ->assertHasNoErrors()
            ->assertSet('importedCount', 1)
            ->assertSet('skippedCount', 0)
            ->assertSet('failedCount', 0)
            ->assertSet('totalProcessed', 1)
            ->assertDispatched('batch-import-finished');

        $image = HerbariumImages::sole();
        $activity = Activity::sole();

        $this->assertSame($herbarium->id, $image->herbarium_id);
        $this->assertSame($herbarium->genus_id, $image->genus_id);
        $this->assertSame('00123.jpg', $image->original_filename);
        $this->assertSame($this->administrator->id, $activity->causer_id);
        $this->assertSame('00123.jpg', $activity->properties->get('original_filename'));
        $this->assertSame('123', $activity->properties->get('collection_number'));
        $this->assertSame($image->checksum, $activity->properties->get('checksum'));
        $this->assertSame('automatic', $activity->properties->get('assignment'));
        $this->assertSame('batch', $activity->properties->get('import_source'));
        $this->assertSame('exact', $activity->properties->get('match_type'));
        $this->assertFalse($temporaryFile->exists());
        $this->assertSame([], $component->get('stagedImages'));
        $this->assertStringContainsString('staged batch is now empty', (string) $component->get('batchMessage'));
    }

    public function test_f_fallback_is_recomputed_as_automatic(): void
    {
        $herbarium = $this->herbarium('F 00124', 'Ficus', 'benghalensis');
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('124.png', 8, 8));
        $component->call('analyzePendingRows');
        $rows = $component->get('stagedImages');
        $rowKey = array_key_first($rows);
        $rows[$rowKey]['match_type'] = 'exact';
        $rows[$rowKey]['suggested_herbarium_id'] = null;
        $rows[$rowKey]['assignment_type'] = 'manual';
        $component->set('stagedImages', $rows)->call('importBatch');

        $this->assertSame($herbarium->id, HerbariumImages::sole()->herbarium_id);
        $properties = Activity::sole()->properties;
        $this->assertSame('automatic', $properties->get('assignment'));
        $this->assertSame('f_fallback', $properties->get('match_type'));
    }

    public function test_unmatched_and_invalid_filenames_can_be_imported_as_manual_assignments(): void
    {
        $herbarium = $this->herbarium('300', 'Azadirachta', 'indica');
        $component = Livewire::test(ImportHerbariumImages::class);

        $this->stage($component, UploadedFile::fake()->image('999.jpg', 8, 8));
        $this->stage($component, UploadedFile::fake()->image('invalid-name.png', 8, 8));
        $component->call('analyzePendingRows');

        foreach (array_keys($component->get('stagedImages')) as $rowKey) {
            $component->set('selectedHerbaria.'.$rowKey, $herbarium->id);
        }

        $component->call('importBatch');

        $this->assertSame(2, HerbariumImages::count());
        $this->assertSame(['manual'], Activity::query()->get()->pluck('properties')->map(
            fn ($properties) => $properties->get('assignment'),
        )->unique()->values()->all());
        $this->assertSame([null], Activity::query()->get()->pluck('properties')->map(
            fn ($properties) => $properties->get('match_type'),
        )->unique()->values()->all());
    }

    public function test_public_match_assignment_filename_and_botanical_tampering_is_ignored(): void
    {
        $suggested = $this->herbarium('400', 'Acacia', 'auriculiformis');
        $override = $this->herbarium('401', 'Ficus', 'religiosa');
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('400.jpg', 8, 8));
        $component->call('analyzePendingRows');
        $rowKey = array_key_first($component->get('stagedImages'));
        $component->set('selectedHerbaria.'.$rowKey, $override->id);

        $rows = $component->get('stagedImages');
        $rows[$rowKey]['original_filename'] = 'tampered.png';
        $rows[$rowKey]['match_status'] = 'matched';
        $rows[$rowKey]['match_type'] = 'f_fallback';
        $rows[$rowKey]['suggested_herbarium_id'] = $override->id;
        $rows[$rowKey]['assignment_type'] = 'automatic';
        $rows[$rowKey]['collection_number'] = 'FORGED';
        $rows[$rowKey]['genus'] = 'Forged genus';
        $rows[$rowKey]['specific_name'] = 'forged';
        $rows[$rowKey]['botanical_name'] = 'Forged genus forged';
        $component->set('stagedImages', $rows)->call('importBatch');

        $image = HerbariumImages::sole();
        $properties = Activity::sole()->properties;

        $this->assertSame($override->id, $image->herbarium_id);
        $this->assertSame($override->genus_id, $image->genus_id);
        $this->assertSame('400.jpg', $image->original_filename);
        $this->assertSame('400.jpg', $properties->get('original_filename'));
        $this->assertSame('401', $properties->get('collection_number'));
        $this->assertSame('manual', $properties->get('assignment'));
        $this->assertSame('exact', $properties->get('match_type'));
        $this->assertNotSame($suggested->id, $image->herbarium_id);
    }

    public function test_storage_uses_a_fresh_server_derived_genus_after_staging(): void
    {
        $herbarium = $this->herbarium('500', 'OriginalGenus');
        $newGenus = Genus::create(['name' => 'CurrentGenus']);
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('500.jpg', 8, 8));
        $component->call('analyzePendingRows');

        $herbarium->update(['genus_id' => $newGenus->id]);
        $component->call('importBatch');

        $this->assertSame($newGenus->id, HerbariumImages::sole()->genus_id);
    }

    public function test_duplicate_checksum_is_skipped_and_repeated_confirmation_creates_nothing_more(): void
    {
        $herbarium = $this->herbarium('600', 'Syzygium', 'cumini');
        $component = Livewire::test(ImportHerbariumImages::class);
        $firstUpload = UploadedFile::fake()->image('600.jpg', 8, 8);
        $this->stage($component, $firstUpload);
        $component->call('analyzePendingRows');
        $firstTemporaryFile = $this->onlyTemporaryFile($component);
        $contents = file_get_contents($firstTemporaryFile->getPathname());
        $component->call('importBatch');

        $this->assertIsString($contents);
        $duplicateUpload = UploadedFile::fake()->createWithContent('copy-600.jpg', $contents);
        $this->stage($component, $duplicateUpload);
        $component->call('analyzePendingRows');
        $rowKey = array_key_first($component->get('stagedImages'));
        $component->set('selectedHerbaria.'.$rowKey, $herbarium->id);
        $duplicateTemporaryFile = $this->onlyTemporaryFile($component);
        $component->call('importBatch');

        $this->assertSame(0, $component->get('importedCount'));
        $this->assertSame(1, $component->get('skippedCount'));
        $this->assertSame('skipped', array_values($component->get('batchResults'))[0]['outcome']);
        $this->assertStringContainsString('same image already exists', array_values($component->get('batchResults'))[0]['message']);
        $this->assertFalse($duplicateTemporaryFile->exists());
        $this->assertSame(1, HerbariumImages::count());
        $this->assertSame(1, Activity::count());
        $this->assertCount(1, Storage::disk('public')->allFiles('herbarium'));

        $component->call('importBatch')->assertHasErrors('batch');
        $this->assertSame(1, HerbariumImages::count());
        $this->assertSame(1, Activity::count());
        $this->assertCount(1, Storage::disk('public')->allFiles('herbarium'));
    }

    public function test_mixed_imported_skipped_and_failed_rows_continue_independently(): void
    {
        $herbarium = $this->herbarium('700', 'Terminalia', 'arjuna');
        $existingSource = UploadedFile::fake()->image('700.jpg', 8, 8);
        $existingContents = file_get_contents($existingSource->getPathname());
        app(HerbariumImageStorageService::class)->import(
            $herbarium,
            $existingSource,
            '700.jpg',
            HerbariumImageAssignmentType::Automatic,
            HerbariumImageImportSource::Batch,
            causer: $this->administrator,
        );

        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->createWithContent('duplicate.jpg', $existingContents));
        $this->stage($component, UploadedFile::fake()->image('unique.jpg', 9, 9));
        $this->stage($component, UploadedFile::fake()->image('corrupt.jpg', 10, 10));
        $component->call('analyzePendingRows');

        foreach (array_keys($component->get('stagedImages')) as $rowKey) {
            $component->set('selectedHerbaria.'.$rowKey, $herbarium->id);
        }

        $rows = $component->get('stagedImages');
        $keys = array_keys($rows);
        $duplicateTemporaryFile = $rows[$keys[0]]['temporary_file'];
        $importedTemporaryFile = $rows[$keys[1]]['temporary_file'];
        $failedTemporaryFile = $rows[$keys[2]]['temporary_file'];
        file_put_contents($failedTemporaryFile->getPathname(), 'corrupted image bytes');

        $component
            ->call('importBatch')
            ->assertDispatched(
                'staged-batch-state-updated',
                remainingCapacity: 99,
                stagedCount: 1,
            );

        $this->assertSame(1, $component->get('importedCount'));
        $this->assertSame(1, $component->get('skippedCount'));
        $this->assertSame(1, $component->get('failedCount'));
        $this->assertSame(3, $component->get('totalProcessed'));
        $this->assertFalse($duplicateTemporaryFile->exists());
        $this->assertFalse($importedTemporaryFile->exists());
        $this->assertTrue($failedTemporaryFile->exists());
        $this->assertSame([$keys[2]], array_keys($component->get('stagedImages')));
        $this->assertSame('failed', $component->get('batchResults')[$keys[2]]['outcome']);
        $this->assertStringNotContainsString($failedTemporaryFile->getPathname(), $component->get('batchResults')[$keys[2]]['message']);
        $this->assertSame(2, HerbariumImages::count());
        $this->assertSame(2, Activity::count());
        $this->assertCount(2, Storage::disk('public')->allFiles('herbarium'));
    }

    public function test_deleted_selection_fails_only_its_row_clears_assignment_and_can_be_retried(): void
    {
        $validHerbarium = $this->herbarium('800', 'Pongamia', 'pinnata');
        $deletedHerbarium = $this->herbarium('801', 'Cassia', 'fistula');
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('manual-one.jpg', 8, 8));
        $this->stage($component, UploadedFile::fake()->image('manual-two.jpg', 9, 9));
        $component->call('analyzePendingRows');
        $keys = array_keys($component->get('stagedImages'));
        $component->set('selectedHerbaria.'.$keys[0], $validHerbarium->id);
        $component->set('selectedHerbaria.'.$keys[1], $deletedHerbarium->id);
        $deletedHerbarium->delete();

        $component->call('importBatch');

        $this->assertSame(1, $component->get('importedCount'));
        $this->assertSame(1, $component->get('failedCount'));
        $this->assertSame([$keys[1]], array_keys($component->get('stagedImages')));
        $this->assertNull($component->get('selectedHerbaria')[$keys[1]]);
        $failedRow = $component->get('stagedImages')[$keys[1]];
        $this->assertNull($failedRow['selected_herbarium_id']);
        $this->assertNull($failedRow['collection_number']);
        $this->assertNull($failedRow['genus']);
        $this->assertStringContainsString('no longer exists', $failedRow['message']);

        $replacement = $this->herbarium('802', 'Cassia', 'roxburghii');
        $component->set('selectedHerbaria.'.$keys[1], $replacement->id)->call('importBatch');

        $this->assertSame(1, $component->get('importedCount'));
        $this->assertSame(0, $component->get('failedCount'));
        $this->assertSame([], $component->get('stagedImages'));
        $this->assertSame(2, HerbariumImages::count());
    }

    public function test_expired_temporary_file_fails_safely_and_is_not_retained(): void
    {
        $this->herbarium('900', 'Butea', 'monosperma');
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('900.jpg', 8, 8));
        $component->call('analyzePendingRows');
        $temporaryFile = $this->onlyTemporaryFile($component);
        $temporaryFile->delete();

        $instance = $component->instance();
        $instance->importBatch(
            app(\App\Services\HerbariumImageMatching\HerbariumImageMatcher::class),
            app(HerbariumImageStorageService::class),
        );

        $this->assertSame(1, $instance->failedCount);
        $this->assertSame(1, $instance->totalProcessed);
        $this->assertSame([], $instance->stagedImages);
        $this->assertSame(0, HerbariumImages::count());
        $this->assertSame(0, Activity::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_revoked_administrator_cannot_confirm_a_mounted_batch(): void
    {
        $this->herbarium('950', 'Mangifera', 'indica');
        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('950.jpg', 8, 8));
        $component->call('analyzePendingRows');

        User::query()->whereKey($this->administrator->id)->update(['is_admin' => false]);

        $component->call('importBatch')->assertForbidden();
        $this->assertSame(0, HerbariumImages::count());
        $this->assertSame(0, Activity::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_button_state_confirmation_loading_summary_and_synchronous_architecture_render(): void
    {
        $empty = Livewire::test(ImportHerbariumImages::class);
        $this->assertImportButtonDisabled($empty->html());

        $component = Livewire::test(ImportHerbariumImages::class);
        $this->stage($component, UploadedFile::fake()->image('needs-assignment.jpg', 8, 8));
        $component->call('analyzePendingRows');
        $this->assertImportButtonDisabled($component->html());
        $component->assertSee('Assign a herbarium collection to every staged image');

        $herbarium = $this->herbarium('999', 'Tectona', 'grandis');
        $rowKey = array_key_first($component->get('stagedImages'));
        $component->set('selectedHerbaria.'.$rowKey, $herbarium->id);
        $this->assertImportButtonEnabled($component->html());

        $view = file_get_contents(resource_path('views/livewire/herbarium/import-images.blade.php'));
        $this->assertIsString($view);
        $this->assertStringContainsString('wire:click="importBatch"', $view);
        $this->assertStringContainsString('wire:confirm=', $view);
        $this->assertStringContainsString('wire:loading.attr="disabled"', $view);
        $this->assertStringContainsString('Importing…', $view);
        $this->assertStringContainsString('wire:key="batch-result-', $view);

        Queue::fake();
        $component->call('importBatch')->assertSee('Batch import result');
        Queue::assertNothingPushed();
        $this->assertFalse(Schema::hasTable('herbarium_image_import_batches'));
        $this->assertFalse(Schema::hasTable('herbarium_image_import_rows'));
    }

    private function stage($component, UploadedFile $file): void
    {
        $component
            ->set('incomingFile', $file)
            ->call('stageIncomingUpload')
            ->assertSet('incomingFile', null);
    }

    private function onlyTemporaryFile($component)
    {
        return array_values($component->get('stagedImages'))[0]['temporary_file'];
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

    private function assertImportButtonDisabled(string $html): void
    {
        $button = $this->importButton($html);
        $this->assertMatchesRegularExpression('/\sdisabled(?:[=\s>])/', $button);
    }

    private function assertImportButtonEnabled(string $html): void
    {
        $button = $this->importButton($html);
        $this->assertDoesNotMatchRegularExpression('/\sdisabled(?:[=\s>])/', $button);
    }

    private function importButton(string $html): string
    {
        $matched = preg_match('/<button[^>]*wire:click="importBatch"[^>]*>/s', $html, $matches);
        $this->assertSame(1, $matched, 'The import button was not rendered.');

        return $matches[0];
    }
}
