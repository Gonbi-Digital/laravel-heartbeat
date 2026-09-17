<?php

namespace GonbiDigital\Heartbeat\Tests;

use GonbiDigital\Heartbeat\HeartbeatServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [HeartbeatServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // The ops page runs the `web` group, which encrypts cookies and so needs a key.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('queue.default', 'sync');

        // The ops page is behind `auth` by default, which needs somewhere to authenticate against.
        $app['config']->set('auth.providers.users.driver', 'array');
    }
}
