<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ga_games') || ! Schema::hasColumn('ga_games', 'slug')) {
            return;
        }

        // Remove bad rows before adding NOT NULL constraint
        DB::table('ga_games')->whereNull('slug')->delete();

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // Keep length at default 255
            DB::statement('ALTER TABLE `ga_games` MODIFY `slug` VARCHAR(255) NOT NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE ga_games ALTER COLUMN slug SET NOT NULL');

            return;
        }

        // SQLite and others: try schema change() which is supported for SQLite
        Schema::table('ga_games', function (Blueprint $t) {
            $t->string('slug')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ga_games') || ! Schema::hasColumn('ga_games', 'slug')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `ga_games` MODIFY `slug` VARCHAR(255) NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE ga_games ALTER COLUMN slug DROP NOT NULL');

            return;
        }

        Schema::table('ga_games', function (Blueprint $t) {
            $t->string('slug')->nullable()->change();
        });
    }
};
