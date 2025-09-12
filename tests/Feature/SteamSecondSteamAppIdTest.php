<?php

namespace Artryazanov\GamesAggregator\Tests\Feature;

use Artryazanov\GamesAggregator\Jobs\AggregateSteamAppsJob;
use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Services\AggregationService;
use Artryazanov\GamesAggregator\Tests\TestCase;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppCategory;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppDetail;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppDeveloper;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppGenre;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SteamSecondSteamAppIdTest extends TestCase
{
    use RefreshDatabase;

    private function makeSteamApp(int $appid, string $name, string $releaseDate, string $devName, string $pubName, ?string $category = null, ?string $genre = null): SteamApp
    {
        $app = SteamApp::create(['appid' => $appid, 'name' => $name]);
        SteamAppDetail::create([
            'steam_app_id' => $app->id,
            'name' => $name,
            'release_date' => $releaseDate,
        ]);

        $dev = SteamAppDeveloper::firstOrCreate(['name' => $devName]);
        $pub = SteamAppPublisher::firstOrCreate(['name' => $pubName]);
        $app->developers()->attach($dev->id);
        $app->publishers()->attach($pub->id);

        if ($category) {
            $cat = SteamAppCategory::firstOrCreate(['category_id' => crc32($category) % 1000000, 'description' => $category]);
            $app->categories()->syncWithoutDetaching([$cat->id]);
        }
        if ($genre) {
            $g = SteamAppGenre::firstOrCreate(['genre_id' => (string) (crc32($genre) % 1000000), 'description' => $genre]);
            $app->genres()->syncWithoutDetaching([$g->id]);
        }

        return $app;
    }

    public function test_links_second_steam_app_id_when_primary_is_already_set(): void
    {
        $service = app(AggregationService::class);

        // Prepare two duplicate Steam apps (same game metadata)
        $app1 = $this->makeSteamApp(1001, 'Doom', '1993-12-10', 'id Software', 'GT Interactive');
        $app2 = $this->makeSteamApp(1002, 'Doom', '1993-12-10', 'id Software', 'GT Interactive');

        // Run the job once; both apps are processed in id order
        (new AggregateSteamAppsJob(chunkSize: 100))->handle($service);

        $ga = GaGame::first();
        $this->assertNotNull($ga, 'GA game must be created');
        $this->assertSame('Doom', $ga->name);
        $this->assertSame(1993, (int) $ga->release_year);

        // First app should be in steam_app_id, second app in second_steam_app_id
        $this->assertDatabaseHas('ga_games', ['id' => $ga->id, 'steam_app_id' => $app1->id]);
        $this->assertDatabaseHas('ga_games', ['id' => $ga->id, 'second_steam_app_id' => $app2->id]);

        // Idempotency: running again should not change anything
        (new AggregateSteamAppsJob(chunkSize: 100))->handle($service);
        $ga->refresh();
        $this->assertSame($app1->id, $ga->steam_app_id);
        $this->assertSame($app2->id, $ga->second_steam_app_id);
    }

    public function test_excludes_apps_already_linked_via_second_steam_app_id(): void
    {
        $service = app(AggregationService::class);

        // Create an app with a unique category marker
        $app = $this->makeSteamApp(2001, 'Quake', '1996-06-22', 'id Software', 'GT Interactive', category: 'Unique Marker');

        // Create unrelated GA game that already references this app via second_steam_app_id
        $ga = GaGame::create(['name' => 'Some Game', 'release_year' => 1996, 'second_steam_app_id' => $app->id]);
        $this->assertDatabaseHas('ga_games', ['id' => $ga->id, 'second_steam_app_id' => $app->id]);

        // Run the job; since NOT EXISTS covers second_steam_app_id, the app must be skipped entirely
        (new AggregateSteamAppsJob(chunkSize: 100))->handle($service);

        // If the app had been processed, its category would be synced into ga_categories. It must not be there.
        $this->assertDatabaseMissing('ga_categories', ['name' => 'Unique Marker']);

        // And no GA game should be created for Quake
        $this->assertDatabaseMissing('ga_games', ['name' => 'Quake']);
    }

    public function test_third_duplicate_is_ignored_when_both_ids_are_already_set(): void
    {
        $service = app(AggregationService::class);

        $app1 = $this->makeSteamApp(3001, 'Heretic', '1994-12-23', 'Raven Software', 'id Software');
        $app2 = $this->makeSteamApp(3002, 'Heretic', '1994-12-23', 'Raven Software', 'id Software');
        $app3 = $this->makeSteamApp(3003, 'Heretic', '1994-12-23', 'Raven Software', 'id Software');

        (new AggregateSteamAppsJob(chunkSize: 100))->handle($service);

        $ga = GaGame::where('name', 'Heretic')->firstOrFail();
        $this->assertSame($app1->id, $ga->steam_app_id);
        $this->assertSame($app2->id, $ga->second_steam_app_id);

        // Ensure the third app was not linked anywhere
        $this->assertDatabaseMissing('ga_games', ['steam_app_id' => $app3->id]);
        $this->assertDatabaseMissing('ga_games', ['second_steam_app_id' => $app3->id]);
    }
}
