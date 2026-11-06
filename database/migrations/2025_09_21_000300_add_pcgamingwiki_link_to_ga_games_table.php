<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ga_games')) {
            return;
        }

        Schema::table('ga_games', function (Blueprint $t) {
            if (! Schema::hasColumn('ga_games', 'pcgamingwiki_game_id')) {
                $t->unsignedBigInteger('pcgamingwiki_game_id')->nullable()->unique()->after('wikipedia_game_id');
                $t->foreign('pcgamingwiki_game_id')->references('id')->on('pcgw_games')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ga_games')) {
            return;
        }

        Schema::table('ga_games', function (Blueprint $t) {
            if (Schema::hasColumn('ga_games', 'pcgamingwiki_game_id')) {
                try {
                    $t->dropForeign(['pcgamingwiki_game_id']);
                } catch (Throwable $e) {
                    // ignore if constraint name mismatch or absent
                }
                try {
                    $t->dropUnique('ga_games_pcgamingwiki_game_id_unique');
                } catch (Throwable $e) {
                    // ignore
                }
                $t->dropColumn('pcgamingwiki_game_id');
            }
        });
    }
};
