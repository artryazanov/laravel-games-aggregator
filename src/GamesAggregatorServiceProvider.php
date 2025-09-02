<?php

namespace Artryazanov\GamesAggregator;

use Illuminate\Support\ServiceProvider;

class GamesAggregatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config so users can override values in their app config
        $this->mergeConfigFrom(__DIR__.'/../config/games-aggregator.php', 'games-aggregator');
    }

    public function boot(): void
    {
        // Publishable configuration
        $this->publishes([
            __DIR__.'/../config/games-aggregator.php' => config_path('games-aggregator.php'),
        ], 'config');

        // Load package migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Artryazanov\GamesAggregator\Console\AggregateGamesCommand::class,
            ]);
        }
    }
}
