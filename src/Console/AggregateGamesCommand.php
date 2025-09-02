<?php

namespace Artryazanov\GamesAggregator\Console;

use Artryazanov\GamesAggregator\Jobs\AggregateGogGamesJob;
use Artryazanov\GamesAggregator\Jobs\AggregateSteamAppsJob;
use Artryazanov\GamesAggregator\Jobs\AggregateWikipediaGamesJob;
use Illuminate\Console\Command;

class AggregateGamesCommand extends Command
{
    protected $signature = 'ga:aggregate {--source=* : Sources to aggregate: gog,steam,wikipedia} {--chunk=100 : Chunk size per job} {--dry-run : Simulate without DB writes} {--limit=200 : Max items to scan per source in dry-run}';
    protected $description = 'Aggregate base game data into ga_ tables using queues and jobs.';

    public function handle(): int
    {
        $sources = $this->option('source');
        $chunk = (int) $this->option('chunk');

        if (empty($sources)) {
            $sources = ['gog', 'steam', 'wikipedia'];
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($sources, (int) $this->option('limit'));
        }

        $this->info('Dispatching aggregation jobs for: '.implode(',', $sources).' (chunk '.$chunk.')');

        foreach ($sources as $source) {
            switch (strtolower((string) $source)) {
                case 'gog':
                    AggregateGogGamesJob::dispatch($chunk);
                    $this->line(' - queued: GOG');
                    break;
                case 'steam':
                    AggregateSteamAppsJob::dispatch($chunk);
                    $this->line(' - queued: Steam');
                    break;
                case 'wikipedia':
                    AggregateWikipediaGamesJob::dispatch($chunk);
                    $this->line(' - queued: Wikipedia');
                    break;
                default:
                    $this->warn('Unknown source: '.$source);
                    break;
            }
        }

        $this->info('Aggregation jobs dispatched.');
        return self::SUCCESS;
    }

    private function dryRun(array $sources, int $limit): int
    {
        $this->info('Dry-run mode: no DB writes. Limit per source: '.$limit);

        foreach ($sources as $source) {
            switch (strtolower((string) $source)) {
                case 'gog':
                    $this->simulateGog($limit);
                    break;
                case 'steam':
                    $this->simulateSteam($limit);
                    break;
                case 'wikipedia':
                    $this->simulateWikipedia($limit);
                    break;
                default:
                    $this->warn('Unknown source: '.$source);
            }
        }

        return self::SUCCESS;
    }

    private function simulateGog(int $limit): void
    {
        $this->line('GOG: scanning candidates...');
        $service = app(\Artryazanov\GamesAggregator\Services\AggregationService::class);
        $q = \Artryazanov\GogScanner\Models\Game::query()
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_gog_game_links l WHERE l.gog_game_id = gog_games.id)')
            ->whereNotNull('title')
            ->whereHas('developers')
            ->whereHas('publishers')
            ->orderBy('id')
            ->limit($limit);

        $summary = [
            'total' => 0,
            'would_link_existing' => 0,
            'would_create_games' => 0,
            'missing_companies' => [],
        ];

        foreach ($q->cursor() as $g) {
            $name = trim((string) $g->title);
            $year = null;
            if (! empty($g->release_date_iso) && preg_match('/^(\\d{4})-\\d{2}-\\d{2}/', $g->release_date_iso, $m)) {
                $year = (int) $m[1];
            } else {
                foreach (['release_date_ts', 'global_release_date_ts'] as $tsField) {
                    $ts = (int) ($g->{$tsField} ?? 0);
                    if ($ts > 0) { $year = (int) gmdate('Y', $ts); break; }
                }
            }
            if ($name === '' || $year === null) continue;

            $devs = $g->developers()->pluck('name')->all();
            $pubs = $g->publishers()->pluck('name')->all();
            if (empty($devs) || empty($pubs)) continue;

            $summary['total']++;
            $sim = $service->simulateDecision($name, $year, $devs, $pubs);
            if ($sim['matched_game_id']) $summary['would_link_existing']++; else $summary['would_create_games']++;
            foreach ($sim['missing_companies'] as $n) { $summary['missing_companies'][$n] = true; }
        }

        $this->table(['metric', 'value'], [
            ['candidates', $summary['total']],
            ['would_link_existing', $summary['would_link_existing']],
            ['would_create_games', $summary['would_create_games']],
            ['unique_missing_companies', count($summary['missing_companies'])],
        ]);
    }

    private function simulateSteam(int $limit): void
    {
        $this->line('Steam: scanning candidates...');
        $service = app(\Artryazanov\GamesAggregator\Services\AggregationService::class);
        $q = \Artryazanov\LaravelSteamAppsDb\Models\SteamApp::query()
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_steam_app_links l WHERE l.steam_app_id = steam_apps.id)')
            ->whereHas('detail', fn($q) => $q->whereNotNull('release_date'))
            ->whereHas('developers')
            ->whereHas('publishers')
            ->orderBy('id')
            ->limit($limit);

        $summary = [
            'total' => 0,
            'would_link_existing' => 0,
            'would_create_games' => 0,
            'missing_companies' => [],
        ];

        foreach ($q->cursor() as $app) {
            $name = trim((string) $app->name);
            $year = $app->detail?->release_date?->format('Y');
            $year = $year ? (int) $year : null;
            if ($name === '' || $year === null) continue;

            $devs = $app->developers()->pluck('name')->all();
            $pubs = $app->publishers()->pluck('name')->all();
            if (empty($devs) || empty($pubs)) continue;

            $summary['total']++;
            $sim = $service->simulateDecision($name, $year, $devs, $pubs);
            if ($sim['matched_game_id']) $summary['would_link_existing']++; else $summary['would_create_games']++;
            foreach ($sim['missing_companies'] as $n) { $summary['missing_companies'][$n] = true; }
        }

        $this->table(['metric', 'value'], [
            ['candidates', $summary['total']],
            ['would_link_existing', $summary['would_link_existing']],
            ['would_create_games', $summary['would_create_games']],
            ['unique_missing_companies', count($summary['missing_companies'])],
        ]);
    }

    private function simulateWikipedia(int $limit): void
    {
        $this->line('Wikipedia: scanning candidates...');
        $service = app(\Artryazanov\GamesAggregator\Services\AggregationService::class);
        $q = \Artryazanov\WikipediaGamesDb\Models\Game::query()
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_wikipedia_game_links l WHERE l.wikipedia_game_id = wikipedia_games.id)')
            ->where(fn($q) => $q->whereNotNull('release_year')->orWhereNotNull('release_date'))
            ->whereHas('developers')
            ->whereHas('publishers')
            ->orderBy('id')
            ->limit($limit);

        $summary = [
            'total' => 0,
            'would_link_existing' => 0,
            'would_create_games' => 0,
            'missing_companies' => [],
        ];

        foreach ($q->cursor() as $wg) {
            $name = trim((string) ($wg->wikipage?->title ?? ''));
            $year = $wg->release_year ?? ($wg->release_date ? (int) $wg->release_date->format('Y') : null);
            if ($name === '' || $year === null) continue;

            $devs = $wg->developers()->pluck('name')->all();
            $pubs = $wg->publishers()->pluck('name')->all();
            if (empty($devs) || empty($pubs)) continue;

            $summary['total']++;
            $sim = $service->simulateDecision($name, (int) $year, $devs, $pubs);
            if ($sim['matched_game_id']) $summary['would_link_existing']++; else $summary['would_create_games']++;
            foreach ($sim['missing_companies'] as $n) { $summary['missing_companies'][$n] = true; }
        }

        $this->table(['metric', 'value'], [
            ['candidates', $summary['total']],
            ['would_link_existing', $summary['would_link_existing']],
            ['would_create_games', $summary['would_create_games']],
            ['unique_missing_companies', count($summary['missing_companies'])],
        ]);
    }
}
