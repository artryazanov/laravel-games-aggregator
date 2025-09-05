<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ga_games')) {
            return;
        }

        Schema::table('ga_games', function (Blueprint $t) {
            if (! Schema::hasColumn('ga_games', 'type')) {
                $t->string('type')->nullable()->after('slug')->index();
            }
        });

        if (! Schema::hasColumn('ga_games', 'type')) {
            return; // nothing to backfill
        }

        // Backfill `type` with precedence: SteamAppDetail.type -> GogGame.game_type -> 'game'
        DB::table('ga_games')
            ->select(['id', 'type', 'steam_app_id', 'gog_game_id'])
            ->whereNull('type')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $resolved = null;

                    if (! empty($row->steam_app_id) && Schema::hasTable('steam_app_details')) {
                        $resolved = DB::table('steam_app_details')
                            ->where('steam_app_id', $row->steam_app_id)
                            ->value('type');
                        $resolved = $this->cleanType($resolved);
                    }

                    if ($resolved === null && ! empty($row->gog_game_id) && Schema::hasTable('gog_games')) {
                        $resolved = DB::table('gog_games')
                            ->where('id', $row->gog_game_id)
                            ->value('game_type');
                        $resolved = $this->cleanType($resolved);
                    }

                    if ($resolved === null) {
                        $resolved = 'game';
                    }

                    DB::table('ga_games')->where('id', $row->id)->update(['type' => $resolved]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ga_games')) {
            return;
        }

        Schema::table('ga_games', function (Blueprint $t) {
            if (Schema::hasColumn('ga_games', 'type')) {
                $t->dropIndex(['type']);
                $t->dropColumn('type');
            }
        });
    }

    private function cleanType($value): ?string
    {
        $v = is_string($value) ? trim($value) : '';
        return $v !== '' ? $v : null;
    }
};

