<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Genus;
use App\Models\Herbarium;
use App\Models\HerbariumImages;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ImportHerbariumImagesCliIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    private string $sourceDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/dryherbarium-cli-test-'.bin2hex(random_bytes(8));
        $this->sourceDirectory = $this->temporaryDirectory.'/source';

        (new Filesystem())->makeDirectory($this->sourceDirectory, 0755, true);
        (new Filesystem())->makeDirectory($this->temporaryDirectory.'/storage/logs', 0755, true);
        $this->app->useStoragePath($this->temporaryDirectory.'/storage');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_cli_reports_success_fallback_failure_and_checksum_duplicates_independently(): void
    {
        $this->herbarium('100');
        $this->herbarium('200');
        $this->herbarium('F 00300');

        file_put_contents($this->sourceDirectory.'/100.jpg', 'renamed text');
        $this->writeJpeg($this->sourceDirectory.'/200.jpg');
        $this->writePng($this->sourceDirectory.'/300.png');
        $this->writeJpeg($this->sourceDirectory.'/999.jpg');

        $this->artisan('herbarium:import-images', ['path' => $this->sourceDirectory])
            ->expectsOutputToContain('Failed to import: 100.jpg')
            ->expectsOutputToContain('Imported: 200.jpg')
            ->expectsOutputToContain('Updated and imported: 300.png')
            ->expectsOutputToContain('No match for: 999.jpg')
            ->assertExitCode(0);

        $this->assertSame(2, HerbariumImages::count());
        $this->assertSame(2, Activity::count());
        $this->assertCount(2, Storage::disk('public')->allFiles('herbarium'));
        $this->assertTrue(Activity::query()->whereNotNull('causer_id')->doesntExist());
        $this->assertSame(
            ['cli'],
            Activity::all()->pluck('properties')->map->get('import_source')->unique()->values()->all(),
        );
        $this->assertSame(
            ['automatic'],
            Activity::all()->pluck('properties')->map->get('assignment')->unique()->values()->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['exact', 'f_fallback'],
            Activity::all()->pluck('properties')->map->get('match_type')->all(),
        );

        $this->artisan('herbarium:import-images', ['path' => $this->sourceDirectory])
            ->expectsOutputToContain('Failed to import: 100.jpg')
            ->expectsOutputToContain('Already imported: 200.jpg')
            ->expectsOutputToContain('Already imported: 300.png')
            ->expectsOutputToContain('No match for: 999.jpg')
            ->assertExitCode(0);

        $this->assertSame(2, HerbariumImages::count());
        $this->assertSame(2, Activity::count());
        $this->assertCount(2, Storage::disk('public')->allFiles('herbarium'));
    }

    public function test_cli_preserves_legacy_filename_duplicate_behavior(): void
    {
        $herbarium = $this->herbarium('400');
        $this->writeJpeg($this->sourceDirectory.'/400.jpg');
        Storage::disk('public')->put('herbarium/400.jpg', 'legacy stored contents');

        HerbariumImages::create([
            'herbarium_id' => $herbarium->id,
            'genus_id' => $herbarium->genus_id,
            'filename' => '400.jpg',
            'original_filename' => null,
            'checksum' => null,
        ]);

        $this->artisan('herbarium:import-images', ['path' => $this->sourceDirectory])
            ->expectsOutputToContain('Already imported: 400.jpg')
            ->assertExitCode(0);

        $this->assertSame(1, HerbariumImages::count());
        $this->assertSame('legacy stored contents', Storage::disk('public')->get('herbarium/400.jpg'));
        $this->assertSame(0, Activity::count());
    }

    private function herbarium(string $collectionNumber): Herbarium
    {
        $family = Family::create(['family' => 'Family '.$collectionNumber]);
        $genus = Genus::create(['name' => 'Genus '.$collectionNumber]);

        return Herbarium::create([
            'family_id' => $family->id,
            'genus_id' => $genus->id,
            'collection_number' => $collectionNumber,
        ]);
    }

    private function writeJpeg(string $path): void
    {
        $image = imagecreatetruecolor(4, 4);
        imagejpeg($image, $path);
        imagedestroy($image);
    }

    private function writePng(string $path): void
    {
        $image = imagecreatetruecolor(4, 4);
        imagepng($image, $path);
        imagedestroy($image);
    }
}
