<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\ExcelExportService;

/**
 * Задача на экспорт Excel через Artisan-команду.
 */
class ExportExcelJob implements ShouldQueue
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

    /**
     * Создание задачи.
     *
     * @param  int|null  $userId
     * @return void
     */
    public function __construct($userId = null)
    {
        $this->userId = $userId;
        $this->onQueue('exporting_excel');
    }

    /**
     * Выполнение задачи.
     *
     * @return void
     */
    public function handle(ExcelExportService $export): void
    {
        $export->generateExcelReport(userId: $this->userId);
    }
}
