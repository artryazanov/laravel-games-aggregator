<?php

namespace Artryazanov\GamesAggregator\Jobs;

use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\PCGamingWiki\Models\Game as PcgwGame;

class AggregatePcgamingwikiGamesJob extends BaseAggregateJob
{
    public function __construct(public int $chunkSize = 500)
    {
        parent::__construct($chunkSize);
    }

    public function handle(AggregationService $service): void
    {
        PcgwGame::query()
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_games g WHERE g.pcgamingwiki_game_id = pcgw_games.id)')
            ->whereNotNull('release_year')
            ->where(function ($q) {
                $q->whereHas('developersCompanies')
                    ->orWhereHas('publishersCompanies');
            })
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($games) use ($service) {
                foreach ($games as $pg) {
                    $name = trim((string) ($pg->clean_title ?? $pg->title ?? ''));
                    $releaseYear = $pg->release_year;

                    if ($name === '' || $releaseYear === null) {
                        continue;
                    }

                    $developerNames = $pg->developersCompanies()->pluck('name')->all();
                    $publisherNames = $pg->publishersCompanies()->pluck('name')->all();

                    if (empty($developerNames) && empty($publisherNames)) {
                        continue;
                    }

                    $this->withTransaction(function () use ($service, $name, $releaseYear, $developerNames, $publisherNames, $pg) {
                        $gaGame = $service->findOrCreateGaGame($name, (int) $releaseYear, $developerNames, $publisherNames);

                        // Categories: PCGamingWiki game modes as categories
                        $this->syncCategories($gaGame, $service, $pg->modes()->pluck('name')->all());

                        // Genres: PCGamingWiki genres names
                        $this->syncGenres($gaGame, $service, $pg->genres()->pluck('name')->all());

                        $this->linkIfEmpty($gaGame, ['pcgamingwiki_game_id' => $pg->id]);
                    });
                }
            });
    }
}
