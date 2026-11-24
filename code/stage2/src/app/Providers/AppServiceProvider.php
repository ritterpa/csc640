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
        //
    }

    public function boot(): void
    {
        // A "jwt" auth guard for the bearer tokens, used by routes with
        // ->middleware('auth:jwt'). A missing or invalid token makes the
        // callback return null, and Laravel answers 401.
        config(['auth.guards.jwt' => ['driver' => 'jwt']]);
        Auth::viaRequest('jwt', fn (Request $request) => JwtTokens::userFromRequest($request));
    }
}
