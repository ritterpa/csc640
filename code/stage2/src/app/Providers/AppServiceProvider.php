<?php

namespace App\Providers;

use App\Support\JwtTokens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One token service for the app, configured from config/jwt.php (.env).
        // Controllers receive it as a parameter; the jwt guard below uses it too.
        $this->app->singleton(JwtTokens::class, fn () => new JwtTokens(
            (string) config('jwt.secret', ''),
            (int) config('jwt.ttl', 3600),
        ));
    }

    public function boot(): void
    {
        // A "jwt" auth guard for the bearer tokens, used by routes with
        // ->middleware('auth:jwt'). A missing or invalid token makes the
        // callback return null, and Laravel answers 401.
        config(['auth.guards.jwt' => ['driver' => 'jwt']]);
        Auth::viaRequest('jwt', fn (Request $request) => $this->app->make(JwtTokens::class)->userFromRequest($request));
    }
}
