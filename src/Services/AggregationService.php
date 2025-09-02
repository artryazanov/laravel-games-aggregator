<?php

namespace Artryazanov\GamesAggregator\Services;

use Artryazanov\GamesAggregator\Models\GaCompany;
use Artryazanov\GamesAggregator\Models\GaCategory;
use Artryazanov\GamesAggregator\Models\GaGame;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AggregationService
{
    /**
     * Find a GA game candidate by name and soft matching rules, or create a new one.
     * Matching rule: same name AND (same release_year OR overlapping developer OR overlapping publisher).
     *
     * @param string $name
     * @param int|null $releaseYear
     * @param array<int,string> $developerNames
     * @param array<int,string> $publisherNames
     */
    public function findOrCreateGaGame(string $name, ?int $releaseYear, array $developerNames, array $publisherNames): GaGame
    {
        $name = trim($name);
        $developerNames = $this->normalizeNames($developerNames);
        $publisherNames = $this->normalizeNames($publisherNames);

        // Try to find matching game(s) by exact name first
        $candidates = GaGame::query()->where('name', $name)->get();

        // Ensure GA companies exist for provided names (we return IDs for later linking)
        $devCompanyIds = $this->ensureCompanies($developerNames);
        $pubCompanyIds = $this->ensureCompanies($publisherNames);

        $matched = null;
        $bestOverlap = -1;
        foreach ($candidates as $candidate) {
            $overlapDevs = $candidate->developers()->whereIn('ga_companies.id', $devCompanyIds)->count();
            $overlapPubs = $candidate->publishers()->whereIn('ga_companies.id', $pubCompanyIds)->count();

            $releaseMatch = ($releaseYear !== null && $candidate->release_year !== null && (int)$candidate->release_year === (int)$releaseYear);
            $overlapScore = ($releaseMatch ? 2 : 0) + $overlapDevs + $overlapPubs; // prioritize year match

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
            // Optionally backfill release_year if empty and we have one
            if ($matched->release_year === null && $releaseYear !== null) {
                $matched->update(['release_year' => $releaseYear]);
            }
        }

        // Attach devs/pubs (ignore duplicates via unique constraints at DB level)
        if (! empty($devCompanyIds)) {
            $matched->developers()->syncWithoutDetaching($devCompanyIds);
        }
        if (! empty($pubCompanyIds)) {
            $matched->publishers()->syncWithoutDetaching($pubCompanyIds);
        }

        return $matched;
    }

    /**
     * Simulate the decision of matching/creating without writing to DB.
     * Returns data about potential match, overlap and which companies would be created.
     *
     * @return array{
     *   matched_game_id: int|null,
     *   release_year_match: bool,
     *   overlap_devs: int,
     *   overlap_pubs: int,
     *   would_create_game: bool,
     *   missing_companies: string[],
     *   existing_company_ids: int[]
     * }
     */
    public function simulateDecision(string $name, ?int $releaseYear, array $developerNames, array $publisherNames): array
    {
        $name = trim($name);
        $devNames = $this->normalizeNames($developerNames);
        $pubNames = $this->normalizeNames($publisherNames);

        $existingByName = GaCompany::query()->whereIn('name', array_values(array_unique(array_merge($devNames, $pubNames))))->get(['id', 'name']);
        $existingMap = [];
        foreach ($existingByName as $c) { $existingMap[$c->name] = $c->id; }

        $devIds = [];
        foreach ($devNames as $n) { if (isset($existingMap[$n])) $devIds[] = $existingMap[$n]; }
        $pubIds = [];
        foreach ($pubNames as $n) { if (isset($existingMap[$n])) $pubIds[] = $existingMap[$n]; }

        $candidates = GaGame::query()->where('name', $name)->get();
        $best = null; $bestScore = -1; $bestReleaseMatch = false; $bestOverlapDevs = 0; $bestOverlapPubs = 0;
        foreach ($candidates as $candidate) {
            $overlapDevs = empty($devIds) ? 0 : $candidate->developers()->whereIn('ga_companies.id', $devIds)->count();
            $overlapPubs = empty($pubIds) ? 0 : $candidate->publishers()->whereIn('ga_companies.id', $pubIds)->count();
            $releaseMatch = ($releaseYear !== null && $candidate->release_year !== null && (int)$candidate->release_year === (int)$releaseYear);
            $score = ($releaseMatch ? 2 : 0) + $overlapDevs + $overlapPubs;
            if ($releaseMatch || $overlapDevs > 0 || $overlapPubs > 0) {
                if ($score > $bestScore) {
                    $best = $candidate; $bestScore = $score; $bestReleaseMatch = $releaseMatch; $bestOverlapDevs = $overlapDevs; $bestOverlapPubs = $overlapPubs;
                }
            }
        }

        $missing = [];
        foreach (array_merge($devNames, $pubNames) as $n) {
            if (! isset($existingMap[$n])) $missing[$n] = $n;
        }

        return [
            'matched_game_id' => $best?->id,
            'release_year_match' => $bestReleaseMatch,
            'overlap_devs' => $bestOverlapDevs,
            'overlap_pubs' => $bestOverlapPubs,
            'would_create_game' => $best === null,
            'missing_companies' => array_values($missing),
            'existing_company_ids' => array_values(array_unique(array_merge($devIds, $pubIds))),
        ];
    }

    /**
     * @param array<int,string> $names
     * @return array<int,int> Company IDs
     */
    public function ensureCompanies(array $names): array
    {
        $names = $this->normalizeNames($names);
        $ids = [];
        foreach ($names as $n) {
            if ($n === '') continue;
            $company = GaCompany::firstOrCreate(['name' => $n]);
            $ids[] = $company->id;
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param array<int,string> $names
     * @return array<int,int> Category IDs
     */
    public function ensureCategories(array $names): array
    {
        $names = $this->normalizeNames($names);
        $ids = [];
        foreach ($names as $n) {
            if ($n === '') continue;
            $cat = GaCategory::firstOrCreate(['name' => $n]);
            $ids[] = $cat->id;
        }
        return array_values(array_unique($ids));
    }

    /**
     * Normalize an array of names: trim, collapse spaces, deduplicate, drop empties.
     * @param array<int,string> $names
     * @return array<int,string>
     */
    private function normalizeNames(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $norm = trim(preg_replace('/\s+/u', ' ', (string) $name));
            if ($norm !== '') {
                $out[$norm] = $norm;
            }
        }
        return array_values($out);
    }
}
