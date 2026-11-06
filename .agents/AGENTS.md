# AGENTS.md

Guidelines for automated coding agents working in `artryazanov/laravel-games-aggregator`. Keep changes targeted, safe, and test-backed.

## Purpose
- This is a Laravel package (`PHP ^8.2`, `Laravel 12.x|13.x`) that aggregates and normalizes video game metadata from **GOG**, **Steam** (`artryazanov/laravel-steam-apps-db: ^2.0`), **Wikipedia**, and **PCGamingWiki** into unified `ga_*` tables.
- Agents should implement narrowly scoped changes with high confidence, update documentation as needed, and cover new or modified behavior with tests.

## Repository Overview
- Package namespace: `Artryazanov\GamesAggregator` (PSR-4 from `src/`).
- Service provider: `src/GamesAggregatorServiceProvider.php`
- Console commands: `src/Console/AggregateGamesCommand.php` (`ga:aggregate`)
- Queue jobs: `src/Jobs/` (`BaseAggregateJob`, `AggregateGogGamesJob`, `AggregateSteamAppsJob`, `AggregateWikipediaGamesJob`, `AggregatePcgamingwikiGamesJob`)
- Services: `src/Services/AggregationService.php`
- Eloquent models: `src/Models/` (`GaGame`, `GaCompany`, `GaCategory`, `GaGenre`)
- Config: `config/games-aggregator.php`
- Database migrations: `database/migrations/`
- Tests (Orchestra Testbench + SQLite `:memory:`): `tests/Unit/` and `tests/Feature/`

## Operating Rules & Code Style
- Make surgical changes only; do not refactor unrelated code.
- Always include `declare(strict_types=1);` in PHP source files under `src/`.
- **PHPDoc & Comments (Russian)**: Cover classes, properties (`@var`), and methods (`@param`, `@return`) with complete PHPDoc blocks written in **Russian** (always include a Russian description after the type in `@var`, `@param`, and `@return` so Laravel Pint's `no_superfluous_phpdoc_tags` rule is satisfied). Write inline comments in Russian.
- Never add license headers or change file ownership.
- Do not commit or create branches unless explicitly asked.
- Use migrations for schema/data changes; avoid raw, out-of-band DB edits.
- Add or update tests for any user-visible behavior or schema changes.

## Laravel & Architecture Conventions
- **Eloquent Models**:
  - Declare `$fillable` and `$casts` explicitly.
  - `GaGame` automatically populates/updates `slug` (from `name`), `release_year` (minimum valid year across linked sources in range `1970..currentYear+5`), and `type` (priority: `steamApp->detail->type` → `secondSteamApp->detail->type` → `gogGame->game_type` → `'game'`, supporting `BackedEnum` such as `SteamAppType` from `laravel-steam-apps-db` v2.0) via `creating` and `saving` model events.
  - `GaCompany`, `GaCategory`, and `GaGenre` provide inverse `BelongsToMany` relations (`developedGames`, `publishedGames`, `games`).
- **AggregationService**:
  - Coordinates deterministic matching (`findOrCreateGaGame`) and dry-run simulation (`simulateDecision`), plus bulk dictionary creation (`ensureCompanies`, `ensureCategories`, `ensureGenres`) and name normalization (`normalizeNames`).
  - Matching requires the same non-empty normalized `slug` AND at least one of: matching `release_year` (`+2` score), overlapping developer (`+1` per match), or overlapping publisher (`+1` per match).
- **Queue Jobs**:
  - Extend `BaseAggregateJob` (which implements `ShouldQueue` and `ShouldBeUnique`).
  - Eager-load required source relations (`->with(...)`) to avoid `N+1` queries and lazy-loading violations.
  - Wrap per-record multi-write sequences in `DB::transaction()` (`$this->withTransaction(...)`) and link source foreign keys idempotently via `linkIfEmpty()`.
  - `AggregateSteamAppsJob` links to `steam_app_id` first, and falls back to `second_steam_app_id` when a second Steam app matches a `GaGame` that already has a primary `steam_app_id`.

## Database & Migrations
- Core tables:
  - `ga_games`: `id`, `name`, `slug`, `type`, `release_year`, `gog_game_id`, `steam_app_id`, `second_steam_app_id`, `wikipedia_game_id`, `pcgamingwiki_game_id`, `timestamps`.
  - Dictionaries: `ga_companies`, `ga_categories`, `ga_genres` (`id`, unique `name`, `timestamps`).
  - Pivots: `ga_game_developers`, `ga_game_publishers`, `ga_game_categories`, `ga_game_genres`.
- **Slug Policy**: Generated via `GaGame::makeSlug()` using a Unicode-safe algorithm:
  1) Unicode lowercase (`mb_strtolower`), 2) collapse whitespace to `-`, 3) strip characters other than Unicode letters/digits/hyphens (`[^\p{L}\p{N}-]+`), 4) collapse multiple `-`, 5) trim leading/trailing `-`.
- **Migrations**:
  - Guard schema and data migrations with `Schema::hasTable()` / `Schema::hasColumn()`.
  - Always use `chunkById()` (never offset-based `chunk()`) when updating or deleting records inside migration loops.

## Testing & Quality Checks
- Framework: Orchestra Testbench + PHPUnit (`sqlite` `:memory:`).
- Feature tests run with `Model::preventLazyLoading(true)` to catch any `N+1` / lazy-loading regressions.
- For migrations that perform data cleanup or backfills, `require` the migration file in a test and invoke `up()` directly.
- Keep tests deterministic; do not rely on network or external services.

## Useful Commands
- Run tests: `composer test` or `vendor/bin/phpunit`
- Run tests with coverage: `vendor/bin/phpunit --coverage-text`
- Run code style check/fix: `vendor/bin/pint`

## Contact
- Primary maintainer: Artem Ryazanov (`artryazanov@gmail.com`)
