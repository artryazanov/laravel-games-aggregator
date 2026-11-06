<?php

declare(strict_types=1);

namespace Artryazanov\GamesAggregator\Console;

use Artryazanov\GamesAggregator\Jobs\AggregateGogGamesJob;
use Artryazanov\GamesAggregator\Jobs\AggregatePcgamingwikiGamesJob;
use Artryazanov\GamesAggregator\Jobs\AggregateSteamAppsJob;
use Artryazanov\GamesAggregator\Jobs\AggregateWikipediaGamesJob;
use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\GogScanner\Models\Game as GogGame;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;
use Artryazanov\PCGamingWiki\Models\Game as PcgwGame;
use Artryazanov\WikipediaGamesDb\Models\Game as WikipediaGame;
use Illuminate\Console\Command;

/**
 * Консольная команда для запуска задач агрегации игр в очереди или их симуляции в режиме dry-run.
 */
class AggregateGamesCommand extends Command
{
    /**
     * Сигнатура и опции консольной команды.
     *
     * @var string
     */
    protected $signature = 'ga:aggregate {--source=* : Sources to aggregate: gog,steam,wikipedia,pcgamingwiki} {--chunk=500 : Chunk size per job} {--dry-run : Simulate without DB writes} {--limit=200 : Max items to scan per source in dry-run}';

    /**
     * Краткое описание консольной команды.
     *
     * @var string
     */
    protected $description = 'Aggregate base game data into ga_ tables using queues and jobs.';

    /**
     * Выполняет консольную команду агрегации игр.
     *
     * @return int Код завершения команды
     */
    public function handle(): int
    {
        $sources = $this->option('source');
        $chunk = max(1, (int) $this->option('chunk'));

        if (empty($sources)) {
            $sources = ['gog', 'steam', 'wikipedia', 'pcgamingwiki'];
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($sources, max(1, (int) $this->option('limit')));
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
                case 'pcgamingwiki':
                    AggregatePcgamingwikiGamesJob::dispatch($chunk);
                    $this->line(' - queued: PCGamingWiki');
                    break;
                default:
                    $this->warn('Unknown source: '.$source);
                    break;
            }
        }

        $this->info('Aggregation jobs dispatched.');

        return self::SUCCESS;
    }

    /**
     * Запускает симуляцию агрегации без записи в БД по выбранным источникам.
     *
     * @param  array<int, mixed>  $sources  Список источников
     * @param  int  $limit  Максимальное число кандидатов на источник
     * @return int Код завершения команды
     */
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
                case 'pcgamingwiki':
                    $this->simulatePcgamingwiki($limit);
                    break;
                default:
                    $this->warn('Unknown source: '.$source);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Симулирует агрегацию кандидатов из каталога GOG.
     *
     * @param  int  $limit  Максимальное количество сканируемых записей
     */
    private function simulateGog(int $limit): void
    {
        $this->line('GOG: scanning candidates...');
        $service = app(AggregationService::class);
        $games = GogGame::query()
            ->with(['developers', 'publishers'])
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_games g WHERE g.gog_game_id = gog_games.id)')
            ->whereNotNull('title')
            ->where(function ($q): void {
                $q->whereHas('developers')
                    ->orWhereHas('publishers');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $summary = [
            'total' => 0,
            'would_link_existing' => 0,
            'would_create_games' => 0,
            'missing_companies' => [],
        ];

        foreach ($games as $g) {
            $name = trim((string) $g->title);
            $year = GaGame::extractYearFromGog($g);
            if ($name === '' || $year === null || GaGame::makeSlug($name) === '') {
                continue;
            }

            $devs = $service->normalizeNames($g->developers->pluck('name')->all());
            $pubs = $service->normalizeNames($g->publishers->pluck('name')->all());
            if (empty($devs) && empty($pubs)) {
                continue;
            }

            $summary['total']++;
            $sim = $service->simulateDecision($name, $year, $devs, $pubs);
            if ($sim['matched_game_id']) {
                $summary['would_link_existing']++;
            } else {
                $summary['would_create_games']++;
            }
            foreach ($sim['missing_companies'] as $n) {
                $summary['missing_companies'][$n] = true;
            }
        }

        $this->table(['metric', 'value'], [
            ['candidates', $summary['total']],
            ['would_link_existing', $summary['would_link_existing']],
            ['would_create_games', $summary['would_create_games']],
            ['unique_missing_companies', count($summary['missing_companies'])],
        ]);
    }

    /**
     * Симулирует агрегацию кандидатов из базы Steam.
     *
     * @param  int  $limit  Максимальное количество сканируемых записей
     */
    private function simulateSteam(int $limit): void
    {
        $this->line('Steam: scanning candidates...');
        $service = app(AggregationService::class);
        $apps = SteamApp::query()
            ->with(['detail', 'developers', 'publishers'])
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_games g WHERE g.steam_app_id = steam_apps.id)')
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_games g WHERE g.second_steam_app_id = steam_apps.id)')
            ->whereHas('detail', fn ($q) => $q->whereNotNull('release_date'))
            ->where(function ($q): void {
                $q->whereHas('developers')
                    ->orWhereHas('publishers');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $summary = [
            'total' => 0,
            'would_link_existing' => 0,
            'would_create_games' => 0,
            'missing_companies' => [],
        ];

        foreach ($apps as $app) {
            $name = trim((string) $app->name);
            $year = $app->detail?->release_date?->format('Y');
            $year = $year ? (int) $year : null;
            if ($name === '' || $year === null || GaGame::makeSlug($name) === '') {
                continue;
            }

            $devs = $service->normalizeNames($app->developers->pluck('name')->all());
            $pubs = $service->normalizeNames($app->publishers->pluck('name')->all());
            if (empty($devs) && empty($pubs)) {
                continue;
            }

            $summary['total']++;
            $sim = $service->simulateDecision($name, $year, $devs, $pubs);
            if ($sim['matched_game_id']) {
                $summary['would_link_existing']++;
            } else {
                $summary['would_create_games']++;
            }
            foreach ($sim['missing_companies'] as $n) {
                $summary['missing_companies'][$n] = true;
            }
        }

        $this->table(['metric', 'value'], [
            ['candidates', $summary['total']],
            ['would_link_existing', $summary['would_link_existing']],
            ['would_create_games', $summary['would_create_games']],
            ['unique_missing_companies', count($summary['missing_companies'])],
        ]);
    }

    /**
     * Симулирует агрегацию кандидатов из базы Wikipedia.
     *
     * @param  int  $limit  Максимальное количество сканируемых записей
     */
    private function simulateWikipedia(int $limit): void
    {
        $this->line('Wikipedia: scanning candidates...');
        $service = app(AggregationService::class);
        $games = WikipediaGame::query()
            ->with(['wikipage', 'developers', 'publishers'])
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_games g WHERE g.wikipedia_game_id = wikipedia_games.id)')
            ->where(fn ($q) => $q->whereNotNull('release_year')->orWhereNotNull('release_date'))
            ->where(function ($q): void {
                $q->whereHas('developers')
                    ->orWhereHas('publishers');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $summary = [
            'total' => 0,
            'would_link_existing' => 0,
            'would_create_games' => 0,
            'missing_companies' => [],
        ];

        foreach ($games as $wg) {
            $name = trim((string) ($wg->clean_title ?: ($wg->wikipage?->title ?? '')));
            $year = $wg->release_year !== null
                ? (int) $wg->release_year
                : ($wg->release_date ? (int) $wg->release_date->format('Y') : null);
            if ($name === '' || $year === null || GaGame::makeSlug($name) === '') {
                continue;
            }

            $devs = $service->normalizeNames(
                $wg->developers->map(static fn ($c) => $c->clean_name ?: $c->name)->all()
            );
            $pubs = $service->normalizeNames(
                $wg->publishers->map(static fn ($c) => $c->clean_name ?: $c->name)->all()
            );
            if (empty($devs) && empty($pubs)) {
                continue;
            }

            $summary['total']++;
            $sim = $service->simulateDecision($name, $year, $devs, $pubs);
            if ($sim['matched_game_id']) {
                $summary['would_link_existing']++;
            } else {
                $summary['would_create_games']++;
            }
            foreach ($sim['missing_companies'] as $n) {
                $summary['missing_companies'][$n] = true;
            }
        }

        $this->table(['metric', 'value'], [
            ['candidates', $summary['total']],
            ['would_link_existing', $summary['would_link_existing']],
            ['would_create_games', $summary['would_create_games']],
            ['unique_missing_companies', count($summary['missing_companies'])],
        ]);
    }

    /**
     * Симулирует агрегацию кандидатов из базы PCGamingWiki.
     *
     * @param  int  $limit  Максимальное количество сканируемых записей
     */
    private function simulatePcgamingwiki(int $limit): void
    {
        $this->line('PCGamingWiki: scanning candidates...');
        $service = app(AggregationService::class);
        $games = PcgwGame::query()
            ->with(['developersCompanies', 'publishersCompanies'])
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ga_games g WHERE g.pcgamingwiki_game_id = pcgw_games.id)')
            ->whereNotNull('release_year')
            ->where(function ($q): void {
                $q->whereHas('developersCompanies')
                    ->orWhereHas('publishersCompanies');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $summary = [
            'total' => 0,
            'would_link_existing' => 0,
            'would_create_games' => 0,
            'missing_companies' => [],
        ];

        foreach ($games as $pg) {
            $name = trim((string) ($pg->clean_title ?: ($pg->title ?? '')));
            $year = $pg->release_year !== null ? (int) $pg->release_year : null;
            if ($name === '' || $year === null || GaGame::makeSlug($name) === '') {
                continue;
            }

            $devs = $service->normalizeNames($pg->developersCompanies->pluck('name')->all());
            $pubs = $service->normalizeNames($pg->publishersCompanies->pluck('name')->all());
            if (empty($devs) && empty($pubs)) {
                continue;
            }

            $summary['total']++;
            $sim = $service->simulateDecision($name, $year, $devs, $pubs);
            if ($sim['matched_game_id']) {
                $summary['would_link_existing']++;
            } else {
                $summary['would_create_games']++;
            }
            foreach ($sim['missing_companies'] as $n) {
                $summary['missing_companies'][$n] = true;
            }
        }

        $this->table(['metric', 'value'], [
            ['candidates', $summary['total']],
            ['would_link_existing', $summary['would_link_existing']],
            ['would_create_games', $summary['would_create_games']],
            ['unique_missing_companies', count($summary['missing_companies'])],
        ]);
    }
}
