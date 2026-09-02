<?php

namespace Tests\Unit;

use App\Support\TrustedProxyConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    #[DataProvider('emptyValues')]
    public function test_empty_configuration_trusts_no_proxies(?string $value): void
    {
        $this->assertSame([], TrustedProxyConfiguration::parse($value));
    }

    public static function emptyValues(): array
    {
        return [[null], [''], ['   ']];
    }

    #[DataProvider('wildcards')]
    public function test_an_intentional_wildcard_remains_a_string(string $value, string $expected): void
    {
        $this->assertSame($expected, TrustedProxyConfiguration::parse($value));
    }

    public static function wildcards(): array
    {
        return [
            ['*', '*'],
            [' ** ', '**'],
        ];
    }

    public function test_comma_separated_ips_and_cidrs_are_trimmed_and_normalized(): void
    {
        $this->assertSame(
            ['172.30.0.0/16', '10.20.30.40', '2001:db8::/64'],
            TrustedProxyConfiguration::parse(' 172.30.0.0/16, 10.20.30.40, ,2001:db8::/64 '),
        );
    }

    #[DataProvider('mixedWildcards')]
    public function test_wildcards_cannot_be_mixed_with_an_address_list(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wildcards must be used alone');

        TrustedProxyConfiguration::parse($value);
    }

    public static function mixedWildcards(): array
    {
        return [
            ['172.30.0.0/16,*'],
            ['**,10.20.30.40'],
        ];
    }
}
