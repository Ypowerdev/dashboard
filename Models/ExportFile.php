<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportFile extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'disk',
        'path',
        'filename',
        'status',
        'size',
        'checksum',
        'meta',
        'expires_at',
        'error',
    ];
    
    protected $casts = ['meta' => 'array', 'expires_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
