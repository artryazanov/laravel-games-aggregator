<?php

namespace Artryazanov\GamesAggregator\Tests\Unit;

use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Tests\TestCase;
use Artryazanov\GogScanner\Models\Game as GogGame;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppDetail;
use Artryazanov\PCGamingWiki\Models\Game as PcgwGame;
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

    public function test_sets_release_year_from_pcgamingwiki_on_link(): void
    {
        $pg = PcgwGame::create([
            'title' => 'Some Game',
            'clean_title' => 'Some Game',
            'release_year' => 1990,
        ]);

        $ga = GaGame::create(['name' => 'Some Game', 'release_year' => 1996]);

        $ga->update(['pcgamingwiki_game_id' => $pg->id]);

        $this->assertDatabaseHas('ga_games', [
            'id' => $ga->id,
            'release_year' => 1990,
            'pcgamingwiki_game_id' => $pg->id,
        ]);
    }

    public function test_sets_release_year_from_second_steam_app_on_link(): void
    {
        $app = SteamApp::create(['appid' => 3000, 'name' => 'Some Game Classic']);
        SteamAppDetail::create([
            'steam_app_id' => $app->id,
            'name' => 'Some Game Classic',
            'release_date' => '1991-06-15',
        ]);

        $ga = GaGame::create(['name' => 'Some Game', 'release_year' => 1996]);

        $ga->update(['second_steam_app_id' => $app->id]);

        $this->assertDatabaseHas('ga_games', [
            'id' => $ga->id,
            'release_year' => 1991,
            'second_steam_app_id' => $app->id,
        ]);
    }

    public function test_extracts_year_from_gog_timestamp_and_wikipedia_release_date(): void
    {
        $gog = GogGame::create([
            'id' => 126,
            'title' => 'Timestamp Game',
            'release_date_ts' => gmmktime(0, 0, 0, 5, 10, 1997),
        ]);
        $this->assertSame(1997, GaGame::extractYearFromGog($gog));

        $emptyGog = GogGame::create([
            'id' => 127,
            'title' => 'No Date Game',
        ]);
        $this->assertNull(GaGame::extractYearFromGog($emptyGog));

        $wp = Wikipage::create(['title' => 'Date Only Wiki', 'wikipedia_url' => 'https://en.wikipedia.org/wiki/Date_Only']);
        $wiki = WikipediaGame::create([
            'wikipage_id' => $wp->id,
            'clean_title' => 'Date Only Wiki',
            'release_year' => null,
            'release_date' => '1998-11-19',
        ]);

        $ga = GaGame::create(['name' => 'Date Only Wiki', 'release_year' => 2000]);
        $ga->update(['wikipedia_game_id' => $wiki->id]);

        $this->assertSame(1998, $ga->fresh()->release_year);
    }

    public function test_uses_preloaded_relations_on_save(): void
    {
        $gog = GogGame::create(['id' => 128, 'title' => 'Preloaded', 'release_date_iso' => '1999-01-01', 'game_type' => 'game']);
        $app1 = SteamApp::create(['appid' => 4001, 'name' => 'Preloaded']);
        $app2 = SteamApp::create(['appid' => 4002, 'name' => 'Preloaded 2']);
        $wp = Wikipage::create(['title' => 'Preloaded', 'wikipedia_url' => 'https://en.wikipedia.org/wiki/Preloaded']);
        $wiki = WikipediaGame::create(['wikipage_id' => $wp->id, 'clean_title' => 'Preloaded', 'release_year' => 1999]);
        $pg = PcgwGame::create(['title' => 'Preloaded', 'clean_title' => 'Preloaded', 'release_year' => 1999]);

        $ga = new GaGame(['name' => 'Preloaded']);
        $ga->gog_game_id = $gog->id;
        $ga->steam_app_id = $app1->id;
        $ga->second_steam_app_id = $app2->id;
        $ga->wikipedia_game_id = $wiki->id;
        $ga->pcgamingwiki_game_id = $pg->id;

        $ga->setRelation('gogGame', $gog);
        $ga->setRelation('steamApp', $app1);
        $ga->setRelation('secondSteamApp', $app2);
        $ga->setRelation('wikipediaGame', $wiki);
        $ga->setRelation('pcgamingwikiGame', $pg);
        $ga->save();

        $this->assertSame(1999, $ga->release_year);
        $this->assertSame('game', $ga->type);
    }
}
