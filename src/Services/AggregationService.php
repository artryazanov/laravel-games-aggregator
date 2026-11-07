<?php

declare(strict_types=1);

namespace Artryazanov\GamesAggregator\Services;

use Artryazanov\GamesAggregator\Models\GaCategory;
use Artryazanov\GamesAggregator\Models\GaCompany;
use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Models\GaGenre;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;
use BackedEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Сервис агрегации и сопоставления игр, компаний, категорий и жанров из внешних источников.
 */
class AggregationService
{
    /**
     * Находит существующую агрегированную игру по slug и мягким правилам совпадения или создаёт новую.
     * Правило совпадения: одинаковый slug И (совпадение года релиза ИЛИ пересечение по разработчикам ИЛИ пересечение по издателям).
     *
     * @param  string  $name  Название игры
     * @param  int|null  $releaseYear  Год выхода игры
     * @param  array<int, string|null>  $developerNames  Список названий компаний-разработчиков
     * @param  array<int, string|null>  $publisherNames  Список названий компаний-издателей
     * @return GaGame Найденная или созданная модель агрегированной игры
     *
     * @throws InvalidArgumentException Если название игры не образует валидный непустой slug
     */
    public function findOrCreateGaGame(string $name, ?int $releaseYear, array $developerNames, array $publisherNames): GaGame
    {
        $name = trim($name);
        $slug = GaGame::makeSlug($name);
        if ($slug === '') {
            throw new InvalidArgumentException('Game name must produce a non-empty slug.');
        }

        $developerNames = $this->normalizeNames($developerNames);
        $publisherNames = $this->normalizeNames($publisherNames);

        // Загружаем кандидатов по точному slug вместе с ID разработчиков и издателей без N+1 запросов
        $candidates = GaGame::query()
            ->with(['developers:id', 'publishers:id'])
            ->where('slug', $slug)
            ->get();

        // Гарантируем наличие компаний в справочнике и получаем их идентификаторы
        $devCompanyIds = $this->ensureCompanies($developerNames);
        $pubCompanyIds = $this->ensureCompanies($publisherNames);

        $matched = null;
        $bestOverlap = -1;
        foreach ($candidates as $candidate) {
            $overlapDevs = empty($devCompanyIds)
                ? 0
                : $candidate->developers->pluck('id')->intersect($devCompanyIds)->count();
            $overlapPubs = empty($pubCompanyIds)
                ? 0
                : $candidate->publishers->pluck('id')->intersect($pubCompanyIds)->count();

            $releaseMatch = ($releaseYear !== null && $candidate->release_year !== null && (int) $candidate->release_year === (int) $releaseYear);
            $overlapScore = ($releaseMatch ? 2 : 0) + $overlapDevs + $overlapPubs;

            if ($releaseMatch || $overlapDevs > 0 || $overlapPubs > 0) {
                if ($overlapScore > $bestOverlap) {
                    $bestOverlap = $overlapScore;
                    $matched = $candidate;
                }
            }
        }

        if (! $matched) {
            $matched = GaGame::create([
                'name' => $name,
                'release_year' => $releaseYear,
            ]);
        } else {
            // Обновляем год релиза, если он не был задан или новый год раньше существующего
            if ($releaseYear !== null && ($matched->release_year === null || $releaseYear < (int) $matched->release_year)) {
                $matched->update(['release_year' => $releaseYear]);
            }
        }

        if (! empty($devCompanyIds)) {
            $matched->developers()->syncWithoutDetaching($devCompanyIds);
        }
        if (! empty($pubCompanyIds)) {
            $matched->publishers()->syncWithoutDetaching($pubCompanyIds);
        }

        return $matched;
    }

    /**
     * Агрегирует одно приложение Steam в запись GaGame (создаёт новую или обновляет существующую).
     *
     * @param  SteamApp  $app  Модель приложения Steam
     * @param  bool  $strict  Требовать ли обязательного наличия года релиза и компаний (true для пакетных задач, false для ручного обновления)
     * @return GaGame|null Агрегированная модель GaGame или null, если запись не прошла фильтр
     */
    public function aggregateSteamApp(SteamApp $app, bool $strict = true): ?GaGame
    {
        $app->loadMissing(['detail', 'developers', 'publishers', 'categories', 'genres']);

        $releaseYear = $app->detail?->release_date?->format('Y');
        $releaseYear = $releaseYear ? (int) $releaseYear : null;

        $developerNames = $this->normalizeNames($app->developers->pluck('name')->all());
        $publisherNames = $this->normalizeNames($app->publishers->pluck('name')->all());
        $categoryNames = $this->normalizeNames($app->categories->pluck('description')->all());
        $genreNames = $this->normalizeNames($app->genres->pluck('description')->all());

        // Проверяем, привязано ли уже данное приложение Steam к существующей записи GaGame
        $existingGaGame = GaGame::query()
            ->where('steam_app_id', $app->id)
            ->orWhere('second_steam_app_id', $app->id)
            ->first();

        if ($existingGaGame) {
            return DB::transaction(function () use (
                $existingGaGame,
                $app,
                $releaseYear,
                $developerNames,
                $publisherNames,
                $categoryNames,
                $genreNames,
            ): GaGame {
                $this->syncGaGameRelations($existingGaGame, $developerNames, $publisherNames, $categoryNames, $genreNames);

                if ($releaseYear !== null && ($existingGaGame->release_year === null || $releaseYear < (int) $existingGaGame->release_year)) {
                    $existingGaGame->release_year = $releaseYear;
                }

                $rawType = $app->detail?->type;
                if ($rawType instanceof BackedEnum) {
                    $rawType = (string) $rawType->value;
                }
                $cleanType = is_string($rawType) ? trim($rawType) : '';

                if ($cleanType !== '' && (int) $existingGaGame->steam_app_id === (int) $app->id) {
                    $existingGaGame->type = $cleanType;
                }

                if ($existingGaGame->isDirty()) {
                    $existingGaGame->save();
                }

                return $existingGaGame;
            });
        }

        $name = trim((string) $app->name);
        $slug = GaGame::makeSlug($name);

        if ($name === '' || $slug === '') {
            return null;
        }

        if ($strict) {
            if ($releaseYear === null || (empty($developerNames) && empty($publisherNames))) {
                return null;
            }
        } elseif ($app->detail === null) {
            return null;
        }

        return DB::transaction(function () use (
            $app,
            $name,
            $slug,
            $releaseYear,
            $developerNames,
            $publisherNames,
            $categoryNames,
            $genreNames,
            $strict,
        ): GaGame {
            if (! $strict && $releaseYear === null && empty($developerNames) && empty($publisherNames)) {
                $gaGame = GaGame::query()
                    ->where('slug', $slug)
                    ->whereNull('steam_app_id')
                    ->first() ?? $this->findOrCreateGaGame($name, null, [], []);
            } else {
                $gaGame = $this->findOrCreateGaGame($name, $releaseYear, $developerNames, $publisherNames);
            }

            $this->syncGaGameRelations($gaGame, [], [], $categoryNames, $genreNames);

            if (empty($gaGame->steam_app_id)) {
                $gaGame->setRelation('steamApp', $app);
                $this->linkSteamAppIfEmpty($gaGame, 'steam_app_id', (int) $app->id);
            } elseif (empty($gaGame->second_steam_app_id) && (int) $gaGame->steam_app_id !== (int) $app->id) {
                $gaGame->setRelation('secondSteamApp', $app);
                $this->linkSteamAppIfEmpty($gaGame, 'second_steam_app_id', (int) $app->id);
            }

            return $gaGame;
        });
    }

    /**
     * Агрегирует коллекцию или массив приложений Steam и возвращает коллекцию уникальных записей GaGame.
     *
     * @param  iterable<int, SteamApp>  $apps  Список моделей SteamApp для агрегации
     * @param  bool  $strict  Требовать ли обязательного наличия года релиза и компаний
     * @return Collection<int, GaGame> Коллекция уникальных агрегированных записей GaGame
     */
    public function aggregateSteamApps(iterable $apps, bool $strict = true): Collection
    {
        $results = new Collection;

        foreach ($apps as $app) {
            if (! $app instanceof SteamApp) {
                continue;
            }

            $app->load(['detail', 'developers', 'publishers', 'categories', 'genres']);
            $gaGame = $this->aggregateSteamApp($app, $strict);

            if ($gaGame) {
                $results->push($gaGame);
            }
        }

        /** @var Collection<int, GaGame> $unique */
        $unique = $results->unique('id')->values();

        return $unique;
    }

    /**
     * Симулирует решение о сопоставлении или создании игры без записи в базу данных (для режима dry-run).
     *
     * @param  string  $name  Название игры
     * @param  int|null  $releaseYear  Год выхода игры
     * @param  array<int, string|null>  $developerNames  Список названий компаний-разработчиков
     * @param  array<int, string|null>  $publisherNames  Список названий компаний-издателей
     * @return array{
     *   matched_game_id: int|null,
     *   release_year_match: bool,
     *   overlap_devs: int,
     *   overlap_pubs: int,
     *   would_create_game: bool,
     *   missing_companies: array<int, string>,
     *   existing_company_ids: array<int, int>
     * } Результат симуляции сопоставления
     */
    public function simulateDecision(string $name, ?int $releaseYear, array $developerNames, array $publisherNames): array
    {
        $name = trim($name);
        $slug = GaGame::makeSlug($name);
        $devNames = $this->normalizeNames($developerNames);
        $pubNames = $this->normalizeNames($publisherNames);
        $allNames = array_values(array_unique(array_merge($devNames, $pubNames)));

        $existingExactMap = [];
        $existingLowerMap = [];
        if (! empty($allNames)) {
            $existingByName = GaCompany::query()->whereIn('name', $allNames)->get(['id', 'name']);
            foreach ($existingByName as $c) {
                $existingExactMap[$c->name] = (int) $c->id;
                $existingLowerMap[mb_strtolower($c->name, 'UTF-8')] = (int) $c->id;
            }
        }

        $resolveCompanyId = static function (string $companyName) use ($existingExactMap, $existingLowerMap): ?int {
            if (isset($existingExactMap[$companyName])) {
                return $existingExactMap[$companyName];
            }

            $lower = mb_strtolower($companyName, 'UTF-8');

            return $existingLowerMap[$lower] ?? null;
        };

        $devIds = [];
        foreach ($devNames as $n) {
            $id = $resolveCompanyId($n);
            if ($id !== null) {
                $devIds[] = $id;
            }
        }
        $pubIds = [];
        foreach ($pubNames as $n) {
            $id = $resolveCompanyId($n);
            if ($id !== null) {
                $pubIds[] = $id;
            }
        }

        $best = null;
        $bestScore = -1;
        $bestReleaseMatch = false;
        $bestOverlapDevs = 0;
        $bestOverlapPubs = 0;

        if ($slug !== '') {
            $candidates = GaGame::query()
                ->with(['developers:id', 'publishers:id'])
                ->where('slug', $slug)
                ->get();

            foreach ($candidates as $candidate) {
                $overlapDevs = empty($devIds)
                    ? 0
                    : $candidate->developers->pluck('id')->intersect($devIds)->count();
                $overlapPubs = empty($pubIds)
                    ? 0
                    : $candidate->publishers->pluck('id')->intersect($pubIds)->count();
                $releaseMatch = ($releaseYear !== null && $candidate->release_year !== null && (int) $candidate->release_year === (int) $releaseYear);
                $score = ($releaseMatch ? 2 : 0) + $overlapDevs + $overlapPubs;
                if ($releaseMatch || $overlapDevs > 0 || $overlapPubs > 0) {
                    if ($score > $bestScore) {
                        $best = $candidate;
                        $bestScore = $score;
                        $bestReleaseMatch = $releaseMatch;
                        $bestOverlapDevs = $overlapDevs;
                        $bestOverlapPubs = $overlapPubs;
                    }
                }
            }
        }

        $missing = [];
        foreach ($allNames as $n) {
            if ($resolveCompanyId($n) === null) {
                $missing[$n] = $n;
            }
        }

        return [
            'matched_game_id' => $best?->id,
            'release_year_match' => $bestReleaseMatch,
            'overlap_devs' => $bestOverlapDevs,
            'overlap_pubs' => $bestOverlapPubs,
            'would_create_game' => $best === null && $slug !== '',
            'missing_companies' => array_values($missing),
            'existing_company_ids' => array_values(array_unique(array_merge($devIds, $pubIds))),
        ];
    }

    /**
     * Гарантирует существование компаний в таблице ga_companies и возвращает их идентификаторы.
     *
     * @param  array<int, string|null>  $names  Названия компаний
     * @return array<int, int> Массив идентификаторов компаний
     */
    public function ensureCompanies(array $names): array
    {
        return $this->ensureDictionaryRecords(GaCompany::class, $names);
    }

    /**
     * Гарантирует существование категорий в таблице ga_categories и возвращает их идентификаторы.
     *
     * @param  array<int, string|null>  $names  Названия категорий
     * @return array<int, int> Массив идентификаторов категорий
     */
    public function ensureCategories(array $names): array
    {
        return $this->ensureDictionaryRecords(GaCategory::class, $names);
    }

    /**
     * Гарантирует существование жанров в таблице ga_genres и возвращает их идентификаторы.
     *
     * @param  array<int, string|null>  $names  Названия жанров
     * @return array<int, int> Массив идентификаторов жанров
     */
    public function ensureGenres(array $names): array
    {
        return $this->ensureDictionaryRecords(GaGenre::class, $names);
    }

    /**
     * Нормализует массив названий: обрезает пробелы, схлопывает внутренние пробелы, удаляет пустые строки и дубликаты.
     *
     * @param  array<int, mixed>  $names  Исходный массив названий
     * @return array<int, string> Очищенный массив уникальных непустых названий
     */
    public function normalizeNames(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            if ($name === null || (! is_string($name) && ! is_numeric($name))) {
                continue;
            }
            $norm = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
            if ($norm !== '') {
                $out[$norm] = $norm;
            }
        }

        return array_values($out);
    }

    /**
     * Пакетно проверяет наличие записей в справочнике по полю name, создаёт недостающие и возвращает их ID.
     *
     * @param  class-string<Model>  $modelClass  Имя класса Eloquent-модели справочника
     * @param  array<int, string|null>  $names  Список названий
     * @return array<int, int> Массив уникальных идентификаторов записей
     */
    private function ensureDictionaryRecords(string $modelClass, array $names): array
    {
        $names = $this->normalizeNames($names);
        if (empty($names)) {
            return [];
        }

        $existing = $modelClass::query()->whereIn('name', $names)->get(['id', 'name']);
        $exactMap = [];
        foreach ($existing as $row) {
            $exactMap[(string) $row->name] = (int) $row->id;
        }

        $ids = [];
        foreach ($names as $n) {
            if (isset($exactMap[$n])) {
                $ids[] = $exactMap[$n];

                continue;
            }

            try {
                /** @var Model $record */
                $record = $modelClass::firstOrCreate(['name' => $n]);
                $id = (int) $record->getKey();
            } catch (QueryException) {
                $id = (int) $modelClass::query()->where('name', $n)->value('id');
            }

            if ($id > 0) {
                $exactMap[$n] = $id;
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Синхронизирует связанные компании, категории и жанры агрегированной игры без удаления существующих связей.
     *
     * @param  GaGame  $gaGame  Агрегированная модель игры
     * @param  array<int, string>  $developerNames  Нормализованные названия разработчиков
     * @param  array<int, string>  $publisherNames  Нормализованные названия издателей
     * @param  array<int, string>  $categoryNames  Нормализованные названия категорий
     * @param  array<int, string>  $genreNames  Нормализованные названия жанров
     */
    private function syncGaGameRelations(
        GaGame $gaGame,
        array $developerNames,
        array $publisherNames,
        array $categoryNames,
        array $genreNames,
    ): void {
        if (! empty($developerNames)) {
            $devIds = $this->ensureCompanies($developerNames);
            if (! empty($devIds)) {
                $gaGame->developers()->syncWithoutDetaching($devIds);
            }
        }

        if (! empty($publisherNames)) {
            $pubIds = $this->ensureCompanies($publisherNames);
            if (! empty($pubIds)) {
                $gaGame->publishers()->syncWithoutDetaching($pubIds);
            }
        }

        if (! empty($categoryNames)) {
            $catIds = $this->ensureCategories($categoryNames);
            if (! empty($catIds)) {
                $gaGame->categories()->syncWithoutDetaching($catIds);
            }
        }

        if (! empty($genreNames)) {
            $genreIds = $this->ensureGenres($genreNames);
            if (! empty($genreIds)) {
                $gaGame->genres()->syncWithoutDetaching($genreIds);
            }
        }
    }

    /**
     * Идемпотентно заполняет внешний ключ Steam у записи GaGame.
     *
     * @param  GaGame  $gaGame  Агрегированная модель игры
     * @param  string  $column  Название колонки внешнего ключа (steam_app_id или second_steam_app_id)
     * @param  int  $steamAppId  Первичный ключ записи SteamApp
     */
    private function linkSteamAppIfEmpty(GaGame $gaGame, string $column, int $steamAppId): void
    {
        try {
            $gaGame->update([$column => $steamAppId]);
        } catch (QueryException) {
            // Игнорируем конфликты уникальности при параллельной обработке для сохранения идемпотентности
        }
    }
}
