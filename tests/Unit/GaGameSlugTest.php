<?php

namespace Artryazanov\GamesAggregator\Tests\Unit;

use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GaGameSlugTest extends TestCase
{
    use RefreshDatabase;

    public function test_slug_generated_on_create_basic(): void
    {
        $game = GaGame::create(['name' => 'Doom  Eternal']);

        $this->assertNotNull($game->slug);
        $this->assertSame('doom-eternal', $game->slug);
        $this->assertDatabaseHas('ga_games', ['id' => $game->id, 'slug' => 'doom-eternal']);
    }

    public function test_slug_generated_on_create_unicode(): void
    {
        $game = GaGame::create(['name' => 'Ведьмак 3: Дикая Охота']);

        $this->assertSame('ведьмак-3-дикая-охота', $game->slug);
        $this->assertDatabaseHas('ga_games', ['id' => $game->id, 'slug' => 'ведьмак-3-дикая-охота']);
    }
}

