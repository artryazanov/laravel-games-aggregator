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
            if (! Schema::hasColumn('ga_games', 'second_steam_app_id')) {
                $t->unsignedBigInteger('second_steam_app_id')->nullable()->unique()->after('wikipedia_game_id');
                $t->foreign('second_steam_app_id')->references('id')->on('steam_apps')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ga_games')) {
            return;
        }

        Schema::table('ga_games', function (Blueprint $t) {
            if (Schema::hasColumn('ga_games', 'second_steam_app_id')) {
                // Drop FK if exists
                try {
                    $t->dropForeign(['second_steam_app_id']);
                } catch (\Throwable $e) {
                    // ignore if constraint name mismatch or absent
                }
                // Drop unique index if exists (Laravel default name)
                try {
                    $t->dropUnique('ga_games_second_steam_app_id_unique');
                } catch (\Throwable $e) {
                    // ignore
                }
                $t->dropColumn('second_steam_app_id');
            }
        });
    }
};
