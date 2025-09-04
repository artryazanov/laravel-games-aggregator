<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Process ga_games that have more than one source link set
        DB::table('ga_games')
            ->select(['id', 'release_year', 'gog_game_id', 'steam_app_id', 'wikipedia_game_id'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $links = 0;
                    if (! empty($row->gog_game_id)) {
                        $links++;
                    }
                    if (! empty($row->steam_app_id)) {
                        $links++;
                    }
                    if (! empty($row->wikipedia_game_id)) {
                        $links++;
                    }
                    if ($links < 2) {
                        continue; // only process games with more than one link
                    }

                    $years = [];

                    // GOG
                    if (! empty($row->gog_game_id)) {
                        $g = DB::table('gog_games')->where('id', $row->gog_game_id)->first();
                        if ($g) {
                            $y = $this->extractYearFromGogRow($g);
                            if ($y !== null) {
                                $years[] = $y;
                            }
                        }
                    }

                    // Steam
                    if (! empty($row->steam_app_id)) {
                        $detail = DB::table('steam_app_details')->where('steam_app_id', $row->steam_app_id)->first();
                        if ($detail && ! empty($detail->release_date)) {
                            $y = $this->extractYearFromDateString((string) $detail->release_date);
                            if ($y !== null) {
                                $years[] = $y;
                            }
                        }
                    }

                    // Wikipedia
                    if (! empty($row->wikipedia_game_id)) {
                        $wg = DB::table('wikipedia_games')->where('id', $row->wikipedia_game_id)->first();
                        if ($wg) {
                            if (isset($wg->release_year) && $wg->release_year !== null) {
                                $years[] = (int) $wg->release_year;
                            } elseif (! empty($wg->release_date)) {
                                $y = $this->extractYearFromDateString((string) $wg->release_date);
                                if ($y !== null) {
                                    $years[] = $y;
                                }
                            }
                        }
                    }

                    if (! empty($years)) {
                        $min = min($years);
                        // Set to minimal from sources as requested
                        DB::table('ga_games')->where('id', $row->id)->update(['release_year' => $min]);
                    }
                }
            });
    }

    public function down(): void
    {
        // No-op: cannot reliably restore previous release_year values
    }

    private function extractYearFromDateString(string $date): ?int
    {
        if (preg_match('/^(\d{4})-\d{2}-\d{2}/', $date, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^(\d{4})$/', $date, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function extractYearFromGogRow(object $g): ?int
    {
        $iso = (string) ($g->release_date_iso ?? '');
        if ($iso !== '') {
            $y = $this->extractYearFromDateString($iso);
            if ($y !== null) {
                return $y;
            }
        }

        foreach (['release_date_ts', 'global_release_date_ts'] as $tsField) {
            $ts = (int) ($g->{$tsField} ?? 0);
            if ($ts > 0) {
                return (int) gmdate('Y', $ts);
            }
        }

        return null;
    }
};
