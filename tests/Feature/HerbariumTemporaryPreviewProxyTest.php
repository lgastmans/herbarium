<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

class HerbariumTemporaryPreviewProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_immediate_internal_proxy_generates_and_accepts_an_https_preview_signature(): void
    {
        config([
            'app.url' => 'http://internal.test',
            'trustedproxy.proxies' => ['172.30.0.0/16'],
        ]);

        Route::middleware('web')->get('/_testing/livewire-preview-url', function () {
            $filename = 'proxy-preview.png';
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true,
            );
            FileUploadConfiguration::storage()->put(
                FileUploadConfiguration::path($filename),
                $png,
            );

            $file = new TemporaryUploadedFile($filename, FileUploadConfiguration::disk());

            return response()->json(['preview_url' => $file->temporaryUrl()]);
        });

        $this->asRequestFromImmediateInternalProxy('172.30.0.12');

        $response = $this->get('/_testing/livewire-preview-url')->assertOk();
        $previewUrl = $response->json('preview_url');
        $this->assertIsString($previewUrl);
        $parts = parse_url($previewUrl);

        $this->assertSame('https', $parts['scheme'] ?? null);
        $this->assertSame('herbarium.example.test', $parts['host'] ?? null);
        $this->assertSame('/livewire/preview-file/proxy-preview.png', $parts['path'] ?? null);
        $this->assertArrayHasKey('query', $parts);

        $this->get(($parts['path'] ?? '').'?'.($parts['query'] ?? ''))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->get($parts['path'] ?? '')
            ->assertUnauthorized();
    }

    public function test_empty_proxy_configuration_ignores_forwarded_scheme_and_host(): void
    {
        $this->assertSame([], config('trustedproxy.proxies'));
        config(['app.url' => 'http://internal.test']);

        $this->registerRequestSecurityRoute();

        $response = $this->asRequestFromImmediateInternalProxy('172.30.0.12')
            ->get('/_testing/request-security')
            ->assertOk();

        $this->assertFalse($response->json('secure'));
        $this->assertNotSame('herbarium.example.test', $response->json('host'));
    }

    public function test_caller_outside_the_configured_internal_proxy_cidr_cannot_forge_origin(): void
    {
        config([
            'app.url' => 'http://internal.test',
            'trustedproxy.proxies' => ['172.30.0.0/16'],
        ]);

        $this->registerRequestSecurityRoute();

        $response = $this->asRequestFromImmediateInternalProxy('172.31.0.12')
            ->get('/_testing/request-security')
            ->assertOk();

        $this->assertFalse($response->json('secure'));
        $this->assertNotSame('herbarium.example.test', $response->json('host'));
    }

    public function test_wildcard_trust_is_resolved_to_only_the_immediate_caller(): void
    {
        config([
            'app.url' => 'http://internal.test',
            'trustedproxy.proxies' => '*',
        ]);

        $this->registerRequestSecurityRoute();

        $response = $this->asRequestFromImmediateInternalProxy('172.30.0.99')
            ->get('/_testing/request-security')
            ->assertOk();

        $this->assertTrue($response->json('secure'));
        $this->assertSame('herbarium.example.test', $response->json('host'));
        $this->assertSame(['172.30.0.99'], $response->json('trusted_proxies'));
    }

    private function registerRequestSecurityRoute(): void
    {
        Route::middleware('web')->get('/_testing/request-security', fn () => [
            'secure' => request()->secure(),
            'host' => request()->getHost(),
            'trusted_proxies' => SymfonyRequest::getTrustedProxies(),
        ]);
    }

    private function asRequestFromImmediateInternalProxy(string $address): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $address])
            ->withHeaders([
                'Host' => 'internal.test',
                'X-Forwarded-Host' => 'herbarium.example.test',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-Proto' => 'https',
            ]);
    }
}
