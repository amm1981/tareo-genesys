<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api-login', function (Request $request) {
            $username = $request->json('usuario');
            $username = is_string($username) ? Str::lower($username) : '';

            return [
                Limit::perMinute(5)->by('login-account:'.hash('sha256', $username.'|'.$request->ip())),
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('api-query', fn (Request $request) => Limit::perMinute(120)->by('query-user:'.$request->user()->id));
    }
}
