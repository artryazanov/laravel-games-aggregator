<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ga_games', function (Blueprint $t) {
            $t->id();
            $t->string('name')->index();
            $t->unsignedSmallInteger('release_year')->nullable()->index();
            $t->timestamps();
        });

        Schema::create('ga_companies', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });

        // Developers pivot
        Schema::create('ga_game_developers', function (Blueprint $t) {
            $t->unsignedBigInteger('ga_game_id');
            $t->unsignedBigInteger('ga_company_id');
            $t->foreign('ga_game_id')->references('id')->on('ga_games')->cascadeOnDelete();
            $t->foreign('ga_company_id')->references('id')->on('ga_companies')->cascadeOnDelete();
            $t->unique(['ga_game_id', 'ga_company_id']);
        });

        // Publishers pivot
        Schema::create('ga_game_publishers', function (Blueprint $t) {
            $t->unsignedBigInteger('ga_game_id');
            $t->unsignedBigInteger('ga_company_id');
            $t->foreign('ga_game_id')->references('id')->on('ga_games')->cascadeOnDelete();
            $t->foreign('ga_company_id')->references('id')->on('ga_companies')->cascadeOnDelete();
            $t->unique(['ga_game_id', 'ga_company_id']);
        });

        // Link tables to source packages
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
            $t->unsignedBigInteger('steam_app_id'); // references steam_apps.id
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
    }

    public function down(): void
    {
        Schema::dropIfExists('ga_wikipedia_game_links');
        Schema::dropIfExists('ga_steam_app_links');
        Schema::dropIfExists('ga_gog_game_links');
        Schema::dropIfExists('ga_game_publishers');
        Schema::dropIfExists('ga_game_developers');
        Schema::dropIfExists('ga_companies');
        Schema::dropIfExists('ga_games');
    }
};

