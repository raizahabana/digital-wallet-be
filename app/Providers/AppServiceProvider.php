<?php

namespace App\Providers;

use App\Support\Problem;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;

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
        // 5 login attempts per minute for each email + IP pair (slows password guessing).
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)
                ->by(Str::lower((string) $request->input('email')).'|'.$request->ip())
                ->response(fn (Request $request, array $headers) => Problem::response(
                    429,
                    'Too many requests',
                    'Too many login attempts. Please wait a minute before trying again.'
                )->withHeaders($headers));
        });

        // Maximum 5 transfer attempts per user per minute.
        RateLimiter::for('transfers', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => Problem::response(
                    429,
                    'Too many requests',
                    'Transfer limit reached (5 per minute). Please wait before trying again.'
                )->withHeaders($headers));
        });
    }
}