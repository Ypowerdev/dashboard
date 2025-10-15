<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExcelUploadFileStatus extends Model
{
    public const S_RUNNING  = 'Обрабатывается';
    public const S_DONE     = 'Завершена обработка';
    public const S_FAILED   = 'Завершилось с ошибкой';

    protected $fillable = [
        'status', 'filename', 'log_file_link', 'user_id', 'job_id', 'started_at', 'finished_at',
        'origin_file',
        'uploaded_filename',
        'sheet_name',
        'created_at',
        'upload_uuid',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    // Скоупы
    public function scopeMine($q) { return $q->where('user_id', auth()->id()); }
    public function scopeRecent($q) { return $q->latest('created_at'); }

    // Методы смены статуса
    public function markAsRunning(): void
    {
        $this->update(['status' => self::S_RUNNING, 'started_at' => now(), 'finished_at' => null]);
    }
    public function markAsCompleted(): void
    {
        $this->update(['status' => self::S_DONE, 'finished_at' => now()]);
    }
    public function markAsFailed(): void
    {
        $this->update(['status' => self::S_FAILED, 'finished_at' => now()]);
    }
}
