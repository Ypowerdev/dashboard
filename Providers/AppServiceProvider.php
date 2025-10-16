<?php

namespace App\Providers;

use App\Exceptions\Handler;
use App\Repositories\Interfaces\ObjectModelRepositoryInterface;
use App\Repositories\ObjectModelRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

use Filament\Forms\Components\Field;
use Filament\Tables\Columns\Column;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            ObjectModelRepositoryInterface::class,
            ObjectModelRepository::class
        );
        $this->app->register(\Filament\FilamentServiceProvider::class);
        $this->app->register(\App\Providers\Filament\AdminPanelProvider::class);
        $this->app->singleton(ExceptionHandler::class, Handler::class);

        // ObjectModel::observe(ObjectModelObserver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Для форм
        Field::macro('autoLabel', function () {
            return $this->label(__("objects.fields.{$this->getName()}"));
        });

        Field::macro('autoHint', function () {
            return $this->hint(__("objects.hints.{$this->getName()}"));
        });

        // Для таблиц
        Column::macro('autoLabel', function () {
            return $this->label(__("objects.fields.{$this->getName()}"));
        });

        // Глобальное ограничение для всех запросов: 50 запросов в минуту с одного IP
        RateLimiter::for('global', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // Проверяем, что app.url начинается с https://
        if (str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
