<?php

namespace Artryazanov\GamesAggregator\Tests\Feature;

use Artryazanov\GamesAggregator\Jobs\AggregateGogGamesJob;
use Artryazanov\GamesAggregator\Jobs\AggregateSteamAppsJob;
use Artryazanov\GamesAggregator\Jobs\AggregateWikipediaGamesJob;
use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\GamesAggregator\Tests\TestCase;
use Artryazanov\GogScanner\Models\Company as GogCompany;
use Artryazanov\GogScanner\Models\Game as GogGame;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppDetail;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppDeveloper;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppPublisher;
use Artryazanov\WikipediaGamesDb\Models\Company as WikiCompany;
use Artryazanov\WikipediaGamesDb\Models\Game as WikipediaGame;
use Artryazanov\WikipediaGamesDb\Models\Wikipage;
use Illuminate\Foundation\Testing\RefreshDatabase;

class JobsAggregationTest extends TestCase
{
    use RefreshDatabase;

    public function test_gog_job_creates_links_and_ga_entities(): void
    {
        $dev = GogCompany::create(['name' => 'id Software']);
        $pub = GogCompany::create(['name' => 'GT Interactive']);

        $g = GogGame::create(['id' => 1, 'title' => 'Doom', 'release_date_iso' => '1993-12-10']);
        $g->developers()->attach($dev->id);
        $g->publishers()->attach($pub->id);

        (new AggregateGogGamesJob(chunkSize: 50))->handle(app(AggregationService::class));

        $this->assertDatabaseHas('ga_games', ['name' => 'Doom', 'release_year' => 1993]);
        $this->assertDatabaseHas('ga_gog_game_links', ['gog_game_id' => 1]);
    }

    public function test_steam_job_creates_links_and_ga_entities(): void
    {
        $app = SteamApp::create(['appid' => 100, 'name' => 'Doom']);
        SteamAppDetail::create([
            'steam_app_id' => $app->id,
            'name' => 'Doom',
            'release_date' => '1993-12-10',
        ]);
        $dev = SteamAppDeveloper::create(['name' => 'id Software']);
        $pub = SteamAppPublisher::create(['name' => 'GT Interactive']);
        $app->developers()->attach($dev->id);
        $app->publishers()->attach($pub->id);

        (new AggregateSteamAppsJob(chunkSize: 50))->handle(app(AggregationService::class));

        $this->assertDatabaseHas('ga_games', ['name' => 'Doom', 'release_year' => 1993]);
        $this->assertDatabaseHas('ga_steam_app_links', ['steam_app_id' => $app->id]);
    }

    public function test_wikipedia_job_creates_links_and_ga_entities(): void
    {
        $wikipage = Wikipage::create(['title' => 'Doom', 'wikipedia_url' => 'https://en.wikipedia.org/wiki/Doom']);
        $game = WikipediaGame::create(['wikipage_id' => $wikipage->id, 'release_year' => 1993]);
        $dev = WikiCompany::create(['name' => 'id Software']);
        $pub = WikiCompany::create(['name' => 'GT Interactive']);
        $game->companies()->attach([$dev->id => ['role' => 'developer'], $pub->id => ['role' => 'publisher']]);

        (new AggregateWikipediaGamesJob(chunkSize: 50))->handle(app(AggregationService::class));

        $this->assertDatabaseHas('ga_games', ['name' => 'Doom', 'release_year' => 1993]);
        $this->assertDatabaseHas('ga_wikipedia_game_links', ['wikipedia_game_id' => $game->id]);
    }
}

