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
    ];

    protected $casts = [
        'release_year' => 'integer',
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
}
