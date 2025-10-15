<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;

class MapCoordinatesLibrary extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'uin',
        'latitude',
        'longitude',
        'coordinates',
    ];

    protected $casts = [
        'uin' => 'string',
        'latitude' => 'float',
        'longitude' => 'float',
        'coordinates' => 'string',
    ];
}
