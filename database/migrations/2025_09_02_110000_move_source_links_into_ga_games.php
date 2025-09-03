<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga_games', function (Blueprint $t) {
            $t->unsignedBigInteger('gog_game_id')->nullable()->unique()->after('release_year');
            $t->unsignedBigInteger('steam_app_id')->nullable()->unique()->after('gog_game_id');
            $t->unsignedBigInteger('wikipedia_game_id')->nullable()->unique()->after('steam_app_id');

            $t->foreign('gog_game_id')->references('id')->on('gog_games')->cascadeOnDelete();
            $t->foreign('steam_app_id')->references('id')->on('steam_apps')->cascadeOnDelete();
            $t->foreign('wikipedia_game_id')->references('id')->on('wikipedia_games')->cascadeOnDelete();
        });

        // migrate existing links
        if (Schema::hasTable('ga_gog_game_links')) {
            foreach (DB::table('ga_gog_game_links')->get() as $row) {
                DB::table('ga_games')->where('id', $row->ga_game_id)->update(['gog_game_id' => $row->gog_game_id]);
            }
        }
        if (Schema::hasTable('ga_steam_app_links')) {
            foreach (DB::table('ga_steam_app_links')->get() as $row) {
                DB::table('ga_games')->where('id', $row->ga_game_id)->update(['steam_app_id' => $row->steam_app_id]);
            }
        }
        if (Schema::hasTable('ga_wikipedia_game_links')) {
            foreach (DB::table('ga_wikipedia_game_links')->get() as $row) {
                DB::table('ga_games')->where('id', $row->ga_game_id)->update(['wikipedia_game_id' => $row->wikipedia_game_id]);
            }
        }

        Schema::dropIfExists('ga_gog_game_links');
        Schema::dropIfExists('ga_steam_app_links');
        Schema::dropIfExists('ga_wikipedia_game_links');
    }

    public function down(): void
    {
        Schema::create('ga_gog_game_links', function (Blueprint $t) {
            $t->unsignedBigInteger('ga_game_id');
            $t->unsignedBigInteger('gog_game_id');
            $t->foreign('ga_game_id')->references('id')->on('ga_games')->cascadeOnDelete();
            $t->foreign('gog_game_id')->references('id')->on('gog_games')->cascadeOnDelete();
            $t->unique(['gog_game_id']);
            $t->unique(['ga_game_id', 'gog_game_id']);
        });

        Schema::create('ga_steam_app_links', function (Blueprint $t) {
            $t->unsignedBigInteger('ga_game_id');
            $t->unsignedBigInteger('steam_app_id');
            $t->foreign('ga_game_id')->references('id')->on('ga_games')->cascadeOnDelete();
            $t->foreign('steam_app_id')->references('id')->on('steam_apps')->cascadeOnDelete();
            $t->unique(['steam_app_id']);
            $t->unique(['ga_game_id', 'steam_app_id']);
        });

        Schema::create('ga_wikipedia_game_links', function (Blueprint $t) {
            $t->unsignedBigInteger('ga_game_id');
            $t->unsignedBigInteger('wikipedia_game_id');
            $t->foreign('ga_game_id')->references('id')->on('ga_games')->cascadeOnDelete();
            $t->foreign('wikipedia_game_id')->references('id')->on('wikipedia_games')->cascadeOnDelete();
            $t->unique(['wikipedia_game_id']);
            $t->unique(['ga_game_id', 'wikipedia_game_id']);
        });

        foreach (DB::table('ga_games')->whereNotNull('gog_game_id')->get(['id', 'gog_game_id']) as $row) {
            DB::table('ga_gog_game_links')->insert([
                'ga_game_id' => $row->id,
                'gog_game_id' => $row->gog_game_id,
            ]);
        }
        foreach (DB::table('ga_games')->whereNotNull('steam_app_id')->get(['id', 'steam_app_id']) as $row) {
            DB::table('ga_steam_app_links')->insert([
                'ga_game_id' => $row->id,
                'steam_app_id' => $row->steam_app_id,
            ]);
        }
        foreach (DB::table('ga_games')->whereNotNull('wikipedia_game_id')->get(['id', 'wikipedia_game_id']) as $row) {
            DB::table('ga_wikipedia_game_links')->insert([
                'ga_game_id' => $row->id,
                'wikipedia_game_id' => $row->wikipedia_game_id,
            ]);
        }

        Schema::table('ga_games', function (Blueprint $t) {
            $t->dropForeign(['gog_game_id']);
            $t->dropForeign(['steam_app_id']);
            $t->dropForeign(['wikipedia_game_id']);
            $t->dropColumn(['gog_game_id', 'steam_app_id', 'wikipedia_game_id']);
        });
    }
};
