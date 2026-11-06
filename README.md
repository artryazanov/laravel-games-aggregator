# Laravel Games Aggregator

Aggregate and normalize video game metadata from multiple sources (**GOG**, **Steam**, **Wikipedia**, **PCGamingWiki**) into a unified set of `ga_*` tables. This package scans source tables populated by companion packages and links or creates canonical game records.

## Features

- Creates normalized aggregator tables (`ga_games`, `ga_companies`, `ga_categories`, `ga_genres`, and pivot tables).
- Scans source data in chunks via unique queued jobs (`ShouldBeUnique`) and links each source entry to a unified `GaGame` record.
- Supports primary and secondary Steam app links (`steam_app_id` and `second_steam_app_id`) on a single aggregated game.
- Automatically derives and maintains the earliest valid `release_year` (`1970..currentYear+5`) and normalized `type` across linked sources.
- Provides a `--dry-run` CLI mode to preview matching decisions without writing to the database.

## Requirements

- PHP `^8.2`
- Laravel `12.x` or `13.x`
- Companion source packages (installed automatically as dependencies):
  - `artryazanov/laravel-gog-scanner` (`gog_games`, companies, categories, genres, pivots)
  - `artryazanov/laravel-steam-apps-db` (`^2.0`, `steam_apps`, `steam_app_details`, developers, publishers, categories, genres, pivots)
  - `artryazanov/laravel-wikipedia-games-db` (`wikipedia_games`, `wikipages`, companies, modes, genres, pivots)
  - `artryazanov/laravel-pcgamingwiki-db` (`pcgw_games`, companies, modes, genres, pivots)

## Installation

1. Require the package via Composer:
   ```bash
   composer require artryazanov/laravel-games-aggregator
   ```

2. Run database migrations (auto-discovered from this package and companion source packages):
   ```bash
   php artisan migrate
   ```

3. *(Optional)* Publish the configuration file:
   ```bash
   php artisan vendor:publish --tag=games-aggregator-config
   ```

## Source Data Setup (Prerequisite)

Populate the underlying source tables using their respective Artisan commands (and run queue workers as needed):

```bash
# GOG
php artisan gog:scan 1

# Steam
php artisan steam:import-apps

# Wikipedia
php artisan games:discover-by-template
# or: php artisan games:scan-all
# or: php artisan games:scrape-wikipedia --seed-high-value

# PCGamingWiki
php artisan pcgamingwiki:sync-games
```

## Database Schema

- **`ga_games`**:
  - `id`, `name`, `slug` (indexed), `type` (indexed, default `'game'`), `release_year` (indexed, nullable)
  - Unique nullable source foreign keys: `gog_game_id`, `steam_app_id`, `second_steam_app_id`, `wikipedia_game_id`, `pcgamingwiki_game_id`
  - `created_at`, `updated_at`
- **`ga_companies`**: `id`, `name` (unique), `timestamps`
- **`ga_categories`**: `id`, `name` (unique), `timestamps`
- **`ga_genres`**: `id`, `name` (unique), `timestamps`
- **Pivot tables**:
  - `ga_game_developers` (`ga_game_id` ↔ `ga_company_id`)
  - `ga_game_publishers` (`ga_game_id` ↔ `ga_company_id`)
  - `ga_game_categories` (`ga_game_id` ↔ `ga_category_id`)
  - `ga_game_genres` (`ga_game_id` ↔ `ga_genre_id`)

## Aggregation & Matching Rules

A source record is eligible for aggregation only when all of the following hold:
1. Title/name is non-empty and produces a non-empty normalized `slug`.
2. Release year is present or derivable from a release date (`1970..currentYear+5`).
3. At least one non-empty developer name **or** publisher name is present.

### Candidate Matching & Scoring

Candidates in `ga_games` are looked up by exact `slug`. A candidate matches if **at least one** of the following is true:
- `release_year` matches (`+2` points)
- At least one developer overlaps (`+1` point per overlapping developer)
- At least one publisher overlaps (`+1` point per overlapping publisher)

The highest-scoring candidate is linked. If no candidate matches, a new `ga_games` record is created. Missing companies, categories, and genres are created idempotently and synced without detaching existing relations.

## Usage

```bash
# Dispatch aggregation jobs for all sources (gog, steam, wikipedia, pcgamingwiki)
php artisan ga:aggregate

# Filter specific sources
php artisan ga:aggregate --source=gog --source=steam

# Customize chunk size (default: 500)
php artisan ga:aggregate --chunk=1000

# Dry-run mode (no DB writes, outputs matching summary per source)
php artisan ga:aggregate --dry-run

# Dry-run with sample limit and specific source
php artisan ga:aggregate --dry-run --source=wikipedia --limit=500
```

Process the queued jobs with a Laravel queue worker:

```bash
php artisan queue:work
```

## Eloquent Models

- `Artryazanov\GamesAggregator\Models\GaGame`
  - Relations: `developers()`, `publishers()`, `categories()`, `genres()`, `gogGame()`, `steamApp()`, `secondSteamApp()`, `wikipediaGame()`, `pcgamingwikiGame()`
  - Scopes: `searchByName(string $term)`, `gameType()`
- `Artryazanov\GamesAggregator\Models\GaCompany`
  - Relations: `developedGames()`, `publishedGames()`
- `Artryazanov\GamesAggregator\Models\GaCategory`
  - Relations: `games()`
- `Artryazanov\GamesAggregator\Models\GaGenre`
  - Relations: `games()`

### Example

```php
use Artryazanov\GamesAggregator\Models\GaGame;

$games = GaGame::query()
    ->gameType()
    ->searchByName('Doom')
    ->with(['developers', 'publishers', 'genres', 'categories'])
    ->get();
```

## Testing & Code Style

```bash
# Run test suite (Orchestra Testbench + PHPUnit)
composer test

# Run Laravel Pint code style check
vendor/bin/pint --test
```

## License

Unlicense — see [LICENSE](LICENSE) for details.
