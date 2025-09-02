<?php

namespace Artryazanov\GamesAggregator\Tests\Feature;

use Artryazanov\GamesAggregator\Tests\TestCase;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppDetail;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppDeveloper;
use Artryazanov\LaravelSteamAppsDb\Models\SteamAppPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CommandDryRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write_links_or_games(): void
    {
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

        $this->artisan('ga:aggregate', ['--dry-run' => true, '--source' => ['steam'], '--limit' => 10])
            ->assertExitCode(0);

        $this->assertDatabaseCount('ga_games', 0);
        $this->assertDatabaseCount('ga_steam_app_links', 0);
    }
}

