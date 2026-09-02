<?php

namespace App\Support;

use InvalidArgumentException;

class TrustedProxyConfiguration
{
    /** @return array<int, string>|string */
    public static function parse(?string $value): array|string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        if ($value === '*' || $value === '**') {
            return $value;
        }

        $proxies = array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $proxy): bool => $proxy !== '',
        ));

        if (in_array('*', $proxies, true) || in_array('**', $proxies, true)) {
            throw new InvalidArgumentException(
                'TRUSTED_PROXIES wildcards must be used alone, not mixed with IP addresses or CIDRs.'
            );
        }

        return $proxies;
    }
}
