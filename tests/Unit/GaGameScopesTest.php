<?php

namespace Artryazanov\GamesAggregator\Tests\Unit;

use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Tests\TestCase;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GaGameScopesTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_by_slug_matches_created_game(): void
    {
        $game = GaGame::create(['name' => 'Doom']);

        $found = GaGame::bySlug('doom')->first();

        $this->assertNotNull($found);
        $this->assertSame($game->id, $found->id);
    }

    public function test_scope_with_sources_eager_loads_relations(): void
    {
        $app1 = SteamApp::create(['appid' => 100, 'name' => 'Doom']);
        $app2 = SteamApp::create(['appid' => 101, 'name' => 'Doom II']);

        $game = GaGame::create([
            'name' => 'Doom',
            'steam_app_id' => $app1->id,
            'second_steam_app_id' => $app2->id,
        ]);

        $loaded = GaGame::withSources()->find($game->id);

        $this->assertTrue($loaded->relationLoaded('steamApp'));
        $this->assertTrue($loaded->relationLoaded('secondSteamApp'));
        $this->assertSame($app1->id, optional($loaded->steamApp)->id);
        $this->assertSame($app2->id, optional($loaded->secondSteamApp)->id);
    }
}

