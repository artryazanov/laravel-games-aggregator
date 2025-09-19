<?php

namespace Artryazanov\GamesAggregator\Models;

use Artryazanov\GogScanner\Models\Game as GogGame;
use Artryazanov\LaravelSteamAppsDb\Models\SteamApp;
use Artryazanov\WikipediaGamesDb\Models\Game as WikipediaGame;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Artryazanov\GamesAggregator\Models\GaGame
 *
 * @property int $id
 * @property string $name Game name
 * @property string $slug Unique slug generated from name
 * @property int|null $release_year Earliest release year from sources
 * @property int|null $gog_game_id Reference to gog_games.id
 * @property int|null $steam_app_id Reference to steam_apps.id
 * @property int|null $second_steam_app_id Optional second Steam app link
 * @property int|null $wikipedia_game_id Reference to wikipedia_games.id
 * @property string|null $type Inferred type (Steam/GOG) or 'game'
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, GaCompany> $developers
 * @property-read Collection<int, GaCompany> $publishers
 * @property-read Collection<int, GaCategory> $categories
 * @property-read Collection<int, GaGenre> $genres
 * @property-read GogGame|null $gogGame
 * @property-read SteamApp|null $steamApp
 * @property-read SteamApp|null $secondSteamApp
 * @property-read WikipediaGame|null $wikipediaGame
 *
 * @method static Builder|GaGame newModelQuery()
 * @method static Builder|GaGame newQuery()
 * @method static Builder|GaGame query()
 * @method static Builder|GaGame bySlug(string $slug)
 * @method static Builder|GaGame withSources()
 */
class GaGame extends Model
{
    protected $table = 'ga_games';

    protected $fillable = [
        'name',
        'release_year',
        'gog_game_id',
        'steam_app_id',
        'second_steam_app_id',
        'wikipedia_game_id',
        'type',
    ];

    protected $casts = [
        'release_year' => 'integer',
        'gog_game_id' => 'integer',
        'steam_app_id' => 'integer',
        'second_steam_app_id' => 'integer',
        'wikipedia_game_id' => 'integer',
        'type' => 'string',
    ];

    public function developers(): BelongsToMany
    {
        return $this->belongsToMany(GaCompany::class, 'ga_game_developers', 'ga_game_id', 'ga_company_id');
    }

    public function publishers(): BelongsToMany
    {
        return $this->belongsToMany(GaCompany::class, 'ga_game_publishers', 'ga_game_id', 'ga_company_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(GaCategory::class, 'ga_game_categories', 'ga_game_id', 'ga_category_id');
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(GaGenre::class, 'ga_game_genres', 'ga_game_id', 'ga_genre_id');
    }

    // Scopes
    public function scopeBySlug(Builder $query, string $slug): Builder
    {
        return $query->where('slug', $slug);
    }

    public function scopeWithSources(Builder $query): Builder
    {
        return $query->with(['gogGame', 'steamApp', 'secondSteamApp', 'wikipediaGame']);
    }

    /**
     * Get the GOG game associated with this game.
     */
    public function gogGame(): BelongsTo
    {
        return $this->belongsTo(GogGame::class, 'gog_game_id', 'id');
    }

    /**
     * Get the primary Steam app associated with this game.
     */
    public function steamApp(): BelongsTo
    {
        return $this->belongsTo(SteamApp::class, 'steam_app_id', 'id');
    }

    /**
     * Get the secondary Steam app associated with this game.
     */
    public function secondSteamApp(): BelongsTo
    {
        return $this->belongsTo(SteamApp::class, 'second_steam_app_id', 'id');
    }

    /**
     * Get the Wikipedia game associated with this game.
     */
    public function wikipediaGame(): BelongsTo
    {
        return $this->belongsTo(WikipediaGame::class, 'wikipedia_game_id', 'id');
    }

    protected static function booted(): void
    {
        // Auto-generate slug on create
        static::creating(function (GaGame $game) {
            $name = (string) ($game->name ?? '');
            if ($name !== '' && empty($game->slug)) {
                $game->slug = self::makeSlug($name);
            }

            // Set initial type based on available links or default to 'game'
            if (empty($game->type)) {
                $game->type = self::resolveTypeFor($game);
            }
        });

        static::saving(function (GaGame $game) {
            $sourceYears = [];

            // If a new GOG link is being added/changed, try to extract its release year
            if ($game->isDirty('gog_game_id') && ! empty($game->gog_game_id)) {
                $gog = GogGame::find($game->gog_game_id);
                if ($gog) {
                    $y = self::extractYearFromGog($gog);
                    if ($y !== null) {
                        $sourceYears[] = $y;
                    }
                }
            }

            // If a new Steam link is being added/changed, take the detail->release_date year
            if ($game->isDirty('steam_app_id') && ! empty($game->steam_app_id)) {
                $app = SteamApp::with('detail')->find($game->steam_app_id);
                if ($app && $app->detail?->release_date) {
                    $sourceYears[] = (int) $app->detail->release_date->format('Y');
                }
            }

            // If a new Wikipedia link is being added/changed, prefer explicit release_year then release_date
            if ($game->isDirty('wikipedia_game_id') && ! empty($game->wikipedia_game_id)) {
                $wg = WikipediaGame::find($game->wikipedia_game_id);
                if ($wg) {
                    if (! is_null($wg->release_year)) {
                        $sourceYears[] = (int) $wg->release_year;
                    } elseif ($wg->release_date) {
                        $sourceYears[] = (int) $wg->release_date->format('Y');
                    }
                }
            }

            if (! empty($sourceYears)) {
                $minSource = min($sourceYears);
                $current = $game->release_year;
                if ($current === null) {
                    $game->release_year = $minSource;
                } else {
                    $game->release_year = min((int) $current, (int) $minSource);
                }
            }

            // Recompute type when any source link changes
            if ($game->isDirty('steam_app_id') || $game->isDirty('gog_game_id') || $game->isDirty('wikipedia_game_id')) {
                $game->type = self::resolveTypeFor($game);
            }
        });
    }

    public static function makeSlug(string $source): string
    {
        $slug = mb_strtolower($source, 'UTF-8');
        $slug = trim($slug);
        $slug = preg_replace('/\s+/u', '-', $slug);
        $slug = preg_replace('/[^\p{L}\p{M}\p{N}-]+/u', '-', $slug);
        $slug = preg_replace('/-+/u', '-', $slug);

        return trim((string) $slug, '-');
    }

    private static function extractYearFromGog(GogGame $gog): ?int
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

    private static function resolveTypeFor(GaGame $game): string
    {
        // Priority: Steam detail.type -> GOG game_type -> 'game'
        if (! empty($game->steam_app_id)) {
            $app = SteamApp::with('detail')->find($game->steam_app_id);
            $steamType = self::cleanType($app?->detail?->type ?? null);
            if ($steamType !== null) {
                return $steamType;
            }
        }

        if (! empty($game->gog_game_id)) {
            $gog = GogGame::find($game->gog_game_id);
            $gogType = self::cleanType($gog?->game_type ?? null);
            if ($gogType !== null) {
                return $gogType;
            }
        }

        return 'game';
    }

    private static function cleanType($value): ?string
    {
        $v = is_string($value) ? trim($value) : '';

        return $v !== '' ? $v : null;
    }
}
