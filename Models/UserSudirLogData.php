<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSudirLogData Extends Model
{
    protected $table = 'users_sudir_log_data';

    protected $fillable = [
        'user_id',
        'email',
        'sudir_data',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'sudir_data' => 'array',
    ];

    public $timestamps = false;

    /**
     * Пользователь, которому принадлежат данные из СУДИР
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Заполняет модель данными из ответа СУДИР
     */
    public function fillFromOAuthData(array $data): self
    {
        $this->email = $data['email'] ?? null;
        $this->sudir_data = $data;

        return $this;
    }
}
