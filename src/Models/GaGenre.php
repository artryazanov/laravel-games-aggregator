<?php

declare(strict_types=1);

namespace Artryazanov\GamesAggregator\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Агрегированная модель жанра игры.
 *
 * @property int $id Уникальный идентификатор жанра
 * @property string $name Уникальное название жанра
 * @property Carbon|null $created_at Дата и время создания записи
 * @property Carbon|null $updated_at Дата и время обновления записи
 * @property-read Collection<int, GaGame> $games Игры, относящиеся к данному жанру
 *
 * @method static Builder<GaGenre> newModelQuery() Создаёт новый построитель запросов без глобальных скоупов
 * @method static Builder<GaGenre> newQuery() Создаёт новый построитель запросов для модели
 * @method static Builder<GaGenre> query() Начинает новый запрос к таблице модели
 */
class GaGenre extends Model
{
    /**
     * Имя таблицы в базе данных.
     *
     * @var string
     */
    protected $table = 'ga_genres';

    /**
     * Атрибуты, разрешённые для массового заполнения.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * Возвращает игры, привязанные к данному жанру.
     *
     * @return BelongsToMany<GaGame, $this> Связь «многие ко многим» с моделью GaGame через таблицу ga_game_genres
     */
    public function games(): BelongsToMany
    {
        return $this->belongsToMany(GaGame::class, 'ga_game_genres', 'ga_genre_id', 'ga_game_id');
    }
}
