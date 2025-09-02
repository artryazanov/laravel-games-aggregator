<?php

namespace Artryazanov\GamesAggregator\Jobs;

use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\WikipediaGamesDb\Models\Game as WikipediaGame;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Exception as QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class AggregateWikipediaGamesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public int $chunkSize = 500)
    {
    }

    public function handle(AggregationService $service): void
    {
        // wikipedia_games now stores title on related wikipage, but we can still filter by required pivots and missing link
        WikipediaGame::query()
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_wikipedia_game_links l WHERE l.wikipedia_game_id = wikipedia_games.id)')
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
                    $name = trim((string) ($wg->wikipage?->title ?? ''));
                    $releaseYear = $wg->release_year ?? ($wg->release_date ? (int) $wg->release_date->format('Y') : null);

                    if ($name === '' || $releaseYear === null) {
                        continue;
                    }

                    $developerNames = $wg->developers()->pluck('name')->all();
                    $publisherNames = $wg->publishers()->pluck('name')->all();

                    if (empty($developerNames) || empty($publisherNames)) {
                        continue;
                    }

                    DB::transaction(function () use ($service, $name, $releaseYear, $developerNames, $publisherNames, $wg) {
                        $gaGame = $service->findOrCreateGaGame($name, $releaseYear, $developerNames, $publisherNames);

                        // Categories: Wikipedia game modes as categories
                        $catNames = $wg->modes()->pluck('name')->all();
                        if (! empty($catNames)) {
                            $gaCatIds = $service->ensureCategories($catNames);
                            $gaGame->categories()->syncWithoutDetaching($gaCatIds);
                        }

                        // Genres: Wikipedia genres names
                        $genreNames = $wg->genres()->pluck('name')->all();
                        if (! empty($genreNames)) {
                            $gaGenreIds = $service->ensureGenres($genreNames);
                            $gaGame->genres()->syncWithoutDetaching($gaGenreIds);
                        }

                        try {
                            DB::table('ga_wikipedia_game_links')->insert([
                                'ga_game_id' => $gaGame->id,
                                'wikipedia_game_id' => $wg->id,
                            ]);
                        } catch (QueryException $e) {
                            // Ignore duplicate errors
                        }
                    });
                }
            });
    }
}
