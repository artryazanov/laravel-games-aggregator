<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safety checks to avoid errors if dependencies are missing
        if (! Schema::hasTable('ga_games') || ! Schema::hasTable('wikipedia_games') || ! Schema::hasTable('wikipedia_game_wikipages')) {
            return;
        }

        // Collect GA game IDs where GA name equals wikipage.title but title != wikipedia_games.clean_title
        $ids = DB::table('ga_games as g')
            ->join('wikipedia_games as wg', 'wg.id', '=', 'g.wikipedia_game_id')
            ->join('wikipedia_game_wikipages as wp', 'wp.id', '=', 'wg.wikipage_id')
            ->whereColumn('g.name', '=', 'wp.title')
            ->whereColumn('wp.title', '<>', 'wg.clean_title')
            ->pluck('g.id');

        if ($ids->isEmpty()) {
            return;
        }

        foreach (array_chunk($ids->toArray(), 500) as $chunk) {
            DB::table('ga_games')->whereIn('id', $chunk)->delete();
        }
    }

    public function down(): void
    {
        // This migration is destructive (deletes rows) and cannot be reversed.
    }
};
