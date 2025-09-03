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

        $cat = \Artryazanov\GogScanner\Models\Category::create(['name' => 'Action']);
        $g = GogGame::create(['id' => 1, 'title' => 'Doom', 'release_date_iso' => '1993-12-10', 'category_id' => $cat->id]);
        $g->developers()->attach($dev->id);
        $g->publishers()->attach($pub->id);

        // Add a GOG genre before running the job
        $genre = \Artryazanov\GogScanner\Models\Genre::create(['name' => 'Shooter']);
        $g->genres()->attach($genre->id);

        (new AggregateGogGamesJob(chunkSize: 50))->handle(app(AggregationService::class));

        $this->assertDatabaseHas('ga_games', ['name' => 'Doom', 'release_year' => 1993, 'gog_game_id' => 1]);
        // Category from gog category
        $this->assertDatabaseHas('ga_categories', ['name' => 'Action']);
        $this->assertDatabaseHas('ga_genres', ['name' => 'Shooter']);
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

        // Add a Steam category
        $catModel = \Artryazanov\LaravelSteamAppsDb\Models\SteamAppCategory::create(['category_id' => 1, 'description' => 'Single-player']);
        $app->categories()->attach($catModel->id);

        // Add a Steam genre beforehand and run job
        $steamGenre = \Artryazanov\LaravelSteamAppsDb\Models\SteamAppGenre::create(['genre_id' => '1', 'description' => 'Action']);
        $app->genres()->attach($steamGenre->id);

        (new AggregateSteamAppsJob(chunkSize: 50))->handle(app(AggregationService::class));

        $this->assertDatabaseHas('ga_games', ['name' => 'Doom', 'release_year' => 1993, 'steam_app_id' => $app->id]);
        $this->assertDatabaseHas('ga_categories', ['name' => 'Single-player']);
        $this->assertDatabaseHas('ga_genres', ['name' => 'Action']);
    }

    public function test_wikipedia_job_creates_links_and_ga_entities(): void
    {
        $wikipage = Wikipage::create(['title' => 'Doom', 'wikipedia_url' => 'https://en.wikipedia.org/wiki/Doom']);
        $game = WikipediaGame::create(['wikipage_id' => $wikipage->id, 'release_year' => 1993]);
        $dev = WikiCompany::create(['name' => 'id Software']);
        $pub = WikiCompany::create(['name' => 'GT Interactive']);
        $game->companies()->attach([$dev->id => ['role' => 'developer'], $pub->id => ['role' => 'publisher']]);

        // Add a Wikipedia mode and attach as category
        $mode = \Artryazanov\WikipediaGamesDb\Models\Mode::create(['name' => 'Single-player']);
        $game->modes()->attach($mode->id);

        // Add a Wikipedia genre beforehand
        $wgGenre = \Artryazanov\WikipediaGamesDb\Models\Genre::create(['name' => 'Action']);
        $game->genres()->attach($wgGenre->id);

        (new AggregateWikipediaGamesJob(chunkSize: 50))->handle(app(AggregationService::class));

        $this->assertDatabaseHas('ga_games', ['name' => 'Doom', 'release_year' => 1993, 'wikipedia_game_id' => $game->id]);
        $this->assertDatabaseHas('ga_categories', ['name' => 'Single-player']);
        $this->assertDatabaseHas('ga_genres', ['name' => 'Action']);
    }
}
