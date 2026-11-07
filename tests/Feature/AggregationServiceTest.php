<?php

namespace Artryazanov\GamesAggregator\Tests\Feature;

use Artryazanov\GamesAggregator\Models\GaCategory;
use Artryazanov\GamesAggregator\Models\GaCompany;
use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Models\GaGenre;
use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\GamesAggregator\Tests\TestCase;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppCategory;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppDetail;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppDeveloper;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppGenre;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppPublisher;
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

    public function test_aggregate_steam_app_creates_and_updates_already_linked_ga_game(): void
    {
        $service = app(AggregationService::class);

        // App without detail or valid slug is skipped
        $invalidApp = SteamApp::create(['appid' => 999, 'name' => '???']);
        $this->assertNull($service->aggregateSteamApp($invalidApp, strict: false));

        $appWithoutDetails = SteamApp::create(['appid' => 1000, 'name' => 'No Details App']);
        $this->assertNull($service->aggregateSteamApp($appWithoutDetails, strict: true));
        $this->assertNull($service->aggregateSteamApp($appWithoutDetails, strict: false));

        // Non-strict mode creates GaGame even when release_date and companies are absent
        SteamAppDetail::create([
            'steam_app_id' => $appWithoutDetails->id,
            'name' => 'No Details App',
            'type' => 'game',
            'release_date' => null,
        ]);
        $appWithoutDetails->refresh();
        $createdNonStrict = $service->aggregateSteamApp($appWithoutDetails, strict: false);
        $this->assertNotNull($createdNonStrict);
        $this->assertSame($appWithoutDetails->id, $createdNonStrict->steam_app_id);

        // When already linked SteamApp gets updated details, developers, publishers, categories, and genres
        $dev = SteamAppDeveloper::create(['name' => 'Valve']);
        $pub = SteamAppPublisher::create(['name' => 'Valve']);
        $cat = SteamAppCategory::create(['category_id' => 1, 'description' => 'Multi-player']);
        $genre = SteamAppGenre::create(['genre_id' => '1', 'description' => 'Action']);

        $appWithoutDetails->detail->update([
            'release_date' => '2023-09-27',
            'type' => 'dlc',
        ]);
        $appWithoutDetails->developers()->attach($dev->id);
        $appWithoutDetails->publishers()->attach($pub->id);
        $appWithoutDetails->categories()->attach($cat->id);
        $appWithoutDetails->genres()->attach($genre->id);

        $aggregatedCollection = $service->aggregateSteamApps([$appWithoutDetails, 'not-an-app'], strict: false);
        $this->assertCount(1, $aggregatedCollection);

        $updatedGa = $aggregatedCollection->first()->fresh(['developers', 'publishers', 'categories', 'genres']);
        $this->assertSame($createdNonStrict->id, $updatedGa->id);
        $this->assertSame(2023, $updatedGa->release_year);
        $this->assertSame('dlc', $updatedGa->type);
        $this->assertSame(['Valve'], $updatedGa->developers->pluck('name')->all());
        $this->assertSame(['Valve'], $updatedGa->publishers->pluck('name')->all());
        $this->assertSame(['Multi-player'], $updatedGa->categories->pluck('name')->all());
        $this->assertSame(['Action'], $updatedGa->genres->pluck('name')->all());

        // Calling aggregateSteamApp again when nothing changed (isDirty() is false)
        $unchangedGa = $service->aggregateSteamApp($appWithoutDetails->fresh(), strict: false);
        $this->assertSame($updatedGa->id, $unchangedGa->id);
    }

    public function test_aggregate_steam_app_strict_filter_and_second_steam_app_linking(): void
    {
        $service = app(AggregationService::class);

        // Strict mode requires at least one developer or publisher even when release_date is present
        $appNoCompanies = SteamApp::create(['appid' => 2001, 'name' => 'Orphan Game']);
        SteamAppDetail::create([
            'steam_app_id' => $appNoCompanies->id,
            'name' => 'Orphan Game',
            'type' => 'game',
            'release_date' => '2024-01-15',
        ]);
        $this->assertNull($service->aggregateSteamApp($appNoCompanies, strict: true));

        // Non-strict mode without release_date and companies reuses existing unlinked GaGame with same slug
        $preExistingGa = GaGame::create(['name' => 'Reuse Slug Game', 'release_year' => null]);
        $appReuse = SteamApp::create(['appid' => 2002, 'name' => 'Reuse Slug Game']);
        SteamAppDetail::create([
            'steam_app_id' => $appReuse->id,
            'name' => 'Reuse Slug Game',
            'type' => 'game',
            'release_date' => null,
        ]);
        $linkedReuse = $service->aggregateSteamApp($appReuse, strict: false);
        $this->assertNotNull($linkedReuse);
        $this->assertSame($preExistingGa->id, $linkedReuse->id);
        $this->assertSame($appReuse->id, $linkedReuse->fresh()->steam_app_id);

        // Second SteamApp with same title and matching release year links to second_steam_app_id
        $dev = SteamAppDeveloper::create(['name' => 'Remedy']);
        $appReuse->detail->update(['release_date' => '2020-05-01', 'type' => 'game']);
        $appReuse->developers()->attach($dev->id);
        $service->aggregateSteamApp($appReuse->fresh(), strict: false);

        $secondApp = SteamApp::create(['appid' => 2003, 'name' => 'Reuse Slug Game']);
        SteamAppDetail::create([
            'steam_app_id' => $secondApp->id,
            'name' => 'Reuse Slug Game',
            'type' => 'demo',
            'release_date' => '2020-05-01',
        ]);
        $secondApp->developers()->attach($dev->id);

        $linkedSecond = $service->aggregateSteamApp($secondApp, strict: false);
        $this->assertNotNull($linkedSecond);
        $this->assertSame($preExistingGa->id, $linkedSecond->id);
        $this->assertSame($secondApp->id, $linkedSecond->fresh()->second_steam_app_id);

        // Updating via second_steam_app_id updates release_year if earlier, but preserves primary type ('game')
        $secondApp->detail->update(['release_date' => '2019-12-01', 'type' => 'demo']);
        $updatedViaSecond = $service->aggregateSteamApp($secondApp->fresh(), strict: false);
        $this->assertSame(2019, $updatedViaSecond->fresh()->release_year);
        $this->assertSame('game', $updatedViaSecond->fresh()->type);
    }
}
