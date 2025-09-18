<?php

namespace Artryazanov\GamesAggregator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Artryazanov\GamesAggregator\Models\GaCompany
 *
 * @property int $id
 * @property string $name Company name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|GaCompany newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|GaCompany newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|GaCompany query()
 */
class GaCompany extends Model
{
    protected $table = 'ga_companies';

    protected $fillable = [
        'name',
    ];
}

