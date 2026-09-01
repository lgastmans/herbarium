<?php

namespace Tests\Feature;

use App\Exceptions\HerbariumImageImportException;
use App\Livewire\UploadHerbariumImage;
use App\Models\Family;
use App\Models\Genus;
use App\Models\Herbarium;
use App\Models\HerbariumImages;
use App\Models\User;
use App\Services\HerbariumImageStorage\HerbariumImageStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class UploadHerbariumImageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_valid_jpeg_uses_safe_storage_audit_feedback_and_refresh_event(): void
    {
        $herbarium = $this->herbarium('1100', 'Acacia');
        $component = Livewire::test(UploadHerbariumImage::class, ['herbariumId' => $herbarium->id])
            ->set('photo', UploadedFile::fake()->image('specimen.jpeg', 8, 8))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('photo', null)
            ->assertSet('statusType', 'success')
            ->assertSee('specimen.jpeg was uploaded successfully')
            ->assertDispatched('refreshHerbariumImageTable');

        $image = HerbariumImages::sole();
        $activity = Activity::sole();

        $this->assertSame($herbarium->id, $image->herbarium_id);
        $this->assertSame($herbarium->genus_id, $image->genus_id);
        $this->assertSame('specimen.jpeg', $image->original_filename);
        $this->assertStringEndsWith('.jpg', $image->filename);
        Storage::disk('public')->assertExists('herbarium/'.$image->filename);

        $properties = $activity->properties;
        $this->assertSame($this->user->id, $activity->causer_id);
        $this->assertSame(User::class, $activity->causer_type);
        $this->assertSame('specimen.jpeg', $properties->get('original_filename'));
        $this->assertSame($image->filename, $properties->get('stored_filename'));
        $this->assertSame('1100', $properties->get('collection_number'));
        $this->assertSame($image->checksum, $properties->get('checksum'));
        $this->assertSame('manual', $properties->get('assignment'));
        $this->assertSame('single_uploader', $properties->get('import_source'));
        $this->assertNull($properties->get('match_type'));
        $this->assertSame('success', $component->get('statusType'));
    }

    public function test_valid_png_is_imported_with_content_derived_extension(): void
    {
        $herbarium = $this->herbarium('1101', 'Ficus');

        Livewire::test(UploadHerbariumImage::class, ['herbariumId' => $herbarium->id])
            ->set('photo', UploadedFile::fake()->image('specimen.png', 8, 8))
            ->call('save')
            ->assertHasNoErrors();

        $image = HerbariumImages::sole();
        $this->assertStringEndsWith('.png', $image->filename);
        Storage::disk('public')->assertExists('herbarium/'.$image->filename);
    }

    public function test_spoofed_gif_svg_empty_and_oversized_files_are_rejected(): void
    {
        $herbarium = $this->herbarium('1102', 'Albizia');
        $invalidFiles = [
            UploadedFile::fake()->createWithContent('spoofed.jpg', 'plain text pretending to be an image'),
            UploadedFile::fake()->image('animated.gif', 8, 8),
            UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->createWithContent('empty.png', ''),
            UploadedFile::fake()->create('oversized.jpg', 5121, 'image/jpeg'),
        ];

        foreach ($invalidFiles as $file) {
            Livewire::test(UploadHerbariumImage::class, ['herbariumId' => $herbarium->id])
                ->set('photo', $file)
                ->call('save')
                ->assertHasErrors('photo');
        }

        $this->assertSame(0, HerbariumImages::count());
        $this->assertSame(0, Activity::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_checksum_duplicate_has_no_extra_side_effects_and_reports_feedback(): void
    {
        $herbarium = $this->herbarium('1103', 'Terminalia');
        $component = Livewire::test(UploadHerbariumImage::class, ['herbariumId' => $herbarium->id]);
        $component->set('photo', UploadedFile::fake()->image('first.jpg', 8, 8));
        /** @var TemporaryUploadedFile $temporaryFile */
        $temporaryFile = $component->get('photo');
        $contents = file_get_contents($temporaryFile->getPathname());
        $this->assertIsString($contents);
        $component->call('save');

        $component
            ->set('photo', UploadedFile::fake()->createWithContent('duplicate.jpg', $contents))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('photo', null)
            ->assertSet('statusType', 'duplicate')
            ->assertSee('already exists for the selected herbarium collection')
            ->assertDispatched('refreshHerbariumImageTable');

        $this->assertSame(1, HerbariumImages::count());
        $this->assertSame(1, Activity::count());
        $this->assertCount(1, Storage::disk('public')->allFiles('herbarium'));
    }

    public function test_genus_is_derived_from_a_fresh_herbarium_record(): void
    {
        $herbarium = $this->herbarium('1104', 'OriginalGenus');
        $currentGenus = Genus::create(['name' => 'CurrentGenus']);
        $component = Livewire::test(UploadHerbariumImage::class, ['herbariumId' => $herbarium->id])
            ->set('photo', UploadedFile::fake()->image('fresh-genus.jpg', 8, 8));

        $herbarium->update(['genus_id' => $currentGenus->id]);
        $component->call('save');

        $this->assertSame($currentGenus->id, HerbariumImages::sole()->genus_id);
    }

    public function test_mounted_herbarium_identifier_is_locked_against_tampering(): void
    {
        $first = $this->herbarium('1105', 'FirstGenus');
        $second = $this->herbarium('1106', 'SecondGenus');
        $component = Livewire::test(UploadHerbariumImage::class, ['herbariumId' => $first->id]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('herbariumId', $second->id);
    }

    public function test_no_public_genus_identifier_can_be_injected(): void
    {
        $herbarium = $this->herbarium('1107', 'ProtectedGenus');
        $component = Livewire::test(UploadHerbariumImage::class, ['herbariumId' => $herbarium->id]);

        $this->expectException(PublicPropertyNotFoundException::class);
        $component->set('genus_id', 999999);
    }

    public function test_guest_and_unverified_user_cannot_invoke_save_directly(): void
    {
        $herbarium = $this->herbarium('1108', 'SecureGenus');

        auth()->logout();
        Livewire::test(UploadHerbariumImage::class, ['herbariumId' => $herbarium->id])
            ->call('save')
            ->assertUnauthorized();

        $this->actingAs(User::factory()->unverified()->create());
        Livewire::test(UploadHerbariumImage::class, ['herbariumId' => $herbarium->id])
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, HerbariumImages::count());
    }

    public function test_safe_storage_failure_is_logged_and_only_generic_feedback_is_exposed(): void
    {
        $herbarium = $this->herbarium('1109', 'FailureGenus');
        $component = Livewire::test(UploadHerbariumImage::class, ['herbariumId' => $herbarium->id])
            ->set('photo', UploadedFile::fake()->image('failure.jpg', 8, 8));
        $storageService = Mockery::mock(HerbariumImageStorageService::class);
        $storageService->shouldReceive('import')
            ->once()
            ->andThrow(new HerbariumImageImportException('SQL /sensitive/server/path'));
        $this->app->instance(HerbariumImageStorageService::class, $storageService);
        Log::spy();

        $component
            ->call('save')
            ->assertHasErrors('photo')
            ->assertSee('could not be uploaded safely')
            ->assertDontSee('SQL /sensitive/server/path');

        Log::shouldHaveReceived('warning')->once();
        $this->assertSame(0, HerbariumImages::count());
        $this->assertSame(0, Activity::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function herbarium(string $collectionNumber, string $genusName): Herbarium
    {
        $family = Family::create(['family' => 'Family '.$collectionNumber]);
        $genus = Genus::create(['name' => $genusName]);

        return Herbarium::create([
            'family_id' => $family->id,
            'genus_id' => $genus->id,
            'collection_number' => $collectionNumber,
        ]);
    }
}
