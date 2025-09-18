<?php

namespace Artryazanov\GamesAggregator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Artryazanov\GamesAggregator\Models\GaCategory
 *
 * @property int $id
 * @property string $name Category name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|GaCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|GaCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|GaCategory query()
 */
class GaCategory extends Model
{
    protected $table = 'ga_categories';

    protected $fillable = [
        'name',
    ];
}

