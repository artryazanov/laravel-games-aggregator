<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga_games', function (Blueprint $t) {
            // Add link to PCGamingWiki game
            $t->unsignedBigInteger('pcgamingwiki_game_id')->nullable()->unique()->after('wikipedia_game_id');
            $t->foreign('pcgamingwiki_game_id')->references('id')->on('pcgw_games')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ga_games', function (Blueprint $t) {
            $t->dropForeign(['pcgamingwiki_game_id']);
            $t->dropColumn('pcgamingwiki_game_id');
        });
    }
};
