<?php

namespace Artryazanov\GamesAggregator\Tests\Unit;

use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Tests\TestCase;
use Artryazanov\GogScanner\Models\Game as GogGame;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppDetail;
use Artryazanov\WikipediaGamesDb\Models\Game as WikipediaGame;
use Artryazanov\WikipediaGamesDb\Models\Wikipage;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GaGameReleaseYearUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_release_year_from_gog_on_link(): void
    {
        $gog = GogGame::create([
            'id' => 123,
            'title' => 'Some Game',
            'release_date_iso' => '1995-01-02',
        ]);

        $ga = GaGame::create(['name' => 'Some Game', 'release_year' => 1996]);

        $ga->update(['gog_game_id' => $gog->id]);

        $this->assertDatabaseHas('ga_games', [
            'id' => $ga->id,
            'release_year' => 1995,
            'gog_game_id' => $gog->id,
        ]);
    }

    public function test_keeps_smaller_release_year_when_gog_is_later(): void
    {
        $gog = GogGame::create([
            'id' => 124,
            'title' => 'Some Game',
            'release_date_iso' => '1995-01-02',
        ]);

        $ga = GaGame::create(['name' => 'Some Game', 'release_year' => 1993]);

        $ga->update(['gog_game_id' => $gog->id]);

        $this->assertDatabaseHas('ga_games', [
            'id' => $ga->id,
            'release_year' => 1993,
            'gog_game_id' => $gog->id,
        ]);
    }

    public function test_sets_release_year_when_null_from_gog(): void
    {
        $gog = GogGame::create([
            'id' => 125,
            'title' => 'Some Game',
            'release_date_iso' => '1994-07-10',
        ]);

        $ga = GaGame::create(['name' => 'Some Game', 'release_year' => null]);

        $ga->update(['gog_game_id' => $gog->id]);

        $this->assertDatabaseHas('ga_games', [
            'id' => $ga->id,
            'release_year' => 1994,
            'gog_game_id' => $gog->id,
        ]);
    }

    public function test_sets_release_year_from_steam_on_link(): void
    {
        $app = SteamApp::create(['appid' => 1000, 'name' => 'Some Game']);
        SteamAppDetail::create([
            'steam_app_id' => $app->id,
            'name' => 'Some Game',
            'release_date' => '1992-12-10',
        ]);

        $ga = GaGame::create(['name' => 'Some Game', 'release_year' => 1996]);

        $ga->update(['steam_app_id' => $app->id]);

        $this->assertDatabaseHas('ga_games', [
            'id' => $ga->id,
            'release_year' => 1992,
            'steam_app_id' => $app->id,
        ]);
    }

    public function test_sets_release_year_from_wikipedia_on_link(): void
    {
        $wp = Wikipage::create(['title' => 'Some Game', 'wikipedia_url' => 'https://en.wikipedia.org/wiki/Some_Game']);
        $wiki = WikipediaGame::create(['wikipage_id' => $wp->id, 'clean_title' => 'Some Game', 'release_year' => 1991]);

        $ga = GaGame::create(['name' => 'Some Game', 'release_year' => 1996]);

        $ga->update(['wikipedia_game_id' => $wiki->id]);

        $this->assertDatabaseHas('ga_games', [
            'id' => $ga->id,
            'release_year' => 1991,
            'wikipedia_game_id' => $wiki->id,
        ]);
    }

    public function test_uses_min_year_across_multiple_new_links(): void
    {
        $app = SteamApp::create(['appid' => 2000, 'name' => 'Some Game']);
        SteamAppDetail::create([
            'steam_app_id' => $app->id,
            'name' => 'Some Game',
            'release_date' => '1994-05-03',
        ]);
        $wp = Wikipage::create(['title' => 'Some Game', 'wikipedia_url' => 'https://en.wikipedia.org/wiki/Some_Game']);
        $wiki = WikipediaGame::create(['wikipage_id' => $wp->id, 'clean_title' => 'Some Game', 'release_year' => 1996]);

        $ga = GaGame::create(['name' => 'Some Game', 'release_year' => 1998]);

        // Set both links in a single save to ensure both source years are considered
        $ga->steam_app_id = $app->id;
        $ga->wikipedia_game_id = $wiki->id;
        $ga->save();

        $this->assertDatabaseHas('ga_games', [
            'id' => $ga->id,
            'release_year' => 1994, // min(1998, 1994, 1996)
            'steam_app_id' => $app->id,
            'wikipedia_game_id' => $wiki->id,
        ]);
    }
}

