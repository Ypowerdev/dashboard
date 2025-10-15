<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParseJsonFileStatus extends Model
{
    protected $fillable = [
        'status',
        'filename',
        'log_file_link'
    ];

    public function markAsRunning(): void
    {
        $this->update([
            'status' => 'Обрабатывается'
        ]);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'Завершена обработка'
        ]);
    }

    public function markAsFailed(): void
    {
        $this->update([
            'status' => 'Завершилось с ошибкой'
        ]);
    }
    }
