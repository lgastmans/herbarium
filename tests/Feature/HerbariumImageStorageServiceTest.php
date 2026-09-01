<?php

namespace Tests\Feature;

use App\Exceptions\HerbariumImageImportException;
use App\Models\Family;
use App\Models\Genus;
use App\Models\Herbarium;
use App\Models\HerbariumImages;
use App\Models\User;
use App\Services\HerbariumImageMatching\HerbariumImageMatchType;
use App\Services\HerbariumImageStorage\HerbariumImageActivityLogger;
use App\Services\HerbariumImageStorage\HerbariumImageAssignmentType;
use App\Services\HerbariumImageStorage\HerbariumImageImportSource;
use App\Services\HerbariumImageStorage\HerbariumImageStorageService;
use App\Services\HerbariumImageStorage\HerbariumImageStorageStatus;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\File\File;
use Tests\TestCase;

class HerbariumImageStorageServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    private HerbariumImageStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->temporaryDirectory = sys_get_temp_dir().'/dryherbarium-storage-test-'.bin2hex(random_bytes(8));
        (new Filesystem())->makeDirectory($this->temporaryDirectory, 0755, true);
        $this->service = $this->app->make(HerbariumImageStorageService::class);
    }

    protected function tearDown(): void
    {
        Str::createUuidsNormally();
        (new Filesystem())->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_imports_a_jpeg_and_derives_the_extension_from_content(): void
    {
        $herbarium = $this->herbarium('123', 11);
        $file = $this->jpeg('source-with-wrong-extension.png');

        $result = $this->service->import(
            $herbarium,
            $file,
            '123.png',
            HerbariumImageAssignmentType::Automatic,
            HerbariumImageImportSource::Cli,
            HerbariumImageMatchType::Exact,
        );

        $this->assertSame(HerbariumImageStorageStatus::Imported, $result->status);
        $this->assertStringEndsWith('.jpg', $result->image->filename);
        $this->assertSame('123.png', $result->image->original_filename);
        $this->assertSame(hash_file('sha256', $file->getPathname()), $result->image->checksum);
        $this->assertSame(11, $result->image->genus_id);
        Storage::disk('public')->assertExists('herbarium/'.$result->image->filename);
    }

    public function test_it_imports_a_png_and_derives_the_extension_from_content(): void
    {
        $herbarium = $this->herbarium('124', 12);
        $result = $this->service->import(
            $herbarium,
            $this->png('source.jpg'),
            '124.jpg',
            HerbariumImageAssignmentType::Manual,
            HerbariumImageImportSource::Batch,
        );

        $this->assertSame(HerbariumImageStorageStatus::Imported, $result->status);
        $this->assertStringEndsWith('.png', $result->image->filename);
        $this->assertSame(12, $result->image->genus_id);
    }

    public function test_it_rejects_unsupported_and_invalid_files(): void
    {
        $herbarium = $this->herbarium('125', 13);

        $invalidFiles = [
            'GIF' => $this->gif('125.jpg'),
            'SVG' => $this->plainFile('125.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            'renamed text' => $this->plainFile('125.jpg', 'not an image'),
            'empty file' => $this->plainFile('empty.png', ''),
            'oversized file' => $this->plainFile(
                'large.jpg',
                str_repeat('x', HerbariumImageStorageService::MAX_FILE_SIZE + 1),
            ),
        ];

        foreach ($invalidFiles as $label => $file) {
            try {
                $this->service->import(
                    $herbarium,
                    $file,
                    $file->getFilename(),
                    HerbariumImageAssignmentType::Automatic,
                    HerbariumImageImportSource::Cli,
                );

                $this->fail("{$label} should have been rejected.");
            } catch (HerbariumImageImportException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, HerbariumImages::count());
        $this->assertSame([], Storage::disk('public')->allFiles('herbarium'));
    }

    public function test_it_sanitizes_and_bounds_original_and_stored_filenames(): void
    {
        $herbarium = $this->herbarium('F 00126', 14);
        $original = "../folder\\\x00F 00126 <>".str_repeat('a', 400).'.jpeg';

        $result = $this->service->import(
            $herbarium,
            $this->jpeg('safe-source.jpg'),
            $original,
            HerbariumImageAssignmentType::Manual,
            HerbariumImageImportSource::SingleUploader,
        );

        $this->assertLessThanOrEqual(255, strlen($result->image->filename));
        $this->assertLessThanOrEqual(255, mb_strlen($result->image->original_filename));
        $this->assertStringNotContainsString('/', $result->image->original_filename);
        $this->assertStringNotContainsString('\\', $result->image->original_filename);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $result->image->original_filename);
        $this->assertStringNotContainsString('/', $result->image->filename);
        $this->assertStringNotContainsString('\\', $result->image->filename);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $result->image->filename);
        $this->assertStringEndsWith('.jpg', $result->image->filename);
    }

    public function test_it_does_not_overwrite_an_existing_generated_filename(): void
    {
        $herbarium = $this->herbarium('127', 15);
        $firstUuid = '00000000-0000-4000-8000-000000000001';
        $secondUuid = '00000000-0000-4000-8000-000000000002';
        Str::createUuidsUsingSequence([$firstUuid, $secondUuid]);

        $existingPath = 'herbarium/127-'.$firstUuid.'.jpg';
        Storage::disk('public')->put($existingPath, 'existing contents');

        $result = $this->service->import(
            $herbarium,
            $this->jpeg('127.jpg'),
            '127.jpg',
            HerbariumImageAssignmentType::Automatic,
            HerbariumImageImportSource::Cli,
        );

        $this->assertSame('existing contents', Storage::disk('public')->get($existingPath));
        $this->assertSame('127-'.$secondUuid.'.jpg', $result->image->filename);
        Storage::disk('public')->assertExists('herbarium/'.$result->image->filename);
    }

    public function test_duplicate_checksum_is_skipped_for_the_same_herbarium(): void
    {
        $herbarium = $this->herbarium('128', 16);
        $file = $this->png('128.png');

        $first = $this->service->import(
            $herbarium,
            $file,
            '128.png',
            HerbariumImageAssignmentType::Automatic,
            HerbariumImageImportSource::Cli,
        );
        $second = $this->service->import(
            $herbarium,
            $file,
            'renamed-128.png',
            HerbariumImageAssignmentType::Manual,
            HerbariumImageImportSource::Batch,
        );

        $this->assertSame(HerbariumImageStorageStatus::Imported, $first->status);
        $this->assertSame(HerbariumImageStorageStatus::Duplicate, $second->status);
        $this->assertSame($first->image->id, $second->image->id);
        $this->assertSame(1, HerbariumImages::count());
        $this->assertCount(1, Storage::disk('public')->allFiles('herbarium'));
        $this->assertSame(1, Activity::count());
    }

    public function test_same_checksum_is_allowed_for_different_herbaria(): void
    {
        $firstHerbarium = $this->herbarium('129', 17);
        $secondHerbarium = $this->herbarium('130', 18);
        $file = $this->jpeg('shared.jpg');

        $first = $this->service->import(
            $firstHerbarium,
            $file,
            '129.jpg',
            HerbariumImageAssignmentType::Automatic,
            HerbariumImageImportSource::Cli,
        );
        $second = $this->service->import(
            $secondHerbarium,
            $file,
            '130.jpg',
            HerbariumImageAssignmentType::Manual,
            HerbariumImageImportSource::Batch,
        );

        $this->assertSame(HerbariumImageStorageStatus::Imported, $first->status);
        $this->assertSame(HerbariumImageStorageStatus::Imported, $second->status);
        $this->assertSame(2, HerbariumImages::count());
        $this->assertNotSame($first->image->filename, $second->image->filename);
    }

    public function test_successful_import_logs_required_properties_and_causer(): void
    {
        $herbarium = $this->herbarium('F 00131', 19);
        $user = User::factory()->create();

        $result = $this->service->import(
            $herbarium,
            $this->png('131.jpg'),
            '../incoming/131.jpg',
            HerbariumImageAssignmentType::Automatic,
            HerbariumImageImportSource::Batch,
            HerbariumImageMatchType::FFallback,
            $user,
        );

        $activity = Activity::sole();
        $properties = $activity->properties;

        $this->assertSame($result->image->id, $activity->subject_id);
        $this->assertSame(HerbariumImages::class, $activity->subject_type);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame(User::class, $activity->causer_type);
        $this->assertSame('131.jpg', $properties->get('original_filename'));
        $this->assertSame($result->image->filename, $properties->get('stored_filename'));
        $this->assertSame('F 00131', $properties->get('collection_number'));
        $this->assertSame($result->checksum, $properties->get('checksum'));
        $this->assertSame('automatic', $properties->get('assignment'));
        $this->assertSame('batch', $properties->get('import_source'));
        $this->assertSame('f_fallback', $properties->get('match_type'));
    }

    public function test_stored_file_is_removed_when_record_creation_fails(): void
    {
        $unsavedHerbarium = new Herbarium([
            'collection_number' => '132',
            'genus_id' => 20,
        ]);

        try {
            $this->service->import(
                $unsavedHerbarium,
                $this->jpeg('132.jpg'),
                '132.jpg',
                HerbariumImageAssignmentType::Automatic,
                HerbariumImageImportSource::Cli,
            );

            $this->fail('The import should have failed.');
        } catch (HerbariumImageImportException $exception) {
            $this->assertSame('The image import could not be completed.', $exception->getMessage());
        }

        $this->assertSame(0, HerbariumImages::count());
        $this->assertSame([], Storage::disk('public')->allFiles('herbarium'));
    }

    public function test_stored_file_and_record_are_removed_when_activity_logging_fails(): void
    {
        $herbarium = $this->herbarium('133', 21);
        $logger = \Mockery::mock(HerbariumImageActivityLogger::class);
        $logger->shouldReceive('log')->once()->andThrow(new \RuntimeException('Activity failure'));
        $service = new HerbariumImageStorageService($logger);

        try {
            $service->import(
                $herbarium,
                $this->png('133.png'),
                '133.png',
                HerbariumImageAssignmentType::Manual,
                HerbariumImageImportSource::SingleUploader,
            );

            $this->fail('The import should have failed.');
        } catch (HerbariumImageImportException $exception) {
            $this->assertSame('The image import could not be completed.', $exception->getMessage());
        }

        $this->assertSame(0, HerbariumImages::count());
        $this->assertSame([], Storage::disk('public')->allFiles('herbarium'));
        $this->assertSame(0, Activity::count());
    }

    private function herbarium(string $collectionNumber, int $genusId): Herbarium
    {
        $family = Family::create(['family' => 'Family '.$collectionNumber]);
        $genus = Genus::create(['name' => 'Genus '.$collectionNumber]);
        $genus->setAttribute('id', $genusId);
        $genus->save();

        return Herbarium::create([
            'family_id' => $family->id,
            'genus_id' => $genus->id,
            'collection_number' => $collectionNumber,
        ]);
    }

    private function jpeg(string $filename): File
    {
        $path = $this->temporaryDirectory.'/'.$filename;
        $image = imagecreatetruecolor(4, 4);
        imagejpeg($image, $path);
        imagedestroy($image);

        return new File($path);
    }

    private function png(string $filename): File
    {
        $path = $this->temporaryDirectory.'/'.$filename;
        $image = imagecreatetruecolor(4, 4);
        imagepng($image, $path);
        imagedestroy($image);

        return new File($path);
    }

    private function gif(string $filename): File
    {
        $path = $this->temporaryDirectory.'/'.$filename;
        $image = imagecreatetruecolor(4, 4);
        imagegif($image, $path);
        imagedestroy($image);

        return new File($path);
    }

    private function plainFile(string $filename, string $contents): File
    {
        $path = $this->temporaryDirectory.'/'.$filename;
        file_put_contents($path, $contents);

        return new File($path);
    }
}
