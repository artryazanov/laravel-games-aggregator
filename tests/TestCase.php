<?php

namespace Artryazanov\GogScanner\Tests;

use Artryazanov\GogScanner\GamesAggregatorServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [GamesAggregatorServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * Define database migrations.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
