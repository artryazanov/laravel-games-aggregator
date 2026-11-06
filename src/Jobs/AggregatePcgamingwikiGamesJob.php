<?php

declare(strict_types=1);

namespace Artryazanov\GamesAggregator\Jobs;

use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\PCGamingWiki\Models\Game as PcgwGame;

/**
 * Задача агрегации игр из базы данных PCGamingWiki (таблица pcgw_games) в таблицы ga_*.
 */
class AggregatePcgamingwikiGamesJob extends BaseAggregateJob
{
    /**
     * Создаёт новый экземпляр задачи агрегации PCGamingWiki.
     *
     * @param  int  $chunkSize  Размер чанка выборки записей
     */
    public function __construct(public int $chunkSize = 500)
    {
        parent::__construct($chunkSize);
    }

    /**
     * Выполняет пакетную обработку непривязанных игр из таблицы pcgw_games.
     *
     * @param  AggregationService  $service  Сервис агрегации
     */
    public function handle(AggregationService $service): void
    {
        PcgwGame::query()
            ->with(['developersCompanies', 'publishersCompanies', 'modes', 'genres'])
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_games g WHERE g.pcgamingwiki_game_id = pcgw_games.id)')
            ->whereNotNull('release_year')
            ->where(function ($q): void {
                $q->whereHas('developersCompanies')
                    ->orWhereHas('publishersCompanies');
            })
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($games) use ($service): void {
                foreach ($games as $pg) {
                    $name = trim((string) ($pg->clean_title ?: ($pg->title ?? '')));
                    $releaseYear = $pg->release_year !== null ? (int) $pg->release_year : null;

                    if ($name === '' || $releaseYear === null || GaGame::makeSlug($name) === '') {
                        continue;
                    }

                    $developerNames = $service->normalizeNames($pg->developersCompanies->pluck('name')->all());
                    $publisherNames = $service->normalizeNames($pg->publishersCompanies->pluck('name')->all());

                    if (empty($developerNames) && empty($publisherNames)) {
                        continue;
                    }

                    $this->withTransaction(function () use ($service, $name, $releaseYear, $developerNames, $publisherNames, $pg): void {
                        $gaGame = $service->findOrCreateGaGame($name, $releaseYear, $developerNames, $publisherNames);

                        $this->syncCategories($gaGame, $service, $pg->modes->pluck('name')->all());
                        $this->syncGenres($gaGame, $service, $pg->genres->pluck('name')->all());
                        $this->linkIfEmpty($gaGame, ['pcgamingwiki_game_id' => (int) $pg->id]);
                    });
                }
            });
    }
}
