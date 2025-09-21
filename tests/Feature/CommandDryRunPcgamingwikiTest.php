<?php

namespace Artryazanov\GamesAggregator\Tests\Feature;

use Artryazanov\GamesAggregator\Tests\TestCase;
use Artryazanov\PCGamingWiki\Models\Company as PcgwCompany;
use Artryazanov\PCGamingWiki\Models\Game as PcgwGame;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CommandDryRunPcgamingwikiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_pcgamingwiki_does_not_write_links_or_games(): void
    {
        $game = PcgwGame::create(['title' => 'Doom', 'clean_title' => 'Doom', 'release_year' => 1993]);
        $dev = PcgwCompany::create(['name' => 'id Software']);
        $pub = PcgwCompany::create(['name' => 'GT Interactive']);
        $game->companies()->attach([$dev->id => ['role' => 'developer'], $pub->id => ['role' => 'publisher']]);

        $this->artisan('ga:aggregate', ['--dry-run' => true, '--source' => ['pcgamingwiki'], '--limit' => 10])
            ->assertExitCode(0);

        $this->assertDatabaseCount('ga_games', 0);
        $this->assertDatabaseMissing('ga_games', ['pcgamingwiki_game_id' => $game->id]);
    }
}
