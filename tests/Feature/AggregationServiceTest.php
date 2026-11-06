<?php

namespace Artryazanov\GamesAggregator\Tests\Feature;

use Artryazanov\GamesAggregator\Models\GaCategory;
use Artryazanov\GamesAggregator\Models\GaCompany;
use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Models\GaGenre;
use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\GamesAggregator\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

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

    public function test_matches_by_slug_even_if_name_differs(): void
    {
        $service = app(AggregationService::class);

        $existing = GaGame::create(['name' => 'Doom', 'release_year' => 1993]);

        // Name differs but slug is the same ("Doom!" -> slug "doom")
        $matched = $service->findOrCreateGaGame('Doom!', 1993, ['Id Software'], ['GT Interactive']);

        $this->assertSame($existing->id, $matched->id);
        $this->assertDatabaseCount('ga_games', 1);
        $this->assertDatabaseHas('ga_games', ['id' => $existing->id, 'slug' => 'doom']);
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

    public function test_matches_by_overlapping_publisher_when_developers_empty(): void
    {
        $service = app(AggregationService::class);

        $publisher = GaCompany::create(['name' => 'GT Interactive']);
        $existing = GaGame::create(['name' => 'Doom', 'release_year' => 1993]);
        $existing->publishers()->attach($publisher->id);

        $result = $service->findOrCreateGaGame('Doom', 1994, [], ['GT Interactive']);
        $this->assertSame($existing->id, $result->id);

        $sim = $service->simulateDecision('Doom', 1994, [], ['GT Interactive']);
        $this->assertSame($existing->id, $sim['matched_game_id']);
        $this->assertSame(0, $sim['overlap_devs']);
        $this->assertSame(1, $sim['overlap_pubs']);

        $invalidSim = $service->simulateDecision('???', 1994, [], []);
        $this->assertNull($invalidSim['matched_game_id']);
        $this->assertFalse($invalidSim['would_create_game']);

        $ids = $service->ensureCompanies(['GT Interactive', 'GT Interactive', '   ']);
        $this->assertSame([$publisher->id], $ids);
    }

    public function test_updates_release_year_when_incoming_year_is_earlier(): void
    {
        $service = app(AggregationService::class);

        $company = GaCompany::create(['name' => 'id Software']);
        $existing = GaGame::create(['name' => 'Doom', 'release_year' => 1995]);
        $existing->developers()->attach($company->id);

        $result = $service->findOrCreateGaGame('Doom', 1993, ['id Software'], []);

        $this->assertSame($existing->id, $result->id);
        $this->assertSame(1993, $result->fresh()->release_year);
    }

    public function test_throws_exception_when_name_produces_empty_slug(): void
    {
        $service = app(AggregationService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->findOrCreateGaGame('???', 1993, ['id Software'], []);
    }

    public function test_simulate_decision_and_inverse_relations(): void
    {
        $service = app(AggregationService::class);

        $game = $service->findOrCreateGaGame('Doom', 1993, ['id Software'], ['GT Interactive']);
        $catIds = $service->ensureCategories(['Single-player']);
        $genreIds = $service->ensureGenres(['Action']);
        $game->categories()->sync($catIds);
        $game->genres()->sync($genreIds);

        $dev = GaCompany::where('name', 'id Software')->firstOrFail();
        $pub = GaCompany::where('name', 'GT Interactive')->firstOrFail();
        $cat = GaCategory::findOrFail($catIds[0]);
        $genre = GaGenre::findOrFail($genreIds[0]);

        $this->assertCount(1, $dev->developedGames);
        $this->assertCount(1, $pub->publishedGames);
        $this->assertCount(1, $cat->games);
        $this->assertCount(1, $genre->games);

        $sim = $service->simulateDecision('Doom', 1993, ['id Software'], ['New Publisher']);
        $this->assertSame($game->id, $sim['matched_game_id']);
        $this->assertTrue($sim['release_year_match']);
        $this->assertFalse($sim['would_create_game']);
        $this->assertSame(['New Publisher'], $sim['missing_companies']);
        $this->assertSame([$dev->id], $sim['existing_company_ids']);
    }
}
