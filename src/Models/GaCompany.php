<?php

declare(strict_types=1);

namespace Artryazanov\GamesAggregator\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Агрегированная модель компании (разработчика или издателя игр).
 *
 * @property int $id Уникальный идентификатор компании
 * @property string $name Уникальное название компании
 * @property Carbon|null $created_at Дата и время создания записи
 * @property Carbon|null $updated_at Дата и время обновления записи
 * @property-read Collection<int, GaGame> $developedGames Игры, разработанные данной компанией
 * @property-read Collection<int, GaGame> $publishedGames Игры, изданные данной компанией
 *
 * @method static Builder<GaCompany> newModelQuery() Создаёт новый построитель запросов без глобальных скоупов
 * @method static Builder<GaCompany> newQuery() Создаёт новый построитель запросов для модели
 * @method static Builder<GaCompany> query() Начинает новый запрос к таблице модели
 */
class GaCompany extends Model
{
    /**
     * Имя таблицы в базе данных.
     *
     * @var string
     */
    protected $table = 'ga_companies';

    /**
     * Атрибуты, разрешённые для массового заполнения.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * Возвращает игры, в которых компания выступает разработчиком.
     *
     * @return BelongsToMany<GaGame, $this> Связь «многие ко многим» с моделью GaGame через таблицу ga_game_developers
     */
    public function developedGames(): BelongsToMany
    {
        return $this->belongsToMany(GaGame::class, 'ga_game_developers', 'ga_company_id', 'ga_game_id');
    }

    /**
     * Возвращает игры, в которых компания выступает издателем.
     *
     * @return BelongsToMany<GaGame, $this> Связь «многие ко многим» с моделью GaGame через таблицу ga_game_publishers
     */
    public function publishedGames(): BelongsToMany
    {
        return $this->belongsToMany(GaGame::class, 'ga_game_publishers', 'ga_company_id', 'ga_game_id');
    }
}
