<?php

namespace Tests\Feature;

use App\Livewire\ImportHerbariumImages;
use App\Models\Family;
use App\Models\Genus;
use App\Models\Herbarium;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ImportHerbariumImagesDiscardWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_rendered_warning_uses_only_a_scalar_staged_count_and_covers_navigation(): void
    {
        $view = file_get_contents(resource_path('views/livewire/herbarium/import-images.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('stagedCount: @js($stagedCount)', $view);
        $this->assertStringContainsString('this.stagedCount > 0', $view);
        $this->assertStringContainsString('x-on:beforeunload.window="if (hasDiscardableWork())', $view);
        $this->assertStringContainsString('x-on:livewire:navigate.window="confirmNavigation($event)"', $view);
        $this->assertStringContainsString('staged-batch-state-updated.window', $view);
        $this->assertStringNotContainsString('@js($stagedImages)', $view);
    }

    public function test_staging_and_removing_rows_dispatch_synchronized_capacity_and_warning_state(): void
    {
        $component = Livewire::test(ImportHerbariumImages::class)
            ->set('incomingFile', UploadedFile::fake()->image('123.jpg', 8, 8))
            ->call('stageIncomingUpload')
            ->assertDispatched(
                'staged-batch-state-updated',
                remainingCapacity: 99,
                stagedCount: 1,
            );

        $rowKey = array_key_first($component->get('stagedImages'));

        $component
            ->call('removeStagedImage', $rowKey)
            ->assertDispatched(
                'staged-batch-state-updated',
                remainingCapacity: 100,
                stagedCount: 0,
            );
    }

    public function test_confirmation_dispatches_empty_state_after_success_and_retained_state_after_failure(): void
    {
        $successfulHerbarium = $this->herbarium('700');
        $successful = Livewire::test(ImportHerbariumImages::class)
            ->set('incomingFile', UploadedFile::fake()->image('700.jpg', 8, 8))
            ->call('stageIncomingUpload')
            ->call('analyzePendingRows')
            ->call('importBatch')
            ->assertDispatched(
                'staged-batch-state-updated',
                remainingCapacity: 100,
                stagedCount: 0,
            )
            ->assertDispatched('batch-import-finished', remaining: 100, stagedCount: 0);

        $this->assertSame([], $successful->get('stagedImages'));
        $this->assertSame($successfulHerbarium->id, \App\Models\HerbariumImages::sole()->herbarium_id);

        $failedHerbarium = $this->herbarium('701');
        $failed = Livewire::test(ImportHerbariumImages::class)
            ->set('incomingFile', UploadedFile::fake()->image('701.png', 8, 8))
            ->call('stageIncomingUpload')
            ->call('analyzePendingRows');

        $failedHerbarium->delete();

        $failed
            ->call('importBatch')
            ->assertSet('failedCount', 1)
            ->assertDispatched(
                'staged-batch-state-updated',
                remainingCapacity: 99,
                stagedCount: 1,
            )
            ->assertDispatched('batch-import-finished', remaining: 99, stagedCount: 1);

        $this->assertCount(1, $failed->get('stagedImages'));
        $this->assertNull(array_values($failed->get('selectedHerbaria'))[0]);
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
