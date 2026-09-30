<?php

namespace lhaamed\MediaModule;

use Illuminate\Support\ServiceProvider;
use lhaamed\MediaModule\Console\UpgradeMediaCommand;
use lhaamed\MediaModule\Services\MediaService;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/media.php', 'media');

        $this->app->singleton(MediaService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/media.php' => config_path('media.php'),
            ], 'media-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'media-migrations');

            $this->commands([UpgradeMediaCommand::class]);

            $this->publishes([
                __DIR__ . '/../resources/file-icons' => public_path('vendor/media/placeholders'),
            ], 'media-assets');

            $this->publishes([
                __DIR__ . '/../stubs/Media.stub' => app_path('Models/Media.php'),
                __DIR__ . '/../stubs/MediaThumbnail.stub' => app_path('Models/MediaThumbnail.php'),
            ], 'media-models');

        }
    }
}
