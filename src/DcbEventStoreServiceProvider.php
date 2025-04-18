<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate;

use Illuminate\Support\ServiceProvider;

final class DcbEventStoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/dcb_event_store.php', 'dcb_event_store');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/dcb_event_store.php' => config_path('dcb_event_store.php')], 'config');
        $this->publishesMigrations([__DIR__ . '/../database/migrations' => database_path('migrations')], 'migrations');
    }
}
