<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Задача на импорт Excel через Artisan-команду.
 */
class ImportExcelJob implements ShouldQueue
{
    use Queueable;

    /** Сколько секунд можно выполнять эту джобу */
    public int $timeout = 3600; // 10 минут (поставь своё)

    /** Сколько попыток (чтобы не дублировать тяжёлую выгрузку) */
    public int $tries = 3;

    /**
     * Данные для экспорта.
     *
     * @var int
     */
    protected int $userId;
    protected string $file_path;

    /**
     * Создание задачи.
     *
     * @param  int|null  $userId
     * @return void
     */
    public function __construct($userId = null, $file_path = null)
    {
        $this->userId = $userId;
        $this->file_path = $file_path;
        $this->onQueue('excel-import');
    }

    /**
     * Выполнение задачи.
     *
     * @return void
     */
    public function handle(): void
    {
        Artisan::call('import:excel', [
            'file_path'  => $this->file_path,
            'userId'  => $this->userId,
        ]);
    }
}
