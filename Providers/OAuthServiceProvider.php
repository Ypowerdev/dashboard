<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SudirOAuthService;
use Illuminate\Support\ServiceProvider;

class OAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SudirOAuthService::class, function () {
            return new SudirOAuthService(config('auth.sudir', []));
        });
    }
}
