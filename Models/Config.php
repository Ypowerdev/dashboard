<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Config extends Model
{
    protected $table = 'configs';

    public $timestamps = false;

    protected $fillable = [
        'value',
        'config_alias',
        'config_name',
    ];

    public static function getDeadLineDays()
    {
        return (int)(self::where('config_alias','deadline_days')->pluck('value')->firstOrFail());
    }
}