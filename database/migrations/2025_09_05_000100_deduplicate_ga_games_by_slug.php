<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ga_games') || ! Schema::hasColumn('ga_games', 'slug')) {
            return;
        }

        // Find slugs that appear more than once and keep the smallest id
        $dupes = DB::table('ga_games')
            ->select('slug', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('slug')
            ->groupBy('slug')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        foreach ($dupes as $d) {
            $slug = (string) $d->slug;
            $keepId = (int) $d->keep_id;

            // Delete all other rows with the same slug
            DB::table('ga_games')
                ->where('slug', $slug)
                ->where('id', '!=', $keepId)
                ->delete();
        }
    }

    public function down(): void
    {
        // Irreversible cleanup; nothing to do.
    }
};

