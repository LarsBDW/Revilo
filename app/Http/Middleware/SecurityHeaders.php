<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (config('revilo.public_preview')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        if ($request->is('admin*') || $request->is('*/account*') || $request->is('*/orders*') || $request->is('auth/google*') || $request->is('*/login') || $request->is('*/register*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        if ($request->is('auth/google*')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
