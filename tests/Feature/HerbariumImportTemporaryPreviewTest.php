<?php

namespace Tests\Feature;

use App\Livewire\ImportHerbariumImages;
use App\Models\HerbariumImages;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class HerbariumImportTemporaryPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tmp-for-tests');
        Storage::fake('public');
    }

    public function test_batch_importer_renders_an_extensionless_signed_preview_url_without_persisting_it(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $component = Livewire::test(ImportHerbariumImages::class)
            ->set('incomingFile', UploadedFile::fake()->image('13536.jpg', 8, 8))
            ->call('stageIncomingUpload');

        $rows = $component->get('stagedImages');
        $rowKey = array_key_first($rows);
        $temporaryFile = $rows[$rowKey]['temporary_file'];
        $viewData = $component->instance()->render()->getData();
        $previewUrl = $viewData['previewUrls'][$rowKey] ?? null;

        $this->assertIsString($previewUrl);
        $parts = parse_url($previewUrl);
        parse_str((string) ($parts['query'] ?? ''), $query);

        $this->assertSame('/herbarium/images/import-preview/'.$rowKey, $parts['path'] ?? null);
        $this->assertDoesNotMatchRegularExpression('/\.(?:jpe?g|png)\z/i', (string) ($parts['path'] ?? ''));
        $this->assertStringNotContainsString($temporaryFile->getFilename(), (string) ($parts['path'] ?? ''));
        $this->assertSame($temporaryFile->getFilename(), $query['filename'] ?? null);
        $this->assertArrayHasKey('signature', $query);
        $this->assertArrayHasKey('expires', $query);
        $this->assertArrayNotHasKey('preview_url', $rows[$rowKey]);

        $component->assertSee('/herbarium/images/import-preview/'.$rowKey, escape: false);
    }

    public function test_valid_jpeg_and_png_previews_are_streamed_privately_without_side_effects(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        foreach ([
            'jpeg' => ['specimen.jpg', $this->imageBytes('jpeg'), 'image/jpeg'],
            'png' => ['specimen.png', $this->imageBytes('png'), 'image/png'],
        ] as [$originalName, $bytes, $mimeType]) {
            [$filename, $path] = $this->putTemporaryFile($originalName, $bytes);
            $url = $this->signedPreviewUrl($filename);

            $response = $this->get($url)
                ->assertOk()
                ->assertHeader('Content-Type', $mimeType)
                ->assertHeader('Content-Disposition', 'inline')
                ->assertHeader('X-Content-Type-Options', 'nosniff');

            $cacheControl = (string) $response->headers->get('Cache-Control');
            $this->assertStringContainsString('private', $cacheControl);
            $this->assertStringContainsString('no-store', $cacheControl);
            $this->assertSame($bytes, $response->streamedContent());
            $this->assertTrue(FileUploadConfiguration::storage()->exists($path));
        }

        $this->assertSame(0, HerbariumImages::count());
        $this->assertSame(0, Activity::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_unsigned_tampered_and_expired_preview_requests_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$filename] = $this->putTemporaryFile('specimen.png', $this->imageBytes('png'));
        $token = (string) Str::uuid();

        $this->get(route('herbarium.images.import.preview', [
            'token' => $token,
            'filename' => $filename,
        ]))->assertForbidden();

        $signedUrl = $this->signedPreviewUrl($filename, $token);
        $this->get($this->replaceQueryValue($signedUrl, 'filename', 'different.png'))
            ->assertForbidden();
        $this->get(str_replace($token, (string) Str::uuid(), $signedUrl))
            ->assertForbidden();

        Carbon::setTestNow(now());
        $expiredUrl = $this->signedPreviewUrl($filename, expiresAt: now()->addSecond());
        $this->travel(2)->seconds();
        $this->get($expiredUrl)->assertForbidden();
        $this->travelBack();
    }

    public function test_preview_endpoint_rejects_guests_unverified_users_and_non_administrators(): void
    {
        [$filename] = $this->putTemporaryFile('specimen.png', $this->imageBytes('png'));
        $url = $this->signedPreviewUrl($filename);

        $this->get($url)->assertRedirect(route('login'));

        $this->actingAs(User::factory()->admin()->unverified()->create())
            ->get($url)
            ->assertRedirect(route('verification.notice'));

        $this->actingAs(User::factory()->create())
            ->get($url)
            ->assertForbidden();
    }

    public function test_missing_and_unsafe_temporary_filenames_return_generic_failures(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $missing = $this->temporaryFilename('missing.png');
        $this->get($this->signedPreviewUrl($missing))
            ->assertNotFound()
            ->assertDontSee('livewire-tmp');

        foreach ([
            null,
            '',
            ['nested.png'],
            '../specimen.png',
            'nested/specimen.png',
            'nested\\specimen.png',
            'specimen..png',
            "specimen\0.png",
            'not a livewire file.png',
        ] as $unsafeFilename) {
            $this->get($this->signedPreviewUrl($unsafeFilename))
                ->assertNotFound()
                ->assertDontSee('livewire-tmp');
        }
    }

    public function test_invalid_corrupt_and_unsupported_image_contents_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $invalidFiles = [
            ['renamed-text.jpg', 'plain text'],
            ['corrupt.png', "\x89PNG\r\n\x1a\ncorrupt"],
            ['unsupported.gif', $this->imageBytes('gif')],
            ['unsupported.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>'],
        ];

        foreach ($invalidFiles as [$originalName, $contents]) {
            [$filename, $path] = $this->putTemporaryFile($originalName, $contents);

            $this->get($this->signedPreviewUrl($filename))
                ->assertNotFound()
                ->assertDontSee('livewire-tmp');

            $this->assertTrue(FileUploadConfiguration::storage()->exists($path));
        }

        $this->assertSame(0, HerbariumImages::count());
        $this->assertSame(0, Activity::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_route_is_constrained_and_standard_livewire_preview_route_is_unchanged(): void
    {
        $route = Route::getRoutes()->getByName('herbarium.images.import.preview');
        $livewireRoute = Route::getRoutes()->getByName('livewire.preview-file');

        $this->assertNotNull($route);
        $this->assertSame('herbarium/images/import-preview/{token}', $route->uri());
        $this->assertSame(
            ['web', 'auth', 'verified', 'admin', 'signed', 'throttle:240,1'],
            $route->gatherMiddleware(),
        );
        $this->assertDoesNotMatchRegularExpression(
            '#'.$route->wheres['token'].'#',
            'not-opaque.jpg',
        );

        $this->assertNotNull($livewireRoute);
        $this->assertSame('livewire/preview-file/{filename}', $livewireRoute->uri());
        $this->assertSame(
            'Livewire\\Features\\SupportFileUploads\\FilePreviewController@handle',
            $livewireRoute->getActionName(),
        );
    }

    /** @return array{string, string} */
    private function putTemporaryFile(string $originalName, string $contents): array
    {
        $filename = $this->temporaryFilename($originalName);
        $path = FileUploadConfiguration::path($filename);
        FileUploadConfiguration::storage()->put($path, $contents);

        return [$filename, $path];
    }

    private function temporaryFilename(string $originalName): string
    {
        return TemporaryUploadedFile::generateHashNameWithOriginalNameEmbedded(
            UploadedFile::fake()->createWithContent($originalName, 'temporary fixture'),
        );
    }

    private function signedPreviewUrl(
        mixed $filename,
        ?string $token = null,
        mixed $expiresAt = null,
    ): string {
        return URL::temporarySignedRoute(
            'herbarium.images.import.preview',
            $expiresAt ?? now()->addMinutes(5),
            [
                'token' => $token ?? (string) Str::uuid(),
                'filename' => $filename,
            ],
        );
    }

    private function replaceQueryValue(string $url, string $key, string $value): string
    {
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query[$key] = $value;

        return (string) ($parts['path'] ?? '').'?'.http_build_query($query);
    }

    private function imageBytes(string $format): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();

        match ($format) {
            'jpeg' => imagejpeg($image),
            'png' => imagepng($image),
            'gif' => imagegif($image),
        };

        $contents = ob_get_clean();
        imagedestroy($image);

        $this->assertIsString($contents);

        return $contents;
    }
}
