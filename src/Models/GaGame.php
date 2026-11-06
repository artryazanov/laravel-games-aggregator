<?php

declare(strict_types=1);

namespace Artryazanov\GamesAggregator\Models;

use Artryazanov\GogScanner\Models\Game as GogGame;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;
use Artryazanov\PCGamingWiki\Models\Game as PcgwGame;
use Artryazanov\WikipediaGamesDb\Models\Game as WikipediaGame;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Агрегированная модель игры, объединяющая записи из Steam, GOG, Wikipedia и PCGamingWiki.
 *
 * @property int $id Уникальный идентификатор записи
 * @property string $name Название игры
 * @property string $slug Нормализованный символьный код (slug) на основе названия
 * @property int|null $release_year Наиболее ранний год выхода по данным всех источников
 * @property int|null $gog_game_id Ссылка на gog_games.id
 * @property int|null $steam_app_id Основная ссылка на steam_apps.id
 * @property int|null $second_steam_app_id Дополнительная ссылка на steam_apps.id
 * @property int|null $wikipedia_game_id Ссылка на wikipedia_games.id
 * @property int|null $pcgamingwiki_game_id Ссылка на pcgw_games.id
 * @property string|null $type Тип записи из источников (Steam/GOG) или 'game' по умолчанию
 * @property Carbon|null $created_at Дата и время создания записи
 * @property Carbon|null $updated_at Дата и время обновления записи
 * @property-read Collection<int, GaCompany> $developers Компании-разработчики игры
 * @property-read Collection<int, GaCompany> $publishers Компании-издатели игры
 * @property-read Collection<int, GaCategory> $categories Категории и режимы игры
 * @property-read Collection<int, GaGenre> $genres Жанры игры
 * @property-read GogGame|null $gogGame Привязанная запись игры из GOG
 * @property-read SteamApp|null $steamApp Основное привязанное приложение Steam
 * @property-read SteamApp|null $secondSteamApp Дополнительное привязанное приложение Steam
 * @property-read WikipediaGame|null $wikipediaGame Привязанная запись игры из Wikipedia
 * @property-read PcgwGame|null $pcgamingwikiGame Привязанная запись игры из PCGamingWiki
 *
 * @method static Builder<GaGame> newModelQuery() Создаёт новый построитель запросов без глобальных скоупов
 * @method static Builder<GaGame> newQuery() Создаёт новый построитель запросов для модели
 * @method static Builder<GaGame> query() Начинает новый запрос к таблице модели
 * @method static Builder<GaGame> bySlug(string $slug) Фильтрует игры по точному совпадению slug
 * @method static Builder<GaGame> withSources() Подгружает все связанные модели источников
 */
class GaGame extends Model
{
    /**
     * Имя таблицы в базе данных.
     *
     * @var string
     */
    protected $table = 'ga_games';

    /**
     * Атрибуты, разрешённые для массового заполнения.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'release_year',
        'gog_game_id',
        'steam_app_id',
        'second_steam_app_id',
        'wikipedia_game_id',
        'pcgamingwiki_game_id',
        'type',
    ];

    /**
     * Приведение типов атрибутов модели.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'release_year' => 'integer',
        'gog_game_id' => 'integer',
        'steam_app_id' => 'integer',
        'second_steam_app_id' => 'integer',
        'wikipedia_game_id' => 'integer',
        'pcgamingwiki_game_id' => 'integer',
        'type' => 'string',
    ];

    /**
     * Возвращает связь «многие ко многим» с компаниями-разработчиками игры.
     *
     * @return BelongsToMany<GaCompany, $this> Связь с моделью GaCompany через таблицу ga_game_developers
     */
    public function developers(): BelongsToMany
    {
        return $this->belongsToMany(GaCompany::class, 'ga_game_developers', 'ga_game_id', 'ga_company_id');
    }

    /**
     * Возвращает связь «многие ко многим» с компаниями-издателями игры.
     *
     * @return BelongsToMany<GaCompany, $this> Связь с моделью GaCompany через таблицу ga_game_publishers
     */
    public function publishers(): BelongsToMany
    {
        return $this->belongsToMany(GaCompany::class, 'ga_game_publishers', 'ga_game_id', 'ga_company_id');
    }

    /**
     * Возвращает связь «многие ко многим» с категориями игры.
     *
     * @return BelongsToMany<GaCategory, $this> Связь с моделью GaCategory через таблицу ga_game_categories
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(GaCategory::class, 'ga_game_categories', 'ga_game_id', 'ga_category_id');
    }

    /**
     * Возвращает связь «многие ко многим» с жанрами игры.
     *
     * @return BelongsToMany<GaGenre, $this> Связь с моделью GaGenre через таблицу ga_game_genres
     */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(GaGenre::class, 'ga_game_genres', 'ga_game_id', 'ga_genre_id');
    }

    /**
     * Локальный скоуп: фильтрует записи по точному значению slug.
     *
     * @param  Builder<GaGame>  $query  Построитель запросов Eloquent
     * @param  string  $slug  Искомый символьный код игры
     * @return Builder<GaGame> Модифицированный построитель запросов
     */
    public function scopeBySlug(Builder $query, string $slug): Builder
    {
        return $query->where('slug', $slug);
    }

    /**
     * Локальный скоуп: жадно подгружает все связанные записи источников.
     *
     * @param  Builder<GaGame>  $query  Построитель запросов Eloquent
     * @return Builder<GaGame> Модифицированный построитель запросов
     */
    public function scopeWithSources(Builder $query): Builder
    {
        return $query->with(['gogGame', 'steamApp', 'secondSteamApp', 'wikipediaGame', 'pcgamingwikiGame']);
    }

    /**
     * Возвращает привязанную игру из каталога GOG.
     *
     * @return BelongsTo<GogGame, $this> Связь с моделью GogGame
     */
    public function gogGame(): BelongsTo
    {
        return $this->belongsTo(GogGame::class, 'gog_game_id', 'id');
    }

    /**
     * Возвращает основное привязанное приложение Steam.
     *
     * @return BelongsTo<SteamApp, $this> Связь с моделью SteamApp
     */
    public function steamApp(): BelongsTo
    {
        return $this->belongsTo(SteamApp::class, 'steam_app_id', 'id');
    }

    /**
     * Возвращает дополнительное привязанное приложение Steam.
     *
     * @return BelongsTo<SteamApp, $this> Связь с моделью SteamApp
     */
    public function secondSteamApp(): BelongsTo
    {
        return $this->belongsTo(SteamApp::class, 'second_steam_app_id', 'id');
    }

    /**
     * Возвращает привязанную игру из базы Wikipedia.
     *
     * @return BelongsTo<WikipediaGame, $this> Связь с моделью WikipediaGame
     */
    public function wikipediaGame(): BelongsTo
    {
        return $this->belongsTo(WikipediaGame::class, 'wikipedia_game_id', 'id');
    }

    /**
     * Возвращает привязанную игру из базы PCGamingWiki.
     *
     * @return BelongsTo<PcgwGame, $this> Связь с моделью PcgwGame
     */
    public function pcgamingwikiGame(): BelongsTo
    {
        return $this->belongsTo(PcgwGame::class, 'pcgamingwiki_game_id', 'id');
    }

    /**
     * Регистрирует обработчики событий жизненного цикла модели для вычисления slug, года релиза и типа игры.
     */
    protected static function booted(): void
    {
        static::creating(function (GaGame $game): void {
            $name = trim((string) ($game->name ?? ''));
            if ($name !== '' && empty($game->slug)) {
                $game->slug = self::makeSlug($name);
            }
        });

        static::saving(function (GaGame $game): void {
            if ($game->isDirty('name') && ! $game->isDirty('slug')) {
                $name = trim((string) ($game->name ?? ''));
                if ($name !== '') {
                    $game->slug = self::makeSlug($name);
                }
            }

            $sourceYears = [];
            $loadedGog = null;
            $loadedSteam = null;
            $loadedSecondSteam = null;

            // Если добавлена или изменена ссылка на GOG, учитываем её год релиза
            if ($game->isDirty('gog_game_id') && ! empty($game->gog_game_id)) {
                $loadedGog = ($game->relationLoaded('gogGame') && $game->gogGame?->id === (int) $game->gog_game_id)
                    ? $game->gogGame
                    : GogGame::find($game->gog_game_id);

                if ($loadedGog) {
                    $y = self::extractYearFromGog($loadedGog);
                    if ($y !== null) {
                        $sourceYears[] = $y;
                    }
                }
            }

            // Если добавлена или изменена основная ссылка на Steam, учитываем год из detail->release_date
            if ($game->isDirty('steam_app_id') && ! empty($game->steam_app_id)) {
                $loadedSteam = ($game->relationLoaded('steamApp') && $game->steamApp?->id === (int) $game->steam_app_id)
                    ? $game->steamApp
                    : SteamApp::with('detail')->find($game->steam_app_id);

                if ($loadedSteam && ! $loadedSteam->relationLoaded('detail')) {
                    $loadedSteam->load('detail');
                }
                if ($loadedSteam && $loadedSteam->detail?->release_date) {
                    $sourceYears[] = (int) $loadedSteam->detail->release_date->format('Y');
                }
            }

            // Если добавлена или изменена вторая ссылка на Steam, учитываем её год релиза
            if ($game->isDirty('second_steam_app_id') && ! empty($game->second_steam_app_id)) {
                $loadedSecondSteam = ($game->relationLoaded('secondSteamApp') && $game->secondSteamApp?->id === (int) $game->second_steam_app_id)
                    ? $game->secondSteamApp
                    : SteamApp::with('detail')->find($game->second_steam_app_id);

                if ($loadedSecondSteam && ! $loadedSecondSteam->relationLoaded('detail')) {
                    $loadedSecondSteam->load('detail');
                }
                if ($loadedSecondSteam && $loadedSecondSteam->detail?->release_date) {
                    $sourceYears[] = (int) $loadedSecondSteam->detail->release_date->format('Y');
                }
            }

            // Если добавлена или изменена ссылка на Wikipedia, берём release_year или год из release_date
            if ($game->isDirty('wikipedia_game_id') && ! empty($game->wikipedia_game_id)) {
                $wg = ($game->relationLoaded('wikipediaGame') && $game->wikipediaGame?->id === (int) $game->wikipedia_game_id)
                    ? $game->wikipediaGame
                    : WikipediaGame::find($game->wikipedia_game_id);

                if ($wg) {
                    if ($wg->release_year !== null) {
                        $sourceYears[] = (int) $wg->release_year;
                    } elseif ($wg->release_date) {
                        $sourceYears[] = (int) $wg->release_date->format('Y');
                    }
                }
            }

            // Если добавлена или изменена ссылка на PCGamingWiki, берём release_year
            if ($game->isDirty('pcgamingwiki_game_id') && ! empty($game->pcgamingwiki_game_id)) {
                $pg = ($game->relationLoaded('pcgamingwikiGame') && $game->pcgamingwikiGame?->id === (int) $game->pcgamingwiki_game_id)
                    ? $game->pcgamingwikiGame
                    : PcgwGame::find($game->pcgamingwiki_game_id);

                if ($pg && $pg->release_year !== null) {
                    $sourceYears[] = (int) $pg->release_year;
                }
            }

            if (! empty($sourceYears)) {
                $minSource = min($sourceYears);
                $current = $game->release_year;
                $game->release_year = $current === null ? $minSource : min((int) $current, (int) $minSource);
            }

            $linksDirty = $game->isDirty([
                'steam_app_id',
                'second_steam_app_id',
                'gog_game_id',
                'wikipedia_game_id',
                'pcgamingwiki_game_id',
            ]);

            if ($linksDirty || empty($game->type)) {
                if (! $game->exists && ! empty($game->type) && ! $linksDirty) {
                    return;
                }

                $game->type = self::resolveTypeFor($game, $loadedSteam, $loadedSecondSteam, $loadedGog);
            }
        });
    }

    /**
     * Формирует нормализованный Unicode-совместимый slug из исходного названия игры.
     *
     * @param  string  $source  Исходное название игры
     * @return string Сформированный символьный код (или пустая строка, если в названии нет букв и цифр)
     */
    public static function makeSlug(string $source): string
    {
        $slug = mb_strtolower($source, 'UTF-8');
        $slug = trim($slug);
        $slug = (string) preg_replace('/\s+/u', '-', $slug);
        $slug = (string) preg_replace('/[^\p{L}\p{M}\p{N}-]+/u', '-', $slug);
        $slug = (string) preg_replace('/-+/u', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * Извлекает год релиза из модели игры GOG (по ISO-дате или временным меткам).
     *
     * @param  GogGame  $gog  Модель игры GOG
     * @return int|null Год релиза или null, если дата не указана
     */
    public static function extractYearFromGog(GogGame $gog): ?int
    {
        $iso = (string) ($gog->release_date_iso ?? '');
        if ($iso !== '' && preg_match('/^(\d{4})-\d{2}-\d{2}/', $iso, $m)) {
            return (int) $m[1];
        }

        foreach (['release_date_ts', 'global_release_date_ts'] as $tsField) {
            $ts = (int) ($gog->{$tsField} ?? 0);
            if ($ts > 0) {
                return (int) gmdate('Y', $ts);
            }
        }

        return null;
    }

    /**
     * Определяет тип записи по приоритету источников: Steam (основной) -> Steam (второй) -> GOG -> 'game'.
     *
     * @param  GaGame  $game  Экземпляр агрегированной игры
     * @param  SteamApp|null  $loadedSteam  Предзагруженная основная модель SteamApp
     * @param  SteamApp|null  $loadedSecondSteam  Предзагруженная дополнительная модель SteamApp
     * @param  GogGame|null  $loadedGog  Предзагруженная модель GogGame
     * @return string Вычисленный тип записи
     */
    private static function resolveTypeFor(
        GaGame $game,
        ?SteamApp $loadedSteam = null,
        ?SteamApp $loadedSecondSteam = null,
        ?GogGame $loadedGog = null
    ): string {
        if (! empty($game->steam_app_id)) {
            $app = $loadedSteam ?? SteamApp::with('detail')->find($game->steam_app_id);
            $steamType = self::cleanType($app?->detail?->type ?? null);
            if ($steamType !== null) {
                return $steamType;
            }
        }

        if (! empty($game->second_steam_app_id)) {
            $secondApp = $loadedSecondSteam ?? SteamApp::with('detail')->find($game->second_steam_app_id);
            $secondSteamType = self::cleanType($secondApp?->detail?->type ?? null);
            if ($secondSteamType !== null) {
                return $secondSteamType;
            }
        }

        if (! empty($game->gog_game_id)) {
            $gog = $loadedGog ?? GogGame::find($game->gog_game_id);
            $gogType = self::cleanType($gog?->game_type ?? null);
            if ($gogType !== null) {
                return $gogType;
            }
        }

        return 'game';
    }

    /**
     * Очищает и нормализует строковое или перечислимое значение типа игры.
     *
     * @param  mixed  $value  Значение типа из модели источника (строка, BackedEnum или null)
     * @return string|null Очищенное непустое строковое значение или null
     */
    private static function cleanType(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = (string) $value->value;
        }

        $v = is_string($value) ? trim($value) : '';

        return $v !== '' ? $v : null;
    }
}
