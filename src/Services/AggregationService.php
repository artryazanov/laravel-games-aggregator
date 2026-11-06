<?php

declare(strict_types=1);

namespace Artryazanov\GamesAggregator\Services;

use Artryazanov\GamesAggregator\Models\GaCategory;
use Artryazanov\GamesAggregator\Models\GaCompany;
use Artryazanov\GamesAggregator\Models\GaGame;
use Artryazanov\GamesAggregator\Models\GaGenre;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
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
}
