<?php

namespace Tests\Feature;

use PDO;
use Tests\TestCase;

class DatabasePersistentConnectionConfigurationTest extends TestCase
{
    public function test_persistent_mysql_connections_are_disabled_by_default(): void
    {
        $config = $this->loadDatabaseConfig([
            'APP_ENV' => 'production',
            'DB_PERSISTENT' => null,
        ]);

        $this->assertArrayNotHasKey(PDO::ATTR_PERSISTENT, $config['connections']['mysql']['options']);

        $example = file_get_contents(base_path('.env.example'));
        $this->assertIsString($example);
        $this->assertMatchesRegularExpression('/^DB_PERSISTENT=false$/m', $example);
    }

    public function test_true_enables_persistence_without_replacing_the_ssl_ca_option(): void
    {
        $config = $this->loadDatabaseConfig([
            'APP_ENV' => 'production',
            'DB_PERSISTENT' => 'true',
            'MYSQL_ATTR_SSL_CA' => '/secure/mysql-ca.pem',
        ]);
        $options = $config['connections']['mysql']['options'];

        $this->assertTrue($options[PDO::ATTR_PERSISTENT] ?? null);
        $this->assertSame('/secure/mysql-ca.pem', $options[PDO::MYSQL_ATTR_SSL_CA] ?? null);
    }

    public function test_false_like_and_invalid_values_do_not_enable_persistence(): void
    {
        foreach (['false', '(false)', '0', 'off', 'no', '', 'not-a-boolean'] as $value) {
            $config = $this->loadDatabaseConfig([
                'APP_ENV' => 'production',
                'DB_PERSISTENT' => $value,
            ]);

            $this->assertArrayNotHasKey(
                PDO::ATTR_PERSISTENT,
                $config['connections']['mysql']['options'],
                "DB_PERSISTENT={$value} must not enable persistent connections.",
            );
        }
    }

    public function test_testing_and_unrelated_connections_never_receive_the_persistent_option(): void
    {
        $config = $this->loadDatabaseConfig([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'dryherbarium_testing',
            'DB_PERSISTENT' => 'true',
        ]);

        $this->assertSame('mysql', $config['default']);
        $this->assertSame('dryherbarium_testing', $config['connections']['mysql']['database']);
        $this->assertArrayNotHasKey(PDO::ATTR_PERSISTENT, $config['connections']['mysql']['options']);
        $this->assertArrayNotHasKey('options', $config['connections']['sqlite']);
        $this->assertArrayNotHasKey('options', $config['connections']['pgsql']);

        $this->assertSame('dryherbarium_testing', config('database.connections.mysql.database'));
        $this->assertArrayNotHasKey(
            PDO::ATTR_PERSISTENT,
            config('database.connections.mysql.options', []),
        );
    }

    /**
     * @param  array<string, string|null>  $overrides
     * @return array<string, mixed>
     */
    private function loadDatabaseConfig(array $overrides): array
    {
        $originalEnvironment = [];
        $originalServer = [];

        foreach ($overrides as $key => $value) {
            $originalEnvironment[$key] = [array_key_exists($key, $_ENV), $_ENV[$key] ?? null];
            $originalServer[$key] = [array_key_exists($key, $_SERVER), $_SERVER[$key] ?? null];

            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        try {
            return require base_path('config/database.php');
        } finally {
            foreach ($overrides as $key => $_) {
                [$environmentExisted, $environmentValue] = $originalEnvironment[$key];
                [$serverExisted, $serverValue] = $originalServer[$key];

                if ($environmentExisted) {
                    $_ENV[$key] = $environmentValue;
                } else {
                    unset($_ENV[$key]);
                }

                if ($serverExisted) {
                    $_SERVER[$key] = $serverValue;
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }
}
