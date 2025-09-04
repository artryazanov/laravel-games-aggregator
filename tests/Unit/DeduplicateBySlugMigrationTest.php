<?php

namespace Artryazanov\GamesAggregator\Tests\Unit;

use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
}
