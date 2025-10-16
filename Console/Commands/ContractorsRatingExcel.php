<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\Api\RatingController;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Maatwebsite\Excel\Facades\Excel;
use App\Helpers\ExcelExportHelper;

Class ContractorsRatingExcel extends Command
{
    protected $signature = 'app:contractorsRatingExcel';
    protected $description = 'Генерация Excel рейтингов подрядчиков';

    // Названия столбцов для выгрузки
    private array $exportFields = [
        'id' => '№',
        'contractor' => 'Подрядчик',
        'total' => 'Всего запланировано для ввода',
        'entered_with_violations' => 'Введено (с нарушениями)',
        'completed_on_time' => 'Реализовано без нарушения сроков',
        'completed_late' => 'Реализовано с нарушениями сроков',
        'culture_issues' => 'С замечаниями по культуре производства',
        'problematic_percentage' => 'Доля проблемных объектов',
    ];

    /**
     * Основной метод выполнения команды
     */
    public function handle()
    {
        $periodDate = $this->getPeriodDates();
        /** @var RatingController $controller */
        $controller = app(RatingController::class);
        $objects = $controller->contractorData($periodDate);

        $exportData = array_merge([$this->exportFields], $objects->toArray());

        $exportData = array_filter($exportData, function($item) {
            return !is_null($item);
        });

        // Путь к файлу
        $filePath = 'excel/contractors_rating.xlsx';

        Storage::makeDirectory('excel');

        // Сохранение в storage/app/excel/
        Excel::store(new ExcelExportHelper($exportData), $filePath);

        // Вывод ссылки на скачивание
        $fullPath = storage_path("app/{$filePath}");
        $this->info("Файл сохранен: {$fullPath}");
        $this->info("Скачать файл: " . url("storage/{$filePath}"));
    }

    public function contractorsExcel($objects): BinaryFileResponse
    {
        $exportData = array_merge([$this->exportFields], $objects->toArray());

        $exportData = array_filter($exportData, function($item) {
            return !is_null($item);
        });

        return Excel::download(new ExcelExportHelper($exportData), 'contractors_rating.xlsx');
    }

    /**
     * @return array
     */
    private function getPeriodDates (): array
    {
        return [
            'period_start' => '1970-01-01',
            'period_end' => now()->addYears(100)->format('Y-m-d'),
        ];
    }
}
