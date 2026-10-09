<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class Locale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->route('locale') ?? session('locale', 'nl');
        abort_unless(in_array($locale, config('revilo.locales'), true), 404);
        app()->setLocale($locale);
        session(['locale' => $locale]);
        URL::defaults(['locale' => $locale]);

        return $next($request);
    }
}
