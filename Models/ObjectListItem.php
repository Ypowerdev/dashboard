<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObjectListItem extends Model
{
    protected $table = 'object_list_object';
    public $timestamps = false;

    protected $fillable = [
        'object_list_id',
        'object_id',
        'position',
    ];

    public function list(): BelongsTo
    {
        return $this->belongsTo(ObjectList::class, 'object_list_id');
    }

    public function object(): BelongsTo
    {
        return $this->belongsTo(ObjectModel::class, 'object_id');
    }
}
