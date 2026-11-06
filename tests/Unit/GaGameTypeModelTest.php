<?php

namespace Artryazanov\GamesAggregator\Tests\Unit;

use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Tests\TestCase;
use Artryazanov\GogScanner\Models\Game as GogGame;
use Artryazanov\LaravelSteamAppsDb\Enums\SteamAppType;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GaGameTypeModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_type_on_create_default_game(): void
    {
        $ga = GaGame::create(['name' => 'No Links Game']);
        $this->assertSame('game', $ga->type);
        $this->assertDatabaseHas('ga_games', ['id' => $ga->id, 'type' => 'game']);
    }

    public function test_sets_type_on_create_from_steam(): void
    {
        $app = SteamApp::create(['appid' => 5001, 'name' => 'Steam Linked']);
        SteamAppDetail::create([
            'steam_app_id' => $app->id,
            'type' => 'dlc',
            'name' => 'Steam Linked',
        ]);

        $ga = GaGame::create(['name' => 'Steam Linked', 'steam_app_id' => $app->id]);
        $this->assertSame('dlc', $ga->type);
        $this->assertDatabaseHas('ga_games', ['id' => $ga->id, 'type' => 'dlc']);
    }

    public function test_sets_type_on_create_from_gog_when_no_steam(): void
    {
        $gog = GogGame::create(['id' => 6002, 'title' => 'GOG Linked', 'game_type' => 'pack']);
        $ga = GaGame::create(['name' => 'GOG Linked', 'gog_game_id' => $gog->id]);
        $this->assertSame('pack', $ga->type);
        $this->assertDatabaseHas('ga_games', ['id' => $ga->id, 'type' => 'pack']);
    }

    public function test_updates_type_when_links_change_respecting_precedence(): void
    {
        // Start with GOG fallback
        $gog = GogGame::create(['id' => 7003, 'title' => 'Combo Game', 'game_type' => 'pack']);
        $ga = GaGame::create(['name' => 'Combo Game', 'gog_game_id' => $gog->id]);
        $this->assertSame('pack', $ga->type);

        // Add Steam detail with priority
        $app = SteamApp::create(['appid' => 70031, 'name' => 'Combo Game']);
        SteamAppDetail::create([
            'steam_app_id' => $app->id,
            'type' => 'demo',
            'name' => 'Combo Game',
        ]);

        $ga->update(['steam_app_id' => $app->id]);
        $ga->refresh();
        $this->assertSame('demo', $ga->type);
        $this->assertDatabaseHas('ga_games', ['id' => $ga->id, 'type' => 'demo']);

        // Changing unrelated wikipedia link should keep precedence calculation stable
        $ga->update(['wikipedia_game_id' => null]);
        $this->assertSame('demo', $ga->type);
    }

    public function test_sets_type_from_second_steam_app_when_primary_has_no_type(): void
    {
        $app1 = SteamApp::create(['appid' => 8001, 'name' => 'Primary No Detail']);
        $app2 = SteamApp::create(['appid' => 8002, 'name' => 'Secondary With Detail']);
        SteamAppDetail::create([
            'steam_app_id' => $app2->id,
            'type' => 'music',
            'name' => 'Secondary With Detail',
        ]);

        $ga = GaGame::create([
            'name' => 'Secondary Typed Game',
            'steam_app_id' => $app1->id,
            'second_steam_app_id' => $app2->id,
        ]);

        $this->assertSame('music', $ga->type);
    }

    public function test_preserves_explicit_type_when_no_links_are_dirty_and_supports_backed_enum(): void
    {
        $ga = GaGame::create(['name' => 'Custom Typed', 'type' => 'mod']);
        $this->assertSame('mod', $ga->type);

        $ref = new \ReflectionMethod(GaGame::class, 'cleanType');
        $ref->setAccessible(true);
        $this->assertSame('dlc', $ref->invoke(null, SteamAppType::DLC));
    }
}
