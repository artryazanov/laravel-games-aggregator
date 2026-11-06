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
use Illuminate\Support\Facades\DB;

class DeduplicateBySlugMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_deduplicates_by_slug_keeps_lowest_id(): void
    {
        $g1 = GaGame::create(['name' => 'Doom']);   // slug: doom
        $g2 = GaGame::create(['name' => 'Doom ']);  // slug: doom
        $g3 = GaGame::create(['name' => 'Doom!']);  // slug: doom

        $this->assertDatabaseCount('ga_games', 3);

        // Run the specific migration's up() to deduplicate
        $pkgBase = dirname(__DIR__, 2);
        $migration = require $pkgBase.'/database/migrations/2025_09_05_000100_deduplicate_ga_games_by_slug.php';
        $migration->up();

        $this->assertDatabaseCount('ga_games', 1);
        $this->assertDatabaseHas('ga_games', ['id' => $g1->id, 'slug' => 'doom']);
        $this->assertDatabaseMissing('ga_games', ['id' => $g2->id]);
        $this->assertDatabaseMissing('ga_games', ['id' => $g3->id]);
    }

    public function test_deletes_ga_games_with_mismatched_slug_vs_name(): void
    {
        $valid = GaGame::create(['name' => 'Doom Eternal']);
        $mismatched = GaGame::create(['name' => 'Quake II', 'slug' => 'wrong-slug']);
        $emptySlug = GaGame::create(['name' => 'Heretic']);
        DB::table('ga_games')->where('id', $emptySlug->id)->update(['slug' => '']);

        $pkgBase = dirname(__DIR__, 2);
        $migration = require $pkgBase.'/database/migrations/2025_09_17_000300_delete_ga_games_with_mismatched_slug_vs_name.php';
        $migration->up();

        $this->assertDatabaseHas('ga_games', ['id' => $valid->id]);
        $this->assertDatabaseMissing('ga_games', ['id' => $mismatched->id]);
        $this->assertDatabaseMissing('ga_games', ['id' => $emptySlug->id]);
    }

    public function test_deletes_ga_games_with_mismatched_wikipedia_titles(): void
    {
        $wp = Wikipage::create(['title' => 'Doom (1993 video game)', 'wikipedia_url' => 'https://en.wikipedia.org/wiki/Doom']);
        $wg = WikipediaGame::create(['wikipage_id' => $wp->id, 'clean_title' => 'Doom', 'release_year' => 1993]);

        $badGa = GaGame::create(['name' => 'Doom (1993 video game)', 'wikipedia_game_id' => $wg->id]);
        $goodGa = GaGame::create(['name' => 'Quake']);

        $pkgBase = dirname(__DIR__, 2);
        $migration = require $pkgBase.'/database/migrations/2025_09_03_000100_delete_ga_games_with_mismatched_wikipedia_titles.php';
        $migration->up();

        $this->assertDatabaseMissing('ga_games', ['id' => $badGa->id]);
        $this->assertDatabaseHas('ga_games', ['id' => $goodGa->id]);
    }

    public function test_backfills_min_release_year_and_type_migrations(): void
    {
        $gog = GogGame::create(['id' => 501, 'title' => 'Multi Linked', 'release_date_iso' => '1995-01-01', 'game_type' => 'dlc']);
        $app = SteamApp::create(['appid' => 502, 'name' => 'Multi Linked']);
        SteamAppDetail::create(['steam_app_id' => $app->id, 'name' => 'Multi Linked', 'release_date' => '1993-01-01', 'type' => 'demo']);

        $ga = GaGame::create([
            'name' => 'Multi Linked',
            'gog_game_id' => $gog->id,
            'steam_app_id' => $app->id,
        ]);

        // Force wrong release_year and null type in DB to test the backfill migrations
        DB::table('ga_games')->where('id', $ga->id)->update(['release_year' => 2005, 'type' => null]);

        $pkgBase = dirname(__DIR__, 2);
        $yearMigration = require $pkgBase.'/database/migrations/2025_09_04_000000_set_release_year_min_from_sources.php';
        $yearMigration->up();

        $typeMigration = require $pkgBase.'/database/migrations/2025_09_05_000200_add_type_to_ga_games_and_backfill.php';
        $typeMigration->up();

        $this->assertDatabaseHas('ga_games', [
            'id' => $ga->id,
            'release_year' => 1993,
            'type' => 'demo',
        ]);
    }
}
