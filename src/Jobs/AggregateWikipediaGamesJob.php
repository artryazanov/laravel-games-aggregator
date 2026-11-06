<?php

declare(strict_types=1);

namespace Artryazanov\GamesAggregator\Jobs;

use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\WikipediaGamesDb\Models\Game as WikipediaGame;

/**
 * Задача агрегации игр из базы данных Wikipedia (таблица wikipedia_games) в таблицы ga_*.
 */
class AggregateWikipediaGamesJob extends BaseAggregateJob
{
    /**
     * Создаёт новый экземпляр задачи агрегации Wikipedia.
     *
     * @param  int  $chunkSize  Размер чанка выборки записей
     */
    public function __construct(public int $chunkSize = 500)
    {
        parent::__construct($chunkSize);
    }

    /**
     * Выполняет пакетную обработку непривязанных игр из таблицы wikipedia_games.
     *
     * @param  AggregationService  $service  Сервис агрегации
     */
    public function handle(AggregationService $service): void
    {
        WikipediaGame::query()
            ->with(['wikipage', 'developers', 'publishers', 'modes', 'genres'])
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_games g WHERE g.wikipedia_game_id = wikipedia_games.id)')
            ->where(function ($q): void {
                $q->whereNotNull('release_year')
                    ->orWhereNotNull('release_date');
            })
            ->where(function ($q): void {
                $q->whereHas('developers')
                    ->orWhereHas('publishers');
            })
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($games) use ($service): void {
                foreach ($games as $wg) {
                    $name = trim((string) ($wg->clean_title ?: ($wg->wikipage?->title ?? '')));
                    $releaseYear = $wg->release_year !== null
                        ? (int) $wg->release_year
                        : ($wg->release_date ? (int) $wg->release_date->format('Y') : null);

                    if ($name === '' || $releaseYear === null || GaGame::makeSlug($name) === '') {
                        continue;
                    }

                    $developerNames = $service->normalizeNames(
                        $wg->developers->map(static fn ($c) => $c->clean_name ?: $c->name)->all()
                    );
                    $publisherNames = $service->normalizeNames(
                        $wg->publishers->map(static fn ($c) => $c->clean_name ?: $c->name)->all()
                    );

                    if (empty($developerNames) && empty($publisherNames)) {
                        continue;
                    }

                    $this->withTransaction(function () use ($service, $name, $releaseYear, $developerNames, $publisherNames, $wg): void {
                        $gaGame = $service->findOrCreateGaGame($name, $releaseYear, $developerNames, $publisherNames);

                        $this->syncCategories($gaGame, $service, $wg->modes->pluck('name')->all());
                        $this->syncGenres($gaGame, $service, $wg->genres->pluck('name')->all());
                        $this->linkIfEmpty($gaGame, ['wikipedia_game_id' => (int) $wg->id]);
                    });
                }
            });
    }
}
