<?php

namespace Artryazanov\GamesAggregator\Tests\Feature;

use Artryazanov\GamesAggregator\Jobs\AggregateGogGamesJob;
use Artryazanov\GamesAggregator\Jobs\AggregatePcgamingwikiGamesJob;
use Artryazanov\GamesAggregator\Jobs\AggregateSteamAppsJob;
use Artryazanov\GamesAggregator\Jobs\AggregateWikipediaGamesJob;
use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\GamesAggregator\Tests\TestCase;
use Artryazanov\GogScanner\Models\Category;
use Artryazanov\GogScanner\Models\Company as GogCompany;
use Artryazanov\GogScanner\Models\Game as GogGame;
use Artryazanov\GogScanner\Models\Genre;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppCategory;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppDetail;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppDeveloper;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppGenre;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppPublisher;
use Artryazanov\PCGamingWiki\Models\Company as PcgwCompany;
use Artryazanov\PCGamingWiki\Models\Game as PcgwGame;
use Artryazanov\PCGamingWiki\Models\Genre as PcgwGenre;
use Artryazanov\PCGamingWiki\Models\Mode as PcgwMode;
use Artryazanov\WikipediaGamesDb\Models\Company as WikiCompany;
use Artryazanov\WikipediaGamesDb\Models\Game as WikipediaGame;
use Artryazanov\WikipediaGamesDb\Models\Mode;
use Artryazanov\WikipediaGamesDb\Models\Wikipage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

class JobsAggregationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Model::preventLazyLoading(true);
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        parent::tearDown();
    }

    public function test_gog_job_creates_links_and_ga_entities(): void
    {
        $dev = GogCompany::create(['name' => 'id Software']);
        $pub = GogCompany::create(['name' => 'GT Interactive']);

        $cat = Category::create(['name' => 'Action']);
        $g = GogGame::create(['id' => 1, 'title' => 'Doom', 'release_date_iso' => '1993-12-10', 'category_id' => $cat->id]);
        $g->developers()->attach($dev->id);
        $g->publishers()->attach($pub->id);

        // Add a GOG genre before running the job
        $genre = Genre::create(['name' => 'Shooter']);
        $g->genres()->attach($genre->id);

        (new AggregateGogGamesJob(chunkSize: 50))->handle(app(AggregationService::class));

        $this->assertDatabaseHas('ga_games', ['name' => 'Doom', 'release_year' => 1993, 'gog_game_id' => 1]);
        $this->assertDatabaseHas('ga_companies', ['name' => 'id Software']);
        $this->assertDatabaseHas('ga_companies', ['name' => 'GT Interactive']);
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
        $catModel = SteamAppCategory::create(['category_id' => 1, 'description' => 'Single-player']);
        $app->categories()->attach($catModel->id);

        // Add a Steam genre beforehand and run job
        $steamGenre = SteamAppGenre::create(['genre_id' => '1', 'description' => 'Action']);
        $app->genres()->attach($steamGenre->id);

        (new AggregateSteamAppsJob(chunkSize: 50))->handle(app(AggregationService::class));

        $this->assertDatabaseHas('ga_games', ['name' => 'Doom', 'release_year' => 1993, 'steam_app_id' => $app->id]);
        $this->assertDatabaseHas('ga_companies', ['name' => 'id Software']);
        $this->assertDatabaseHas('ga_companies', ['name' => 'GT Interactive']);
        $this->assertDatabaseHas('ga_categories', ['name' => 'Single-player']);
        $this->assertDatabaseHas('ga_genres', ['name' => 'Action']);
    }

    public function test_wikipedia_job_creates_links_and_ga_entities(): void
    {
        $wikipage = Wikipage::create(['title' => 'Doom', 'wikipedia_url' => 'https://en.wikipedia.org/wiki/Doom']);
        $game = WikipediaGame::create(['wikipage_id' => $wikipage->id, 'clean_title' => 'Doom', 'release_year' => 1993]);
        $dev = WikiCompany::create(['name' => 'id Software']);
        $pub = WikiCompany::create(['name' => 'GT Interactive (company)', 'clean_name' => 'GT Interactive']);
        $game->companies()->attach([$dev->id => ['role' => 'developer'], $pub->id => ['role' => 'publisher']]);

        // Add a Wikipedia mode and attach as category
        $mode = Mode::create(['name' => 'Single-player']);
        $game->modes()->attach($mode->id);

        // Add a Wikipedia genre beforehand
        $wgGenre = \Artryazanov\WikipediaGamesDb\Models\Genre::create(['name' => 'Action']);
        $game->genres()->attach($wgGenre->id);

        // Second game without clean_title and release_year, falling back to wikipage title and release_date
        $wp2 = Wikipage::create(['title' => 'Doom II', 'wikipedia_url' => 'https://en.wikipedia.org/wiki/Doom_II']);
        $game2 = WikipediaGame::create([
            'wikipage_id' => $wp2->id,
            'clean_title' => null,
            'release_year' => null,
            'release_date' => '1994-10-10',
        ]);
        $game2->companies()->attach([$dev->id => ['role' => 'developer']]);

        (new AggregateWikipediaGamesJob(chunkSize: 50))->handle(app(AggregationService::class));

        $this->assertDatabaseHas('ga_games', ['name' => 'Doom', 'release_year' => 1993, 'wikipedia_game_id' => $game->id]);
        $this->assertDatabaseHas('ga_games', ['name' => 'Doom II', 'release_year' => 1994, 'wikipedia_game_id' => $game2->id]);
        $this->assertDatabaseHas('ga_companies', ['name' => 'id Software']);
        $this->assertDatabaseHas('ga_companies', ['name' => 'GT Interactive']);
        $this->assertDatabaseHas('ga_categories', ['name' => 'Single-player']);
        $this->assertDatabaseHas('ga_genres', ['name' => 'Action']);
    }

    public function test_pcgamingwiki_job_creates_links_and_ga_entities(): void
    {
        $pg = PcgwGame::create(['title' => 'Doom', 'clean_title' => 'Doom', 'release_year' => 1993]);
        $dev = PcgwCompany::create(['name' => 'id Software']);
        $pub = PcgwCompany::create(['name' => 'GT Interactive']);
        $pg->companies()->attach([$dev->id => ['role' => 'developer'], $pub->id => ['role' => 'publisher']]);

        // Add a PCGW mode and genre
        $mode = PcgwMode::create(['name' => 'Single-player']);
        $pg->modes()->attach($mode->id);
        $genre = PcgwGenre::create(['name' => 'Action']);
        $pg->genres()->attach($genre->id);

        (new AggregatePcgamingwikiGamesJob(chunkSize: 50))->handle(app(AggregationService::class));

        $this->assertDatabaseHas('ga_games', ['name' => 'Doom', 'release_year' => 1993, 'pcgamingwiki_game_id' => $pg->id]);
        $this->assertDatabaseHas('ga_companies', ['name' => 'id Software']);
        $this->assertDatabaseHas('ga_companies', ['name' => 'GT Interactive']);
        $this->assertDatabaseHas('ga_categories', ['name' => 'Single-player']);
        $this->assertDatabaseHas('ga_genres', ['name' => 'Action']);
    }

    public function test_jobs_skip_invalid_slug_names_and_empty_company_names(): void
    {
        $invalidGog = GogGame::create(['id' => 99, 'title' => '???', 'release_date_iso' => '1993-12-10']);
        $emptyDev = GogCompany::create(['name' => '   ']);
        $invalidGog->developers()->attach($emptyDev->id);

        $validDevOnlyGog = GogGame::create(['id' => 100, 'title' => 'Indie Gem', 'release_date_iso' => '2020-05-01']);
        $validDev = GogCompany::create(['name' => 'Solo Dev']);
        $validDevOnlyGog->developers()->attach($validDev->id);

        (new AggregateGogGamesJob(chunkSize: 50))->handle(app(AggregationService::class));

        $this->assertDatabaseMissing('ga_games', ['gog_game_id' => 99]);
        $this->assertDatabaseHas('ga_games', ['name' => 'Indie Gem', 'gog_game_id' => 100]);
    }

    public function test_job_unique_id_and_link_if_empty_handles_existing_link_and_unique_violation(): void
    {
        $job = new AggregateGogGamesJob(chunkSize: 50);
        $this->assertSame(AggregateGogGamesJob::class, $job->uniqueId());

        // Two GOG entries that resolve to the same GaGame ("Indie Gem"):
        // First one links gog_game_id = 201, second one sees gog_game_id already populated (empty $set).
        $dev = GogCompany::create(['name' => 'Solo Dev']);
        $g1 = GogGame::create(['id' => 201, 'title' => 'Indie Gem', 'release_date_iso' => '2020-05-01']);
        $g2 = GogGame::create(['id' => 202, 'title' => 'Indie Gem!', 'release_date_iso' => '2020-05-01']);
        $g1->developers()->attach($dev->id);
        $g2->developers()->attach($dev->id);

        $job->handle(app(AggregationService::class));

        $ga = GaGame::where('slug', 'indie-gem')->firstOrFail();
        $this->assertSame(201, $ga->gog_game_id);

        // Verify linkIfEmpty gracefully catches unique constraint violation if another GaGame already owns the link
        $otherGa = GaGame::create(['name' => 'Other Game', 'release_year' => 2021]);
        $ref = new \ReflectionMethod($job, 'linkIfEmpty');
        $ref->invoke($job, $otherGa, ['gog_game_id' => 201]);

        $this->assertNull($otherGa->fresh()->gog_game_id);
    }
}
