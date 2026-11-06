<?php

namespace Artryazanov\GamesAggregator\Tests;

use Artryazanov\GamesAggregator\GamesAggregatorServiceProvider;
use Artryazanov\GogScanner\GogScannerServiceProvider;
use Artryazanov\LaravelSteamAppsDb\LaravelSteamAppsDbServiceProvider;
use Artryazanov\PCGamingWiki\PCGamingWikiServiceProvider;
use Artryazanov\WikipediaGamesDb\WikipediaGamesDbServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            GamesAggregatorServiceProvider::class,
            GogScannerServiceProvider::class,
            LaravelSteamAppsDbServiceProvider::class,
            WikipediaGamesDbServiceProvider::class,
            PCGamingWikiServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
