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
            if (! Schema::hasColumn('ga_games', 'slug')) {
                $t->string('slug')->nullable()->after('name')->index();
            }
        });

        // Backfill slug values for existing rows
        if (Schema::hasColumn('ga_games', 'slug')) {
            $slugify = function (string $source): string {
                $slug = mb_strtolower($source, 'UTF-8');
                $slug = preg_replace('/\s+/u', '-', $slug);
                $slug = preg_replace('/[^\p{L}\p{M}\p{N}-]+/u', '', $slug);
                $slug = preg_replace('/-+/u', '-', $slug);
                return trim((string) $slug, '-');
            };

            DB::table('ga_games')
                ->select(['id', 'name'])
                ->whereNull('slug')
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($slugify) {
                    foreach ($rows as $row) {
                        $name = (string) ($row->name ?? '');
                        if ($name === '') {
                            continue;
                        }
                        DB::table('ga_games')->where('id', $row->id)->update([
                            'slug' => $slugify($name),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ga_games')) {
            return;
        }

        Schema::table('ga_games', function (Blueprint $t) {
            if (Schema::hasColumn('ga_games', 'slug')) {
                $t->dropIndex(['slug']);
                $t->dropColumn('slug');
            }
        });
    }
};

