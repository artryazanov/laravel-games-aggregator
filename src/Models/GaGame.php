<?php

namespace Artryazanov\GamesAggregator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GaGame extends Model
{
    protected $table = 'ga_games';

    protected $fillable = [
        'name',
        'release_year',
        'gog_game_id',
        'steam_app_id',
        'wikipedia_game_id',
    ];

    protected $casts = [
        'release_year' => 'integer',
        'gog_game_id' => 'integer',
        'steam_app_id' => 'integer',
        'wikipedia_game_id' => 'integer',
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
}
