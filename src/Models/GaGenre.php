<?php

namespace Artryazanov\GamesAggregator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Artryazanov\GamesAggregator\Models\GaGenre
 *
 * @property int $id
 * @property string $name Genre name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|GaGenre newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|GaGenre newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|GaGenre query()
 */
class GaGenre extends Model
{
    protected $table = 'ga_genres';

    protected $fillable = [
        'name',
    ];
}

