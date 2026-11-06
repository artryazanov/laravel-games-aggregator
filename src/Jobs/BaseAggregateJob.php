<?php

declare(strict_types=1);

namespace Artryazanov\GamesAggregator\Jobs;

use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Services\AggregationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Базовый абстрактный класс задачи очереди для агрегации игр из внешнего источника.
 */
abstract class BaseAggregateJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Максимальное количество попыток выполнения задачи.
     */
    public int $tries = 3;

    /**
     * Максимальное время выполнения задачи в секундах.
     */
    public int $timeout = 1800;

    /**
     * Задержка перед повторной попыткой выполнения задачи в секундах.
     */
    public int $backoff = 30;

    /**
     * Время блокировки уникальности задачи в очереди в секундах.
     */
    public int $uniqueFor = 1800;

    /**
     * Создаёт новый экземпляр задачи агрегации.
     *
     * @param  int  $chunkSize  Размер пакета (чанка) при выборке записей из БД
     */
    public function __construct(public int $chunkSize = 500)
    {
        $this->chunkSize = max(1, $chunkSize);
    }

    /**
     * Возвращает уникальный идентификатор задачи для предотвращения дублирования в очереди.
     *
     * @return string Уникальный ключ класса задачи
     */
    public function uniqueId(): string
    {
        return static::class;
    }

    /**
     * Выполняет переданный колбэк внутри транзакции базы данных.
     *
     * @param  callable(): mixed  $callback  Замыкание с операциями записи в БД
     */
    protected function withTransaction(callable $callback): void
    {
        DB::transaction($callback);
    }

    /**
     * Синхронизирует категории игры без удаления уже существующих связей.
     *
     * @param  GaGame  $gaGame  Агрегированная модель игры
     * @param  AggregationService  $service  Сервис агрегации
     * @param  array<int, string|null>  $names  Названия категорий для привязки
     */
    protected function syncCategories(GaGame $gaGame, AggregationService $service, array $names): void
    {
        $names = $service->normalizeNames($names);
        if (empty($names)) {
            return;
        }
        $ids = $service->ensureCategories($names);
        if (! empty($ids)) {
            $gaGame->categories()->syncWithoutDetaching($ids);
        }
    }

    /**
     * Синхронизирует жанры игры без удаления уже существующих связей.
     *
     * @param  GaGame  $gaGame  Агрегированная модель игры
     * @param  AggregationService  $service  Сервис агрегации
     * @param  array<int, string|null>  $names  Названия жанров для привязки
     */
    protected function syncGenres(GaGame $gaGame, AggregationService $service, array $names): void
    {
        $names = $service->normalizeNames($names);
        if (empty($names)) {
            return;
        }
        $ids = $service->ensureGenres($names);
        if (! empty($ids)) {
            $gaGame->genres()->syncWithoutDetaching($ids);
        }
    }

    /**
     * Заполняет внешние ключи источников в записи GaGame только в том случае, если они ещё пусты.
     *
     * @param  GaGame  $gaGame  Агрегированная модель игры
     * @param  array<string, int|null>  $attributes  Атрибуты внешних ключей для обновления
     */
    protected function linkIfEmpty(GaGame $gaGame, array $attributes): void
    {
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
        } catch (QueryException) {
            // Игнорируем конфликты уникальности при параллельной обработке воркерами для сохранения идемпотентности
        }
    }
}
