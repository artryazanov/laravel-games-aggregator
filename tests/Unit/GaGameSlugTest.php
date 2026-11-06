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

    public function test_slug_updated_automatically_when_name_changes(): void
    {
        $game = GaGame::create(['name' => 'Original Title']);
        $this->assertSame('original-title', $game->slug);

        $game->update(['name' => 'Updated Title: Remastered']);
        $this->assertSame('updated-title-remastered', $game->slug);
        $this->assertDatabaseHas('ga_games', ['id' => $game->id, 'slug' => 'updated-title-remastered']);
    }

    public function test_explicit_slug_is_preserved_on_create_and_update(): void
    {
        $game = GaGame::create(['name' => 'Doom', 'slug' => 'custom-doom-slug']);
        $this->assertSame('custom-doom-slug', $game->slug);

        $game->update(['name' => 'Doom II', 'slug' => 'custom-doom-2-slug']);
        $this->assertSame('custom-doom-2-slug', $game->slug);
    }
}
