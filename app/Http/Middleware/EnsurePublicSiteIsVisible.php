<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePublicSiteIsVisible
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('herbarium.public_site_enabled')) {
            return $next($request);
        }

        // While the public site is unreleased, only the allow-listed accounts can preview it.
        $email = $request->user()?->email;

        if ($email && in_array(strtolower($email), config('herbarium.public_site_preview_emails'), true)) {
            return $next($request);
        }

        return redirect('/admin');
    }
}
