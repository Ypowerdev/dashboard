<?php

namespace App\Console\Commands;

use App\Imports\ExcelImport; // Убедитесь, что путь правильный
use App\Models\ExcelUploadFileStatus;
use App\Services\ExcelServices\CellUinCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Console\Command\Command as CommandAlias;

class ImportExcelCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:excel
                            {file_path : Путь к исходному файлу Excel (содержащему несколько листов).}
                            {userId : ID пользователя, который инициировал процесс}
                            {--temp-dir=excel_temp : Подкаталог в storage/app для временных файлов}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Разделяет файл Excel на листы, сохраняет их как отдельные файлы и импортирует каждый поочередно.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        ini_set('memory_limit', '1G');
        $filePathInput = $this->argument('file_path');
        $userId = $this->argument('userId');
        Log::info('ФАЙЛ обработки Эксель:'. $filePathInput);
        Log::info('Пользователь, который инициировал команду обработки Эксель:'. $userId);
        $tempDirName = trim($this->option('temp-dir'), '/\\'); // Убираем слеши в начале и конце

        // --- Логика определения пути к исходному файлу ---
        $isAbsolute = (strpos($filePathInput, '/') === 0) || (preg_match('/^[A-Za-z]:/', $filePathInput) === 1);

        if ($isAbsolute) {
            $fullPath = $filePathInput;
        } else {
            $fullPath = storage_path('app/' . ltrim($filePathInput, '/'));
        }

        $uploadedFileNameArr = explode('/', $fullPath);
        $originFileName = array_pop($uploadedFileNameArr);

        if (!file_exists($fullPath)) {
            $this->error("Исходный файл не найден: {$fullPath}");
            return CommandAlias::FAILURE;
        }
        $this->line("Используется исходный файл: {$fullPath}");
        // --- Конец логики определения пути ---

        $this->info("Начало обработки файла: {$fullPath}");
        $this->info("Каталог для временных файлов: storage/app/{$tempDirName}");

        try {
            // Очищаем кэш перед импортом
            CellUinCacheService::clearCache();

            // --- Создаем общую запись для всего файла ---
            $fileUuid = Str::uuid();
            $mainFileRecord = ExcelUploadFileStatus::create([
                'upload_uuid' => $fileUuid,
                'uploaded_filename' => $originFileName,
                'sheet_name' => 'Весь файл',
                'status' => 'Обрабатывается',
                'filename' => '-',
                'user_id' => $userId,
                'created_at' => now()
            ]);
            $this->info("Создана общая запись для файла: {$fileUuid}");

            // --- Создание временного каталога ---
            $tempDirPath = storage_path('app/' . $tempDirName);
            if (!file_exists($tempDirPath)) {
                if (!mkdir($tempDirPath, 0755, true)) {
                    $this->error("Не удалось создать временный каталог: {$tempDirPath}");
                    return CommandAlias::FAILURE;
                }
                $this->line("Создан временный каталог: {$tempDirPath}");
            } else {
                // Очищаем временный каталог от предыдущих файлов
                $this->line("Очистка временного каталога: {$tempDirPath}");
                $files = glob("{$tempDirPath}/*.xlsx");
                foreach($files as $file) {
                    if(is_file($file)) {
                        unlink($file);
                    }
                }
            }

            // --- Разделение файла на листы ---
            $this->line("Разделение файла на листы...");
            $createdFiles = $this->splitFileIntoSheets($fullPath, $tempDirPath);

            if (empty($createdFiles)) {
                $this->error("Не удалось создать файлы для листов или файл не содержит листов.");
                return CommandAlias::FAILURE;
            }

            $this->info("Созданы файлы для листов: " . implode(', ', array_column($createdFiles, 'name')));

            // --- Импорт каждого созданного файла поочередно ---
            $this->info("Начало поочередного импорта файлов листов...");
            foreach ($createdFiles as $createdFile) {
                $sheetFileName = $createdFile['name'];
                $sheetFilePath = $createdFile['path']; // Путь к временному файлу
                $sheetOriginalName = $createdFile['original_sheet_name'];

                $this->line("--- Импорт листа '{$sheetOriginalName}' из файла '{$sheetFileName}' ---");

                // Создаем экземпляр ExcelImport-делегата
                // Передаем имя листа И путь к временному файлу
                $importDelegate = new ExcelImport($sheetOriginalName, $sheetFilePath, $userId, $originFileName);

                // Вызываем метод делегата, который запустит специализированный импорт
                $importDelegate->import();

                $this->info("Импорт листа '{$sheetOriginalName}' завершен.");
            }

            // --- Обновляем статус общей записи ---

            $mainFileRecord->update([
                'status' => 'Файл обработан'
            ]);


            $this->info('--- Все листы успешно импортированы. ---');
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            // Очищаем кэш в случае ошибки
            CellUinCacheService::clearCache();
            $this->error('Ошибка во время обработки: ' . $e->getMessage());
            Log::error('Ошибка команды import:excel: ' . $e->getMessage(), ['exception' => $e]);
            return CommandAlias::FAILURE;
        }
    }

    /**
     * Разделяет Excel-файл на отдельные файлы по листам.
     *
     * @param string $inputFilePath Путь к исходному файлу.
     * @param string $outputDirPath Путь к каталогу для сохранения новых файлов.
     * @return array Массив с информацией о созданных файлах.
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    private function splitFileIntoSheets(string $inputFilePath, string $outputDirPath): array
    {
        $createdFiles = [];

        $inputFileType = IOFactory::identify($inputFilePath);
        $reader = IOFactory::createReader($inputFileType);

        Log::info("Попытка загрузки файла {$inputFilePath}");
        $spreadsheet = $reader->load($inputFilePath);

        $sheetNames = $spreadsheet->getSheetNames();

        if (empty($sheetNames)) {
            Log::warning("Файл {$inputFilePath} не содержит листов.");
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            return $createdFiles;
        }

        foreach ($sheetNames as $index => $sheetName) {

            if(!in_array($sheetName, ['5 - ОИВ факт', '4 - ОИВ план'])){
                continue;
            }

            try {
                Log::info("Попытка загрузки листа '{$sheetName}' из файла {$inputFilePath}");

                $readerForSingleSheet = IOFactory::createReader($inputFileType);
                $spreadsheetSingle = $readerForSingleSheet->load($inputFilePath);

                // Принудительно вычисляем формулы ДО сохранения
                foreach ($spreadsheetSingle->getAllSheets() as $worksheet) {
                    // Устанавливаем, что нужно вычислять формулы
                    $worksheet->setSelectedCells('A1');

                    // Проходим по всем ячейкам и вычисляем формулы
                    foreach ($worksheet->getRowIterator() as $row) {
                        $cellIterator = $row->getCellIterator();
                        $cellIterator->setIterateOnlyExistingCells(false);

                        foreach ($cellIterator as $cell) {
                            if ($cell->getValue() !== null && strpos((string)$cell->getValue(), '=') === 0) {
                                try {
                                    // Принудительно вычисляем формулу
                                    $calculatedValue = $cell->getCalculatedValue();
                                    // Устанавливаем вычисленное значение как реальное значение
                                    $cell->setValue($calculatedValue);
                                } catch (\Exception $e) {
                                    Log::warning("Ошибка вычисления формулы в ячейке " . $cell->getCoordinate() . ": " . $e->getMessage());
                                }
                            }
                        }
                    }
                }

                // Удаляем все листы, кроме нужного
                foreach ($spreadsheetSingle->getSheetNames() as $name) {
                    if ($name !== $sheetName) {
                        $spreadsheetSingle->removeSheetByIndex(
                            $spreadsheetSingle->getIndex(
                                $spreadsheetSingle->getSheetByName($name)
                            )
                        );
                    }
                }

                Log::info("Лист '{$sheetName}' успешно загружен. Создание временного файла...");

                // Остальной код остается без изменений...
                $transliterated = $sheetName;
                if (class_exists('Transliterator')) {
                    $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII;');
                    if ($transliterator) {
                        $transliterated = $transliterator->transliterate($sheetName);
                    }
                }
                $sanitizedName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $transliterated);
                $sanitizedName = preg_replace('/_+/', '_', $sanitizedName);
                $sanitizedName = substr($sanitizedName, 0, 100);
                $outputFileName = "sheet_" . ($index + 1) . "_" . trim($sanitizedName, '_') . ".xlsx";

                $outputFilePath = $outputDirPath . DIRECTORY_SEPARATOR . $outputFileName;

                $writer = new Xlsx($spreadsheetSingle);
                $writer->save($outputFilePath);

                $createdFiles[] = [
                    'name' => $outputFileName,
                    'path' => $outputFilePath,
                    'original_sheet_name' => $sheetName
                ];

                $this->line("  Создан файл для листа '{$sheetName}': {$outputFileName}");

                $spreadsheetSingle->disconnectWorksheets();
                unset($spreadsheetSingle, $writer, $readerForSingleSheet);

            } catch (\Exception $e) {
                Log::error("Ошибка при создании файла для листа '{$sheetName}': " . $e->getMessage());
                $this->error("Ошибка при создании файла для листа '{$sheetName}': " . $e->getMessage());
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $reader);

        return $createdFiles;
    }

    /**
     * Очищает временные файлы (опционально).
     * Можно вызвать в блоке finally.
     *
     * @param string $tempDirPath
     */
    // private function cleanupTempFiles(string $tempDirPath)
    // {
    //     $this->line("Очистка временных файлов...");
    //     $files = glob("{$tempDirPath}/sheet_*.xlsx");
    //     foreach($files as $file) {
    //         if(is_file($file)) {
    //             unlink($file);
    //         }
    //     }
    //     $this->line("Временные файлы очищены.");
    // }
}
