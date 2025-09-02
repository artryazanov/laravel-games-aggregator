<?php

namespace Artryazanov\GamesAggregator\Models;

use Illuminate\Database\Eloquent\Model;

class GaCategory extends Model
{
    protected $table = 'ga_categories';

    protected $fillable = [
        'name',
    ];
}

