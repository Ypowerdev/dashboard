<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * CУЩНОСТЬ, которая хранит информацию о том "кто и когда" сменял статусы ОКС для объекта
 */
class ObjectOksStatusChanges extends Model
{
    protected static $entity_type = 'ОКС статус';
    protected $table = 'object_oks_status_changes';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'object_id',
        'oks_status_library_id',
        'oks_status_library_previous_id',
        'user_id',
        'created_at',
    ];
}
