<?php

declare(strict_types=1);

namespace Artryazanov\GamesAggregator\Jobs;

use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\GogScanner\Models\Game as GogGame;

/**
 * Задача агрегации игр из каталога GOG (таблица gog_games) в таблицы ga_*.
 */
class AggregateGogGamesJob extends BaseAggregateJob
{
    /**
     * Создаёт новый экземпляр задачи агрегации GOG.
     *
     * @param  int  $chunkSize  Размер чанка выборки записей
     */
    public function __construct(public int $chunkSize = 500)
    {
        parent::__construct($chunkSize);
    }

    /**
     * Выполняет пакетную обработку непривязанных игр из таблицы gog_games.
     *
     * @param  AggregationService  $service  Сервис агрегации
     */
    public function handle(AggregationService $service): void
    {
        GogGame::query()
            ->with(['developers', 'publishers', 'category', 'originalCategory', 'genres'])
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_games g WHERE g.gog_game_id = gog_games.id)')
            ->whereNotNull('title')
            ->where(function ($q): void {
                $q->whereHas('developers')
                    ->orWhereHas('publishers');
            })
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($games) use ($service): void {
                foreach ($games as $g) {
                    $name = trim((string) $g->title);
                    $releaseYear = GaGame::extractYearFromGog($g);

                    if ($name === '' || $releaseYear === null || GaGame::makeSlug($name) === '') {
                        continue;
                    }

                    $developerNames = $service->normalizeNames($g->developers->pluck('name')->all());
                    $publisherNames = $service->normalizeNames($g->publishers->pluck('name')->all());

                    if (empty($developerNames) && empty($publisherNames)) {
                        continue;
                    }

                    $this->withTransaction(function () use ($service, $name, $releaseYear, $developerNames, $publisherNames, $g): void {
                        $gaGame = $service->findOrCreateGaGame($name, $releaseYear, $developerNames, $publisherNames);

                        $this->syncCategories($gaGame, $service, [$g->category?->name, $g->originalCategory?->name]);
                        $this->syncGenres($gaGame, $service, $g->genres->pluck('name')->all());
                        $this->linkIfEmpty($gaGame, ['gog_game_id' => (int) $g->id]);
                    });
                }
            });
    }
}
