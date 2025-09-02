<?php

namespace Artryazanov\GamesAggregator\Jobs;

use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\GogScanner\Models\Game as GogGame;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Exception as QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class AggregateGogGamesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function __construct(public int $chunkSize = 100)
    {
    }

    public function handle(AggregationService $service): void
    {
        GogGame::query()
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_gog_game_links l WHERE l.gog_game_id = gog_games.id)')
            ->whereNotNull('title')
            ->where(function ($q) {
                $q->whereHas('developers')
                  ->whereHas('publishers');
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

                    if (empty($developerNames) || empty($publisherNames)) {
                        continue;
                    }

                    DB::transaction(function () use ($service, $name, $releaseYear, $developerNames, $publisherNames, $g) {
                        $gaGame = $service->findOrCreateGaGame($name, $releaseYear, $developerNames, $publisherNames);

                        // Link to source if not linked
                        try {
                            DB::table('ga_gog_game_links')->insert([
                                'ga_game_id' => $gaGame->id,
                                'gog_game_id' => $g->id,
                            ]);
                        } catch (QueryException $e) {
                            // Ignore duplicate errors
                        }
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

