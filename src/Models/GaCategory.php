<?php

declare(strict_types=1);

namespace Artryazanov\GamesAggregator\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Агрегированная модель категории (или режима) игры.
 *
 * @property int $id Уникальный идентификатор категории
 * @property string $name Уникальное название категории
 * @property Carbon|null $created_at Дата и время создания записи
 * @property Carbon|null $updated_at Дата и время обновления записи
 * @property-read Collection<int, GaGame> $games Игры, относящиеся к данной категории
 *
 * @method static Builder<GaCategory> newModelQuery() Создаёт новый построитель запросов без глобальных скоупов
 * @method static Builder<GaCategory> newQuery() Создаёт новый построитель запросов для модели
 * @method static Builder<GaCategory> query() Начинает новый запрос к таблице модели
 */
class GaCategory extends Model
{
    /**
     * Имя таблицы в базе данных.
     *
     * @var string
     */
    protected $table = 'ga_categories';

    /**
     * Атрибуты, разрешённые для массового заполнения.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * Возвращает игры, привязанные к данной категории.
     *
     * @return BelongsToMany<GaGame, $this> Связь «многие ко многим» с моделью GaGame через таблицу ga_game_categories
     */
    public function games(): BelongsToMany
    {
        return $this->belongsToMany(GaGame::class, 'ga_game_categories', 'ga_category_id', 'ga_game_id');
    }
}
