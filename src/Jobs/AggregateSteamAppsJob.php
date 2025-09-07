<?php

namespace Artryazanov\GamesAggregator\Jobs;

use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;

class AggregateSteamAppsJob extends BaseAggregateJob
{
    public function __construct(public int $chunkSize = 500)
    {
        parent::__construct($chunkSize);
    }

    public function handle(AggregationService $service): void
    {
        SteamApp::query()
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_games g WHERE g.steam_app_id = steam_apps.id)')
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

                    $this->withTransaction(function () use ($service, $name, $releaseYear, $developerNames, $publisherNames, $app) {
                        $gaGame = $service->findOrCreateGaGame($name, $releaseYear, $developerNames, $publisherNames);

                        // Categories: Steam categories descriptions
                        $this->syncCategories($gaGame, $service, $app->categories()->pluck('description')->all());

                        // Genres: Steam genres descriptions
                        $this->syncGenres($gaGame, $service, $app->genres()->pluck('description')->all());

                        $this->linkIfEmpty($gaGame, ['steam_app_id' => $app->id]);
                    });
                }
            });
    }
}
