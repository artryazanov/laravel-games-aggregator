# AGENTS.md

Guidelines for automated coding agents working in this repository. Keep changes targeted, safe, and test-backed.

## Purpose
- This is a Laravel package that aggregates and normalizes games data from GOG, Steam, and Wikipedia into `ga_*` tables.
- Agents should implement narrowly scoped changes with high confidence, update docs as needed, and cover new behavior with tests.

## Repository Overview
- Package namespace: `Artryazanov\GamesAggregator` (PSR-4 from `src/`).
- Config: `config/`
- Database migrations: `database/migrations/`
- Models/Jobs/Services: `src/Models`, `src/Jobs`, `src/Services`
- Tests (Orchestra Testbench): `tests/` (sqlite in-memory)
- Run tests: `composer test`

## Operating Rules
- Make surgical changes only; do not refactor unrelated code.
- Prefer small, incremental diffs; keep style consistent with existing code.
- Never add license headers or change file ownership.
- Do not commit or create branches unless explicitly asked.
- Use migrations for schema/data changes; avoid raw, out-of-band DB edits.
- Add or update tests for any user-visible behavior or schema changes.
- Document noteworthy behavior changes here or in `README.md` when appropriate.

## Laravel/PHP Conventions
- Eloquent models declare `$fillable` and `$casts` explicitly.
- Use model events (e.g., `creating`, `saving`) for automatic field population.
- Use Services for coordination logic shared by Jobs/Commands.
- Jobs should be idempotent and wrap multi-write sequences in transactions.

## Database and Slugs
- Core table: `ga_games` has `name`, `slug`, `release_year`, source link columns, and timestamps.
- Slug policy: slug is generated from `name` on create using a Unicode-safe algorithm:
  1) lowercase (Unicode), 2) collapse whitespace to `-`, 3) strip non letters/digits/hyphens, 4) collapse multiple hyphens, 5) trim hyphens.
- When matching existing games in services and jobs, match by `slug` (not `name`).
- Data backfills and cleanup should be performed in migrations, guarded by `Schema::hasTable/hasColumn` checks, and chunked for large tables.

## Testing
- Framework: Orchestra Testbench with sqlite `:memory:`.
- Run: `composer test`
- Add focused tests near changed code. For migrations that perform data cleanup/backfill, you can `require` the migration file in a test and invoke `up()` directly.
- Keep tests deterministic; do not rely on network or external services.

## Coding Checklist
- Inputs validated/sanitized as needed.
- Database changes behind transactions where appropriate.
- Migrations are idempotent and safe to re-run.
- New behavior covered by unit/feature tests; existing tests still pass.
- Public APIs and observable behavior documented.

## Communication & Planning (for agent UIs)
- Before running commands, briefly state intent; group related actions.
- For multi-step tasks, maintain a short plan with one active step.
- Ask for approval when actions require elevated permissions or are potentially destructive.

## Useful Commands
- Run tests: `composer test`
- Static formatting (if needed): `./vendor/bin/pint` (optional; only if configured by the maintainer).

## Boundaries
- Do not introduce new external dependencies without approval.
- Do not perform unrelated “drive-by” fixes; mention them instead.
- Network calls are restricted during tests; design accordingly.

## Contact
- Primary maintainer: artryazanov@gmail.com

