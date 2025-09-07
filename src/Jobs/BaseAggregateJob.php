<?php

namespace Artryazanov\GamesAggregator\Jobs;

use Artryazanov\GamesAggregator\Services\AggregationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Exception as QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

abstract class BaseAggregateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public int $backoff = 30;

    public function __construct(public int $chunkSize = 500) {}

    protected function withTransaction(callable $callback): void
    {
        DB::transaction($callback);
    }

    protected function syncCategories($gaGame, AggregationService $service, array $names): void
    {
        $names = array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), $names), fn ($v) => $v !== '')));
        if (empty($names)) {
            return;
        }
        $ids = $service->ensureCategories($names);
        $gaGame->categories()->syncWithoutDetaching($ids);
    }

    protected function syncGenres($gaGame, AggregationService $service, array $names): void
    {
        $names = array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), $names), fn ($v) => $v !== '')));
        if (empty($names)) {
            return;
        }
        $ids = $service->ensureGenres($names);
        $gaGame->genres()->syncWithoutDetaching($ids);
    }

    protected function linkIfEmpty($gaGame, array $attributes): void
    {
        // Only set attributes that are currently empty to avoid unnecessary writes
        $set = [];
        foreach ($attributes as $key => $value) {
            if (empty($gaGame->{$key})) {
                $set[$key] = $value;
            }
        }
        if (empty($set)) {
            return;
        }

        try {
            $gaGame->update($set);
        } catch (QueryException $e) {
            // Ignore duplicates or race conditions; idempotent behavior is desired
        }
    }
}
