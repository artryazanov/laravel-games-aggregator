<?php

namespace Artryazanov\GamesAggregator\Tests\Feature;

use Artryazanov\GamesAggregator\Jobs\AggregateGogGamesJob;
use Artryazanov\GamesAggregator\Jobs\AggregatePcgamingwikiGamesJob;
use Artryazanov\GamesAggregator\Jobs\AggregateSteamAppsJob;
use Artryazanov\GamesAggregator\Jobs\AggregateWikipediaGamesJob;
use Artryazanov\GamesAggregator\Models\GaCompany;
use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Tests\TestCase;
use Artryazanov\GogScanner\Models\Company as GogCompany;
use Artryazanov\GogScanner\Models\Game as GogGame;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppDetail;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppDeveloper;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppPublisher;
use Artryazanov\PCGamingWiki\Models\Company as PcgwCompany;
use Artryazanov\PCGamingWiki\Models\Game as PcgwGame;
use Artryazanov\WikipediaGamesDb\Models\Company as WikiCompany;
use Artryazanov\WikipediaGamesDb\Models\Game as WikipediaGame;
use Artryazanov\WikipediaGamesDb\Models\Wikipage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

class CommandDryRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write_links_or_games(): void
    {
        $existingGa = GaGame::create(['name' => 'Doom', 'release_year' => 1993]);
        $existingDev = GaCompany::create(['name' => 'id Software']);
        $existingGa->developers()->attach($existingDev->id);

        $app = SteamApp::create(['appid' => 200, 'name' => 'Doom']);
        SteamAppDetail::create([
            'steam_app_id' => $app->id,
            'name' => 'Doom',
            'release_date' => '1993-12-10',
        ]);
        $dev = SteamAppDeveloper::create(['name' => 'id Software']);
        $pub = SteamAppPublisher::create(['name' => 'GT Interactive']);
        $app->developers()->attach($dev->id);
        $app->publishers()->attach($pub->id);

        $app2 = SteamApp::create(['appid' => 201, 'name' => 'Wolfenstein 3D']);
        SteamAppDetail::create([
            'steam_app_id' => $app2->id,
            'name' => 'Wolfenstein 3D',
            'release_date' => '1992-05-05',
        ]);
        $app2->developers()->attach($dev->id);

        $gog1 = GogGame::create(['id' => 299, 'title' => 'Doom', 'release_date_iso' => '1993-12-10']);
        $gog2 = GogGame::create(['id' => 300, 'title' => 'Quake', 'release_date_iso' => '1996-06-22']);
        $gogDev = GogCompany::create(['name' => 'id Software']);
        $gog1->developers()->attach($gogDev->id);
        $gog2->developers()->attach($gogDev->id);

        $wp1 = Wikipage::create(['title' => 'Doom', 'wikipedia_url' => 'https://en.wikipedia.org/wiki/Doom']);
        $wg1 = WikipediaGame::create([
            'wikipage_id' => $wp1->id,
            'clean_title' => 'Doom',
            'release_year' => null,
            'release_date' => '1993-12-10',
        ]);
        $wikiIdDev = WikiCompany::create(['name' => 'id Software']);
        $wg1->companies()->attach([$wikiIdDev->id => ['role' => 'developer']]);

        $wp2 = Wikipage::create(['title' => 'Heretic (video game)', 'wikipedia_url' => 'https://en.wikipedia.org/wiki/Heretic']);
        $wg2 = WikipediaGame::create(['wikipage_id' => $wp2->id, 'clean_title' => 'Heretic', 'release_year' => 1994]);
        $wikiDev = WikiCompany::create(['name' => 'Raven Software']);
        $wg2->companies()->attach([$wikiDev->id => ['role' => 'developer']]);

        $pg1 = PcgwGame::create(['title' => 'Doom', 'clean_title' => 'Doom', 'release_year' => 1993]);
        $pg2 = PcgwGame::create(['title' => 'Hexen', 'clean_title' => 'Hexen', 'release_year' => 1995]);
        $pcgwDev = PcgwCompany::create(['name' => 'id Software']);
        $pg1->companies()->attach([$pcgwDev->id => ['role' => 'developer']]);
        $pg2->companies()->attach([$pcgwDev->id => ['role' => 'developer']]);

        $this->artisan('ga:aggregate', [
            '--dry-run' => true,
            '--source' => ['steam', 'gog', 'wikipedia', 'pcgamingwiki', 'unknown'],
            '--limit' => 10,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('ga_games', 1);
        $this->assertNull($existingGa->fresh()->steam_app_id);
        $this->assertNull($existingGa->fresh()->gog_game_id);
        $this->assertNull($existingGa->fresh()->wikipedia_game_id);
        $this->assertNull($existingGa->fresh()->pcgamingwiki_game_id);
    }

    public function test_command_dispatches_aggregation_jobs(): void
    {
        Bus::fake();

        $this->artisan('ga:aggregate', ['--chunk' => 250, '--source' => ['gog', 'steam', 'wikipedia', 'pcgamingwiki', 'unknown']])
            ->assertExitCode(0);

        Bus::assertDispatched(AggregateGogGamesJob::class, fn ($job) => $job->chunkSize === 250);
        Bus::assertDispatched(AggregateSteamAppsJob::class, fn ($job) => $job->chunkSize === 250);
        Bus::assertDispatched(AggregateWikipediaGamesJob::class, fn ($job) => $job->chunkSize === 250);
        Bus::assertDispatched(AggregatePcgamingwikiGamesJob::class, fn ($job) => $job->chunkSize === 250);
    }
}
