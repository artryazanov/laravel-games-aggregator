<?php

namespace Artryazanov\GamesAggregator\Jobs;

use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\GogScanner\Models\Game as GogGame;

class AggregateGogGamesJob extends BaseAggregateJob
{
    public function __construct(public int $chunkSize = 500)
    {
        parent::__construct($chunkSize);
    }

    public function handle(AggregationService $service): void
    {
        GogGame::query()
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_games g WHERE g.gog_game_id = gog_games.id)')
            ->whereNotNull('title')
            ->where(function ($q) {
                $q->whereHas('developers')
                    ->orWhereHas('publishers');
            })
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($games) use ($service) {
                foreach ($games as $g) {
                    $name = trim((string) $g->title);
                    $releaseYear = $this->extractYearFromGog($g);

                    // Skip if required fields are missing
                    if ($name === '' || $releaseYear === null) {
                        continue;
                    }

                    $developerNames = $g->developers()->pluck('name')->all();
                    $publisherNames = $g->publishers()->pluck('name')->all();

                    if (empty($developerNames) && empty($publisherNames)) {
                        continue;
                    }

                    $this->withTransaction(function () use ($service, $name, $releaseYear, $developerNames, $publisherNames, $g) {
                        $gaGame = $service->findOrCreateGaGame($name, $releaseYear, $developerNames, $publisherNames);

                        // Categories: from gog category and original_category
                        $this->syncCategories($gaGame, $service, [optional($g->category)->name, optional($g->originalCategory)->name]);

                        // Genres from GOG pivot
                        $this->syncGenres($gaGame, $service, $g->genres()->pluck('name')->all());

                        // Link to source if not linked
                        $this->linkIfEmpty($gaGame, ['gog_game_id' => $g->id]);
                    });
                }
            });
    }

    private function extractYearFromGog(GogGame $g): ?int
    {
        // Prefer explicit ISO or timestamp fields
        if (! empty($g->release_date_iso) && preg_match('/^(\d{4})-\d{2}-\d{2}/', $g->release_date_iso, $m)) {
            return (int) $m[1];
        }
        foreach (['release_date_ts', 'global_release_date_ts'] as $tsField) {
            $ts = (int) ($g->{$tsField} ?? 0);
            if ($ts > 0) {
                return (int) gmdate('Y', $ts);
            }
        }

        return null;
    }
}
