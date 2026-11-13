<?php

return [

    /*
    | When false, the public site is only visible to the logged-in accounts listed in
    | PUBLIC_SITE_PREVIEW_EMAILS (comma separated); everyone else is redirected to /admin.
    | Set PUBLIC_SITE_ENABLED=true to launch.
    */
    'public_site_enabled' => (bool) env('PUBLIC_SITE_ENABLED', false),

    'public_site_preview_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('PUBLIC_SITE_PREVIEW_EMAILS', '')),
    ))),

];
