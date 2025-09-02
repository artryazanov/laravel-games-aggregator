<?php

namespace Artryazanov\GamesAggregator\Jobs;

use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Exception as QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class AggregateSteamAppsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public int $chunkSize = 500)
    {
    }

    public function handle(AggregationService $service): void
    {
        SteamApp::query()
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_steam_app_links l WHERE l.steam_app_id = steam_apps.id)')
            ->whereHas('detail', function ($q) {
                $q->whereNotNull('release_date');
            })
            ->whereHas('developers')
            ->whereHas('publishers')
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($apps) use ($service) {
                foreach ($apps as $app) {
                    $name = trim((string) $app->name);
                    $releaseYear = optional($app->detail?->release_date)->format('Y');
                    $releaseYear = $releaseYear ? (int) $releaseYear : null;

                    if ($name === '' || $releaseYear === null) {
                        continue;
                    }

                    $developerNames = $app->developers()->pluck('name')->all();
                    $publisherNames = $app->publishers()->pluck('name')->all();

                    if (empty($developerNames) || empty($publisherNames)) {
                        continue;
                    }

                    DB::transaction(function () use ($service, $name, $releaseYear, $developerNames, $publisherNames, $app) {
                        $gaGame = $service->findOrCreateGaGame($name, $releaseYear, $developerNames, $publisherNames);

                        // Categories: Steam categories descriptions
                        $catNames = $app->categories()->pluck('description')->all();
                        if (! empty($catNames)) {
                            $gaCatIds = $service->ensureCategories($catNames);
                            $gaGame->categories()->syncWithoutDetaching($gaCatIds);
                        }

                        // Genres: Steam genres descriptions
                        $genreNames = $app->genres()->pluck('description')->all();
                        if (! empty($genreNames)) {
                            $gaGenreIds = $service->ensureGenres($genreNames);
                            $gaGame->genres()->syncWithoutDetaching($gaGenreIds);
                        }

                        try {
                            DB::table('ga_steam_app_links')->insert([
                                'ga_game_id' => $gaGame->id,
                                'steam_app_id' => $app->id,
                            ]);
                        } catch (QueryException $e) {
                            // Ignore duplicate errors
                        }
                    });
                }
            });
    }
}
