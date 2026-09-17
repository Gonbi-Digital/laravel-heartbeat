<?php

namespace GonbiDigital\Heartbeat;

use GonbiDigital\Heartbeat\Checks\CacheCheck;
use GonbiDigital\Heartbeat\Checks\Check;
use GonbiDigital\Heartbeat\Checks\DatabaseCheck;
use GonbiDigital\Heartbeat\Checks\QueueCheck;
use GonbiDigital\Heartbeat\Checks\StorageCheck;
use GonbiDigital\Heartbeat\Http\EnsureHeartbeatAccess;
use GonbiDigital\Heartbeat\Http\HealthController;
use GonbiDigital\Heartbeat\Http\HeartbeatController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HeartbeatServiceProvider extends ServiceProvider
{
    /** The checks that ship with the package, in the order they appear on the page. */
    private const DEFAULT_CHECKS = [
        'database' => DatabaseCheck::class,
        'cache' => CacheCheck::class,
        'storage' => StorageCheck::class,
        'queue' => QueueCheck::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/heartbeat.php', 'heartbeat');

        /*
         * Bound fresh on every resolve, never shared. A report caches its own results so the page
         * and its status code cannot disagree within one request — but a singleton would carry
         * that cache across requests in Octane and serve yesterday's answer forever.
         */
        $this->app->bind(HeartbeatReport::class, fn (): HeartbeatReport => new HeartbeatReport($this->checks()));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'heartbeat');

        $this->publishes([
            __DIR__.'/../config/heartbeat.php' => config_path('heartbeat.php'),
        ], 'heartbeat-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/heartbeat'),
        ], 'heartbeat-views');

        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        // Routes cached by `optimize` are already compiled; registering again would be ignored at
        // best and would throw on a duplicate name at worst.
        if ($this->app->routesAreCached()) {
            return;
        }

        if (config('heartbeat.api.enabled', true)) {
            Route::get((string) config('heartbeat.api.path', 'health'), HealthController::class)
                ->middleware((array) config('heartbeat.api.middleware', []))
                ->name('heartbeat.health');
        }

        if (config('heartbeat.web.enabled', true)) {
            Route::get((string) config('heartbeat.web.path', 'health/ops'), HeartbeatController::class)
                ->middleware((array) config('heartbeat.web.middleware', ['web', EnsureHeartbeatAccess::class]))
                ->name('heartbeat.page');
        }
    }

    /** @return array<int, Check> */
    private function checks(): array
    {
        $checks = [];

        foreach (self::DEFAULT_CHECKS as $name => $class) {
            if (config('heartbeat.checks.'.$name, true)) {
                $checks[] = $this->app->make($class);
            }
        }

        return $checks;
    }
}
