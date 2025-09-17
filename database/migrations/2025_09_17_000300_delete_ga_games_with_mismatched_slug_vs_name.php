<?php

use Artryazanov\GamesAggregator\Models\GaGame;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ga_games') || ! Schema::hasColumn('ga_games', 'name') || ! Schema::hasColumn('ga_games', 'slug')) {
            return;
        }

        $toDelete = [];

        DB::table('ga_games')
            ->select(['id', 'name', 'slug'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$toDelete) {
                foreach ($rows as $row) {
                    $name = (string) ($row->name ?? '');
                    $currentSlug = (string) ($row->slug ?? '');

                    if ($name === '' || $currentSlug === '') {
                        // If either is empty, consider it a mismatch and delete
                        $toDelete[] = (int) $row->id;
                        continue;
                    }

                    $expected = GaGame::makeSlug($name);
                    if ($expected !== $currentSlug) {
                        $toDelete[] = (int) $row->id;
                    }
                }

                if (! empty($toDelete)) {
                    foreach (array_chunk($toDelete, 500) as $chunk) {
                        DB::table('ga_games')->whereIn('id', $chunk)->delete();
                    }
                    $toDelete = [];
                }
            });
    }

    public function down(): void
    {
        // Destructive data cleanup; nothing to restore.
    }
};

