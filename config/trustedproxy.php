<?php

use App\Support\TrustedProxyConfiguration;

return [
    /*
    | Only addresses/CIDRs listed here may supply forwarded scheme and host
    | information. Signed Livewire preview URLs depend on those values.
    */
    'proxies' => TrustedProxyConfiguration::parse(env('TRUSTED_PROXIES')),
];
