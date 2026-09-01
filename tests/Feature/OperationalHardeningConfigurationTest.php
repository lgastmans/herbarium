<?php

namespace Tests\Feature;

use App\Services\HerbariumImageStorage\HerbariumImageStorageService;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OperationalHardeningConfigurationTest extends TestCase
{
    public function test_livewire_applies_the_exact_application_upload_limit_without_a_global_mime_rule(): void
    {
        $rules = config('livewire.temporary_file_upload.rules');

        $this->assertSame(['required', 'file', 'max:5120'], $rules);
        $this->assertSame(5 * 1024 * 1024, HerbariumImageStorageService::MAX_FILE_SIZE);
        $this->assertStringNotContainsString('mimes:', implode('|', $rules));
        $this->assertStringNotContainsString('mimetypes:', implode('|', $rules));
    }

    public function test_php_and_nginx_transport_limits_leave_bounded_multipart_headroom(): void
    {
        $iniPath = base_path('docker/php/uploads.ini');
        $settings = parse_ini_file($iniPath);

        $this->assertIsArray($settings);
        $uploadBytes = $this->iniBytes((string) $settings['upload_max_filesize']);
        $postBytes = $this->iniBytes((string) $settings['post_max_size']);
        $applicationBytes = HerbariumImageStorageService::MAX_FILE_SIZE;

        $this->assertGreaterThan($applicationBytes, $uploadBytes);
        $this->assertGreaterThan($uploadBytes, $postBytes);
        $this->assertLessThanOrEqual(10 * 1024 * 1024, $postBytes);

        $dockerfile = file_get_contents(base_path('Dockerfile'));
        $this->assertIsString($dockerfile);
        $this->assertStringContainsString(
            'COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/zz-dryherbarium-uploads.ini',
            $dockerfile,
        );

        $nginx = file_get_contents(base_path('docker/nginx/default.conf'));
        $this->assertIsString($nginx);
        $this->assertMatchesRegularExpression('/client_max_body_size\s+7m;/i', $nginx);
        $this->assertGreaterThan($applicationBytes, 7 * 1024 * 1024);
    }

    public function test_docker_php_build_enables_jpeg_support_in_gd(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString('libjpeg62-turbo-dev', $dockerfile);
        $this->assertMatchesRegularExpression(
            '/docker-php-ext-configure\s+gd\s+--with-jpeg/',
            $dockerfile,
        );
        $this->assertMatchesRegularExpression(
            '/docker-php-ext-install[^\n]*\bgd\b/',
            $dockerfile,
        );
    }

    public function test_checked_in_compose_file_defines_only_an_isolated_guarded_mysql_database(): void
    {
        $compose = Yaml::parseFile(base_path('docker-compose.testing.yml'));

        $this->assertSame(['mysql-testing'], array_keys($compose['services']));

        $mysql = $compose['services']['mysql-testing'];
        $this->assertSame('mysql:8.0', $mysql['image']);
        $this->assertSame('dryherbarium_testing', $mysql['environment']['MYSQL_DATABASE']);
        $this->assertSame('root', $mysql['environment']['MYSQL_ROOT_PASSWORD']);
        $this->assertSame('%', $mysql['environment']['MYSQL_ROOT_HOST']);
        $this->assertContains('127.0.0.1:3308:3306', $mysql['ports']);
        $this->assertContains('dryherbarium-testing-db:/var/lib/mysql', $mysql['volumes']);
        $this->assertSame(20, $mysql['healthcheck']['retries']);
        $this->assertArrayHasKey('dryherbarium-testing-db', $compose['volumes']);

        $phpunit = file_get_contents(base_path('phpunit.xml'));
        $this->assertIsString($phpunit);
        $this->assertStringContainsString('name="DB_PORT" value="3308"', $phpunit);
        $this->assertStringContainsString('name="DB_DATABASE" value="dryherbarium_testing"', $phpunit);
        $this->assertStringContainsString('name="DB_USERNAME" value="root"', $phpunit);
        $this->assertStringContainsString('name="DB_PASSWORD" value="root"', $phpunit);
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
