<?php

declare(strict_types=1);

namespace Artryazanov\GamesAggregator;

use Artryazanov\GamesAggregator\Console\AggregateGamesCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Сервис-провайдер пакета агрегации игр из внешних источников.
 */
class GamesAggregatorServiceProvider extends ServiceProvider
{
    /**
     * Регистрирует сервисы и объединяет конфигурацию пакета.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/games-aggregator.php', 'games-aggregator');
    }

    /**
     * Загружает миграции, публикует конфигурацию и регистрирует консольные команды.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/games-aggregator.php' => config_path('games-aggregator.php'),
            ], 'config');

            $this->commands([
                AggregateGamesCommand::class,
            ]);
        }
    }
}
