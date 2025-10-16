<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use ParseCsv\Csv;
use Throwable;
use Illuminate\Support\Facades\Log;

/**
 * Консольная команда для обработки CSV файлов
 *
 * Обрабатывает CSV файлы и сохраняет результат в виде PHP файла с массивами данных
 */
class ProcessCsvCommand extends Command
{
    /**
     * Сигнатура консольной команды
     *
     * @var string
     */
    protected $signature = 'csv:process
                            {filename=example_for_parsing.csv : Путь к CSV файлу в storage/app/cvsimports}
                            {--delimiter=$ : Разделитель в CSV файле}';

    /**
     * Описание консольной команды
     *
     * @var string
     */
    protected $description = 'Обработка CSV файла и генерация PHP массива';

    /**
     * Выполнение консольной команды
     *
     * @return int 0 - успех, 1 - ошибка
     */
    public function handle(): int
    {
        try {
            $storagePath = storage_path('app/cvsimports');
            $inputFile = "$storagePath/{$this->argument('filename')}";

            // Создание директории при необходимости
            if (!is_dir($storagePath) && !mkdir($storagePath, 0755) && !is_dir($storagePath)) {
                Log::error('Не удалось создать директорию: ' . $storagePath);  // <--
                return self::FAILURE;
            }

            if (!file_exists($inputFile)) {
                Log::error('Файл не найден: ' . $inputFile);
                return self::FAILURE;
            }

            $csv = new Csv();
            $csv->encoding('UTF-8', 'UTF-8');
            $csv->delimiter = $this->option('delimiter');
            $csv->parseFile($inputFile);

            if (empty($csv->data)) {
                Log::error('CSV данные пусты');
                return self::FAILURE;
            }

            // Вывод данных с цветовой разметкой
            foreach ($csv->data as $key => $row) {
                $this->line("<fg=yellow>┌──[ Row $key ]──────────────────────────────</>");

                foreach ($row as $field => $value) {
                    $this->line(sprintf(
                        "<fg=cyan>│ %-20s</> <fg=green>%s</>",
                        $field . ':',
                        $value ?: '<fg=red>N/A</>'
                    ));
                }
                $this->line("<fg=yellow>└───────────────────────────────────────────</>\n");
            }

            Log::info('Успешное выполнение');
            return self::SUCCESS;

        } catch (Throwable $e) {
            Log::error('Ошибка выполнения: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
