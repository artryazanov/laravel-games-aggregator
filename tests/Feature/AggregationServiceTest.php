<?php

namespace Artryazanov\GamesAggregator\Tests\Feature;

use Artryazanov\GamesAggregator\Models\GaCompany;
use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\GamesAggregator\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AggregationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_new_game_and_companies(): void
    {
        $service = app(AggregationService::class);

        $game = $service->findOrCreateGaGame('Doom', 1993, ['id Software'], ['GT Interactive']);

        $this->assertInstanceOf(GaGame::class, $game);
        $this->assertDatabaseHas('ga_games', ['id' => $game->id, 'name' => 'Doom', 'release_year' => 1993]);
        $this->assertDatabaseHas('ga_companies', ['name' => 'id Software']);
        $this->assertDatabaseHas('ga_companies', ['name' => 'GT Interactive']);

        $this->assertCount(1, $game->developers);
        $this->assertCount(1, $game->publishers);
    }

    public function test_matches_by_release_year_with_same_name(): void
    {
        $service = app(AggregationService::class);

        $existing = GaGame::create(['name' => 'Doom', 'release_year' => 1993]);
        $service->findOrCreateGaGame('Doom', 1993, ['Id Software'], ['GT Interactive']);

        $this->assertDatabaseCount('ga_games', 1);
        $this->assertDatabaseHas('ga_games', ['id' => $existing->id, 'name' => 'Doom', 'release_year' => 1993]);
    }

    public function test_matches_by_overlapping_developer(): void
    {
        $service = app(AggregationService::class);

        $company = GaCompany::create(['name' => 'id Software']);
        $existing = GaGame::create(['name' => 'Doom']);
        $existing->developers()->attach($company->id);

        $result = $service->findOrCreateGaGame('Doom', 1994, ['id Software'], ['Some Publisher']);

        $this->assertSame($existing->id, $result->id);
        $this->assertDatabaseHas('ga_games', ['id' => $existing->id, 'name' => 'Doom']);
    }
}
