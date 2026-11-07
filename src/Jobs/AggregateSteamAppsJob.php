<?php

declare(strict_types=1);

namespace Artryazanov\GamesAggregator\Jobs;

use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;

/**
 * Задача агрегации приложений Steam (таблица steam_apps) в таблицы ga_*.
 */
class AggregateSteamAppsJob extends BaseAggregateJob
{
    /**
     * Создаёт новый экземпляр задачи агрегации Steam.
     *
     * @param  int  $chunkSize  Размер чанка выборки записей
     */
    public function __construct(public int $chunkSize = 500)
    {
        parent::__construct($chunkSize);
    }

    /**
     * Выполняет пакетную обработку непривязанных приложений из таблицы steam_apps.
     *
     * @param  AggregationService  $service  Сервис агрегации
     */
    public function handle(AggregationService $service): void
    {
        SteamApp::query()
            ->with(['detail', 'developers', 'publishers', 'categories', 'genres'])
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_games g WHERE g.steam_app_id = steam_apps.id)')
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_games g WHERE g.second_steam_app_id = steam_apps.id)')
            ->whereHas('detail', function ($q): void {
                $q->whereNotNull('release_date');
            })
            ->where(function ($q): void {
                $q->whereHas('developers')
                    ->orWhereHas('publishers');
            })
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($apps) use ($service): void {
                foreach ($apps as $app) {
                    $service->aggregateSteamApp($app, strict: true);
                }
            });
    }
}
