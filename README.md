Laravel Games Aggregator
========================

Aggregate base game data from multiple sources (GOG, Steam, Wikipedia) into a single, normalized set of tables with the `ga_` prefix. This package scans source tables created by companion packages and links or creates unified records.

What This Package Does
- Creates aggregator tables: games, companies, and pivots, all prefixed with `ga_`.
- Scans source data in chunks via queued jobs and links each source game to a single aggregated game.
- Implements deterministic matching rules to avoid duplicates across sources.
- Supports a dry-run mode to preview matches without writing to the database.

Requirements
- PHP 8.1+
- Laravel 10–12
- The following source packages (already included as dependencies):
  - `artryazanov/laravel-gog-scanner` (tables like `gog_games`, companies + pivots)
  - `artryazanov/laravel-steam-apps-db` (tables like `steam_apps`, `steam_app_details`, companies + pivots)
  - `artryazanov/laravel-wikipedia-games-db` (tables like `wikipedia_games`, companies + pivots)

Installation
1) Require the package (if your app is not this monorepo):
   composer require artryazanov/laravel-games-aggregator

2) Run migrations (loads migrations from this package and the source packages via auto-discovery):
   php artisan migrate

Source Data Setup (prerequisite)
Populate source tables using their own commands (run queue workers as needed):
- GOG:   php artisan gog:scan 1
- Steam: php artisan steam:import-apps
- Wikipedia:
  - Discover via templates: php artisan games:discover-by-template
  - Or scan-all seeds:      php artisan games:scan-all
  - Or start scrape:        php artisan games:scrape-wikipedia --seed-high-value

Schema Overview (created by this package)
- `ga_games` (id, name, release_year, gog_game_id, steam_app_id, wikipedia_game_id, timestamps)
- `ga_companies` (id, name unique, timestamps)
- `ga_game_developers` (pivot ga_game_id <-> ga_company_id)
- `ga_game_publishers` (pivot ga_game_id <-> ga_company_id)
- `ga_game_categories` (pivot ga_game_id <-> ga_category_id)
- `ga_game_genres` (pivot ga_game_id <-> ga_genre_id)

Aggregation Rules
Only source rows that meet all required fields are considered:
- Name/title is present
- Release year is present or derivable from a release date
- At least one developer and at least one publisher

Matching logic for linking to `ga_games`:
- Same name AND at least one of the following is true:
  - Release year matches
  - Any developer overlaps
  - Any publisher overlaps

If no aggregated record matches, a new `ga_games` row is created, missing GA companies are created, and developer/publisher relations are attached. Each source row is then linked to exactly one `ga_games` record via dedicated foreign key columns.

Usage
- Real run (queues + jobs):
  php artisan ga:aggregate

- Filter sources:
  php artisan ga:aggregate --source=gog --source=steam

- Tune chunk size:
  php artisan ga:aggregate --chunk=1000

- Dry-run (no DB writes, prints summary per source):
  php artisan ga:aggregate --dry-run

- Dry-run with limits and specific sources:
  php artisan ga:aggregate --dry-run --source=wikipedia --limit=500

Queues
- The command enqueues three jobs (GOG, Steam, Wikipedia). Run a worker:
  php artisan queue:work

Eloquent Models
- Aggregated game: `Artryazanov\GamesAggregator\Models\GaGame`
- Aggregated company: `Artryazanov\GamesAggregator\Models\GaCompany`

Example: listing a game’s devs and pubs
  $game = \Artryazanov\GamesAggregator\Models\GaGame::first();
  $devNames = $game->developers()->pluck('name');
  $pubNames = $game->publishers()->pluck('name');

Configuration
- Optional config file `config/games-aggregator.php` is publishable via the service provider; currently no required settings.

Testing
- This package ships with an in-repo test suite using Testbench. Run:
  vendor/bin/phpunit

License
- Unlicense — see `LICENSE` for details.

Contributing
- Issues and PRs welcome. Please include tests for new features or bugfixes.

