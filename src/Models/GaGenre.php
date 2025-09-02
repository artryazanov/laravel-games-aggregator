<?php

namespace Artryazanov\GamesAggregator\Models;

use Illuminate\Database\Eloquent\Model;

class GaGenre extends Model
{
    protected $table = 'ga_genres';

    protected $fillable = [
        'name',
    ];
}

