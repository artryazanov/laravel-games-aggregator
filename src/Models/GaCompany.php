<?php

namespace Artryazanov\GamesAggregator\Models;

use Illuminate\Database\Eloquent\Model;

class GaCompany extends Model
{
    protected $table = 'ga_companies';

    protected $fillable = [
        'name',
    ];
}
