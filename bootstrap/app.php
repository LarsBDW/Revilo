<?php

use App\Http\Middleware\Admin;
use App\Http\Middleware\Locale;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_PROTO);
        $middleware->alias(['locale' => Locale::class, 'admin' => Admin::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->redirectGuestsTo(fn () => route('login', ['locale' => session('locale', 'nl')]));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
