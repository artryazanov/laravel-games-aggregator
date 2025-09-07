<?php

namespace Artryazanov\GamesAggregator\Jobs;

use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\WikipediaGamesDb\Models\Game as WikipediaGame;

class AggregateWikipediaGamesJob extends BaseAggregateJob
{
    public function __construct(public int $chunkSize = 500)
    {
        parent::__construct($chunkSize);
    }

    public function handle(AggregationService $service): void
    {
        // wikipedia_games now stores title on related wikipage, but we can still filter by required pivots and missing link
        WikipediaGame::query()
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_games g WHERE g.wikipedia_game_id = wikipedia_games.id)')
            ->where(function ($q) {
                // Release year or release_date is required (release_year may be null, but release_date could be set)
                $q->whereNotNull('release_year')
                    ->orWhereNotNull('release_date');
            })
            ->whereHas('developers')
            ->whereHas('publishers')
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($games) use ($service) {
                foreach ($games as $wg) {
                    // Use normalized clean_title from wikipedia_games instead of wikipage title
                    $name = trim((string) ($wg->clean_title ?? ''));
                    $releaseYear = $wg->release_year ?? ($wg->release_date ? (int) $wg->release_date->format('Y') : null);

                    if ($name === '' || $releaseYear === null) {
                        continue;
                    }

                    $developerNames = $wg->developers()->pluck('clean_name')->all();
                    $publisherNames = $wg->publishers()->pluck('clean_name')->all();

                    if (empty($developerNames) || empty($publisherNames)) {
                        continue;
                    }

                    $this->withTransaction(function () use ($service, $name, $releaseYear, $developerNames, $publisherNames, $wg) {
                        $gaGame = $service->findOrCreateGaGame($name, $releaseYear, $developerNames, $publisherNames);

                        // Categories: Wikipedia game modes as categories
                        $this->syncCategories($gaGame, $service, $wg->modes()->pluck('name')->all());

                        // Genres: Wikipedia genres names
                        $this->syncGenres($gaGame, $service, $wg->genres()->pluck('name')->all());

                        $this->linkIfEmpty($gaGame, ['wikipedia_game_id' => $wg->id]);
                    });
                }
            });
    }
}
