<?php

namespace lhaamed\MediaModule;


use lhaamed\MediaModule\Services\MediaService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    /**
     * Register any application Services.
     */
    public function register(): void
    {

        $this->mergeConfigFrom(__DIR__ . '/../config/media.php','media');

        $this->app->singleton(MediaService::class, function (Application $app) {
            return new MediaService();
        });
    }

    /**
     * Bootstrap any application Services.
     */
    public function boot(): void
    {

        $this->publishes([
            __DIR__ . '/../config/media.php' => config_path('media.php'),
        ],['media','config','media-config']);


        // Migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'MediaModule-migrations');


        // Service
        $this->publishes([
            __DIR__ . '/../src/Services/MediaService.php' => app_path('Services/MediaService.php'),
        ], 'MediaModule-Services');

        // Provider
        $this->publishes([
            __DIR__ . '/MediaServiceProvider.php' => app_path('Providers/MediaServiceProvider.php'),
        ], 'MediaModule-provider');


        // Models
        $this->publishes([
            __DIR__ . '/Models/' => app_path('Models/MediaModule'),
        ], 'MediaModule-Models');


        // Traits
        $this->publishes([
            __DIR__ . '/Traits/' => app_path('Traits/MediaModule'),
        ], 'MediaModule-traits');

    }
}
