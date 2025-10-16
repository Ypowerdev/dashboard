<?php

namespace App\Console\Commands;

use App\Helpers\JsonHelper;
use App\Models\Library\ConstructionStagesLibrary;
use App\Models\Library\OksStatusLibrary;
use App\Models\MongoChangeLogModel;
use App\Models\ObjectConstructionStage;
use App\Models\ObjectModel;
use App\Models\User;
use DateTime;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Класс ParseJSON обрабатывает JSON файлы из определенных директорий
 * и применяет соответствующие методы обработки в зависимости от расположения файла
 */
class ParseJSON extends CommandWithIsolatedWarning
{
    /**
     * Сигнатура команды с добавленными параметрами для папки и файла
     *
     * @var string
     */
    protected $signature = 'app:parse-json
                            {--folder=07 : Название папки внутри storage/app/json_samples}
                            {--file= : Полный путь к конкретному файлу для обработки}
                            {--username= : Имя пользователя }';

    /**
     * Описание команды
     *
     * @var string
     */
    protected $description = 'Парсинг JSON файлов с данными объектов';

    private $errorCount = 0;
    private $currentProcessingFile = null; // Текущий обрабатываемый файл
    private $jsonFiles = []; // Массив для хранения найденных JSON файлов
    private $initiatorUserName = null;
    private $initiatorUserId = null;
    private $userId = null;

    // Один метод в parseJSON который принимает массив и отдал ссылки на логи по ошибкам

    public function __construct()
    {
        parent::__construct();
        ini_set('memory_limit', '1024M');
        set_time_limit(3600); // 1 час
    }

    /**
     * Основной метод выполнения команды
     */
    public function handle()
    {
        try {
            if ($this->option('username')) {
                $this->initiatorUserName = $this->option('username');
                $this->initiatorUserId = User::where('name', $this->initiatorUserName)
                    ->orWhere('family_name', $this->initiatorUserName)
                    ->orWhere('father_name', $this->initiatorUserName)
                    ->pluck('id')
                    ->first();
            }

            // Если указан конкретный файл - обрабатываем только его
            if ($filePath = $this->option('file')) {
                if (!File::exists($filePath)) {
                    $this->error("Файл не найден: $filePath");
                    return 1;
                }

                // Сохраняем относительный путь от storage/app/json_samples
                $relativePath = str_replace(storage_path('app/json_samples/'), '', $filePath);

                $fileInfo = [
                    'path' => $filePath,
                    'name' => $relativePath // Используем относительный путь вместо basename
                ];

                $this->processJsonFile($fileInfo);

                // Вывод информации об ошибках
                if ($this->errorCount > 0) {
                    $this->warn("Обработка завершена с {$this->errorCount} ошибками.");
                } else {
                    $this->info("Обработка завершена успешно без ошибок.");
                }

                return 0;
            }

            // Иначе работаем в обычном режиме
            $folderName = $this->option('folder');
            $this->scanJsonFiles($folderName);

            if (empty($this->jsonFiles)) {
                $this->error('JSON файлы не найдены в директории storage/app/json_samples');
                return 1;
            }

            $selectedFile = $this->selectFile();
            if ($selectedFile) {
                $this->processJsonFile($selectedFile);
            }

            // Вывод информации об ошибках в конце работы
            if ($this->errorCount > 0) {
                $this->warn("Обработка завершена с {$this->errorCount} ошибками. Подробности в файле: tmp/ParserJsonError.log");
            } else {
                $this->info("Обработка завершена успешно без ошибок.");
            }

            $this->warn("Запуск выполнения ночного скрипта...");
            Artisan::call('app:every-night-script');

            exec('echo -ne "\a"');

            return 0;
        } catch (Throwable $e) {
            $this->logException($e);
            $this->error("Критическая ошибка: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Сканирование JSON файлов в указанной директории
     */
    private function scanJsonFiles($folderName)
    {
        $basePath = storage_path('app/json_samples');
//        $basePath = storage_path('app/json_samples/'.$folderName);

        if (!File::exists($basePath)) {
            File::makeDirectory($basePath, 0755, true);
        }

        $files = File::allFiles($basePath);

        foreach ($files as $file) {
            if ($file->getExtension() === 'json') {
                $relativePath = str_replace($basePath . '/', '', $file->getPathname());
                $this->jsonFiles[] = [
                    'path' => $file->getPathname(),
                    'name' => $relativePath
                ];
            }
        }
    }

    /**
     * Выбор файла для обработки
     */
    private function selectFile()
    {
        $choices = collect($this->jsonFiles)->pluck('name')->toArray();
        $choices[] = '[АВТО] Обработать все файлы автоматически';

        $selectedFileName = $this->choice(
            'Выберите JSON файл для обработки:',
            $choices
        );

        if ($selectedFileName === '[АВТО] Обработать все файлы автоматически') {
            // Порядок обработки файлов
            $processingOrder =

            [

            ];

            foreach ($processingOrder as $fileName) {
                $fileInfo = collect($this->jsonFiles)->firstWhere('name', $fileName);
                if ($fileInfo) {
                    $this->info("\nОбработка файла: $fileName");
                    $this->processJsonFile($fileInfo);
                }
            }
            return null;
        }

        return collect($this->jsonFiles)
            ->firstWhere('name', $selectedFileName);
    }

    /**
     * Определение метода обработки на основе расположения файла
     */
    private function processJsonFile(array $fileInfo)
    {
        $this->warn("Обработка файла: {$fileInfo['name']}");

        // Сохраняем информацию о текущем обрабатываемом файле
        $this->currentProcessingFile = $fileInfo;

        $processor = $this->determineProcessor($fileInfo);

        if(!$processor){
            $this->error($fileInfo['name'].' - непонятно по какому сценарию работать');
            die();
        }

        if ($processor) {
            $this->$processor($fileInfo);
        }

//        $this->warn('Начинаем обновление окс статусов');
        // ParseOksStatus::processJsonFile($fileInfo, $this->initiatorUserId, $this->initiatorUserName);
    }

    /**
     * Определение нужного обработчика на основе пути к файлу
     */
    private function determineProcessor(array $fileInfo)
    {
        $path = mb_strtolower($fileInfo['name']);
        $prefix = '';

        // Определяем тип документа по наличию ключевого слова
        if (str_contains($path, 'дгс')) {
            $prefix = 'processDgs';
        } elseif (str_contains($path, 'дстии')) {
            $prefix = 'processDstii';
        } elseif (str_contains($path, 'дгп') || str_contains($path, 'мфр')) {
            $prefix = 'processDgp'; // Renamed from processMfr to processDgp
        } elseif (str_contains($path, 'дрнт')) {
            $prefix = 'processDrnt';
        } elseif (str_contains($path, 'смг')) {
            $prefix = 'processSMG';
        }

        if (empty($prefix)) {
            return null;
        }

        // Определяем тип операции по номеру и ключевым словам
        if (str_contains($path, '1') && str_contains($path, 'ежедневный')) {
            return $prefix . 'Daily';
        }
        if (str_contains($path, '2') && str_contains($path, 'срывы')) {
            return $prefix . 'DelaysAndActions';
        }
        if (str_contains($path, '2') && str_contains($path, 'культура')) {
            return $prefix . 'CultureManufacture';
        }
        if (str_contains($path, '3') && str_contains($path, 'ресурсы план')) {
            return $prefix . 'ResourcesPlan';
        }
        if (str_contains($path, '4') && str_contains($path, 'оив план')) {
            return $prefix . 'Plan';
        }
        if (str_contains($path, '5') && str_contains($path, 'оив факт')) {
            return $prefix . 'Fact';
        }

        return null;
    }


    /**
     * Обработка данных ресурсов плана ДГС
     * Обрабатывает файл: "3 - ОИВ ресурсы план.json" из директории ДГС
     * @param array $fileInfo Информация о файле
     */
    private function processDgsResourcesPlan(array $fileInfo)
    {
        // Определение OIV_ID для ДГС
        $oivId = 2; // ДГС - 1
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка данных ресурсов плана ДГС...');

        foreach ($data as $record) {
            if($this->checkUin($record->{'УИН'}) === false){
                continue;
            }
            JsonHelper::processMonitorData(
                $record,
                'plan_month', 
                initiator_user_id: $this->initiatorUserId ?? null,
                initiator_user_name: $this->initiatorUserName ?? null,
                json_user_id: $userID ?? null,
            );
        }
    }


    /**
     * Обработка файлов плана ДГП
     * Обрабатывает файл: "4 - ОИВ план.json" из директории ДГП
     * @param array $fileInfo Информация о файле
     */
    private function processDgpPlan(array $fileInfo)
    {
        // Определение OIV_ID для ДГП
        $oivId = 3; // ДГП - 3
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка данных плана ДГП...');
        // Здесь будет логика обработки данных
        $this->parseBigJsonData($data,$oivId,'processDgpPlan');
    }

    /**
     * Обработка файлов срывов и действий ДГП
     * Обрабатывает файл: "2 - СМГ срывы и действия.json" из директории ДГП
     * @param array $fileInfo Информация о файле
     */
    private function processDgpDelaysAndActions(array $fileInfo)
    {
        // Определение OIV_ID для ДГП
        $oivId = 3; // ДГП - 3
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка данных срывов и действий ДГП...');
        // Здесь будет логика обработки данных
        foreach ($data as $record){
            $this->info("Processing record ID: {$record->{'УИН'}}");
            if($this->checkUin($record->{'УИН'}) === false){
                continue;
            }
            $object = ObjectModel::getByUIN($record->{'УИН'});
            JsonHelper::processBreakDownData($record,$object);
        }
    }

    /**
     * Обработка файлов факта ДГП
     * Обрабатывает файл: "5 - ОИВ факт.json" из директории ДГП
     * @param array $fileInfo Информация о файле
     */
    private function processDgpFact(array $fileInfo)
    {
        // Определение OIV_ID для ДГП
        $oivId = 3; // ДГП - 3
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка данных факта ДГП...');
        // Здесь будет логика обработки данных
        $this->parseBigJsonDataWithSmallObjectDataFromOIVFact($data,$oivId,'processDgpFact');
        foreach ($data as $oneJSON){
            JsonHelper::processMonitorData($oneJSON, 
                initiator_user_id: $this->initiatorUserId ?? null,
                initiator_user_name: $this->initiatorUserName ?? null,
                json_user_id: $userID ?? null,
            );
        }
    }

    /**
     * Обработка файлов плана ДСТИИ
     * Обрабатывает файл: "4 - ОИВ план.json" из директории ДСТИИ
     * @param array $fileInfo Информация о файле
     */
    private function processDstiiPlan(array $fileInfo) {
        // Определение OIV_ID для ДСТИИ
        $oivId = 4; // ДСТИИ - 4
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка данных плана ДСТИИ...');
        // Здесь будет логика обработки данных
        $this->parseBigJsonData($data,$oivId,'processDstiiPlan');
    }

    /**
     * Обработка файлов факта ДСТИИ
     * Обрабатывает файл: "5 - ОИВ факт.json" из директории ДСТИИ
     * @param array $fileInfo Информация о файле
     */
    private function processDstiiFact(array $fileInfo)
    {
        // Определение OIV_ID для ДСТИИ
        $oivId = 4; // ДСТИИ - 4
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка данных факта ДСТИИ...');

        // Здесь будет логика обработки данных
        $this->parseBigJsonDataWithSmallObjectDataFromOIVFact($data,$oivId,'processDstiiFact');

        foreach ($data as $oneJSON){
            JsonHelper::processMonitorData($oneJSON, 
                initiator_user_id: $this->initiatorUserId ?? null,
                initiator_user_name: $this->initiatorUserName ?? null,
                json_user_id: $userID ?? null,
            );
        }
    }

    /**
     * Обработка файлов плана ДГС
     * Обрабатывает файл: "4 - ОИВ план.json" из директории ДГС
     * @param array $fileInfo Информация о файле
     */
    private function processDgsPlan(array $fileInfo)
    {
        // Определение OIV_ID для ДГС
        $oivId = 2; // ДГС - 1
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка данных плана ДГС...');
        // Здесь будет логика обработки данных
        $this->parseBigJsonData($data,$oivId,'processDgsPlan');
    }

    /**
     * Обработка файлов факта ДГС
     * Обрабатывает файл: "5 - ОИВ факт.json" из директории ДГС
     * @param array $fileInfo Информация о файле
     */
    private function processDgsFact(array $fileInfo)
    {
        // Определение OIV_ID для ДГС
        $oivId = 2; // ДГС - 1
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка данных факта ДГС...');
        // Здесь будет логика обработки данных
        $this->parseBigJsonDataWithSmallObjectDataFromOIVFact($data,$oivId,'processDgsFact');

        foreach ($data as $oneJSON){
            JsonHelper::processMonitorData($oneJSON, 
                initiator_user_id: $this->initiatorUserId ?? null,
                initiator_user_name: $this->initiatorUserName ?? null,
                json_user_id: $userID ?? null,
            );
        }
    }

    /**
     * Обработка ежедневных данных ДГС
     * Обрабатывает файл: "1 - СМГ ежедневный.json" из директории ДГС
     * @param array $fileInfo Информация о файле
     */
    private function processDgsDaily(array $fileInfo)
    {
        // Определение OIV_ID для ДГС
        $oivId = 2; // ДГС - 1
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка ежедневных данных ДГС...');
        $this->parseBigJsonDataWithIgnoreObjectData($data,$oivId,'processDgsDaily');

    }

    /**
     * Обработка срывов и действий ДГС
     * Обрабатывает файл: "2 - СМГ срывы и действия.json" из директории ДГС
     * @param array $fileInfo Информация о файле
     */
    private function processDgsDelaysAndActions(array $fileInfo)
    {
        // Определение OIV_ID для ДГС
        $oivId = 2; // ДГС - 1
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка срывов и действий ДГС...');
        // Здесь будет логика обработки данных
        foreach ($data as $record){
            $this->info("Processing record ID: {$record->{'УИН'}}");
            if($this->checkUin($record->{'УИН'}) === false){
                continue;
            }
            $object = ObjectModel::getByUIN($record->{'УИН'});
            JsonHelper::processBreakDownData($record,$object);
        }
    }

    /**
     * Обработка ежедневных данных ДСТИИ
     * Обрабатывает файл: "1 - СМГ ежедневный.json" из директории ДСТИИ
     * @param array $fileInfo Информация о файле
     */
    private function processDstiiDaily(array $fileInfo)
    {
        // Определение OIV_ID для ДСТИИ
        $oivId = 4; // ДСТИИ - 4
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка ежедневных данных ДСТИИ...');
        $this->parseBigJsonDataWithIgnoreObjectData($data,$oivId,'processDstiiDaily');

    }

    /**
     * Обработка ежедневных данных ДСТИИ
     * Обрабатывает файл: "1 - СМГ ежедневный.json" из директории ДСТИИ
     * @param array $fileInfo Информация о файле
     */
    private function processSMGDaily(array $fileInfo)
    {
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка ежедневных данных SMG...');
        $this->parseBigJsonDataWithIgnoreObjectData($data,null,'processSMGDaily');

    }

    /**
     * Обработка срывов и действий ДСТИИ
     * Обрабатывает файл: "2 - СМГ срывы и действия.json" из директории ДСТИИ
     * @param array $fileInfo Информация о файле
     */
    private function processDstiiDelaysAndActions(array $fileInfo)
    {
        // Определение OIV_ID для ДСТИИ
        $oivId = 4; // ДСТИИ - 4
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка срывов и действий ДСТИИ...');
        // Здесь будет логика обработки данных
        foreach ($data as $record){
            $this->info("Processing record ID: {$record->{'УИН'}}");
            if($this->checkUin($record->{'УИН'}) === false){
                continue;
            }
            $object = ObjectModel::getByUIN($record->{'УИН'});
            JsonHelper::processBreakDownData($record,$object);
        }
    }

    /**
     * Обработка ресурсов плана ДСТИИ
     * Обрабатывает файл: "3 - ОИВ ресурсы план.json" из директории ДСТИИ
     * @param array $fileInfo Информация о файле
     */
    private function processDstiiResourcesPlan(array $fileInfo)
    {
        // Определение OIV_ID для ДСТИИ
        $oivId = 4; // ДСТИИ - 4
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка ресурсов плана ДСТИИ...');

        foreach ($data as $record) {
            if($this->checkUin($record->{'УИН'}) === false){
                continue;
            }
            JsonHelper::processMonitorData($record, 'plan_month', 
                initiator_user_id: $this->initiatorUserId ?? null,
                initiator_user_name: $this->initiatorUserName ?? null,
                json_user_id: $userID ?? null,
            );
        }
    }

    /**
     * Обработка ежедневных данных ДГП
     * Обрабатывает файл: "1 - СМГ ежедневный.json" из директории ДГП
     * @param array $fileInfo Информация о файле
     */
    private function processDgpDaily(array $fileInfo)
    {
        // Определение OIV_ID для ДГП
        $oivId = 3; // ДГП - 3
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка ежедневных данных ДГП...');
        $this->parseBigJsonDataWithIgnoreObjectData($data,$oivId,'processDgpDaily');
    }

    /**
     * Обработка ресурсов плана ДГП
     * Обрабатывает файл: "3 - ОИВ ресурсы план.json" из директории ДГП
     * @param array $fileInfo Информация о файле
     */
    private function processDgpResourcesPlan(array $fileInfo)
    {
        // Определение OIV_ID для ДГП
        $oivId = 3; // ДГП - 3
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка данных ресурсов плана ДГП...');

        foreach ($data as $record) {
            if($this->checkUin($record->{'УИН'}) === false){
                continue;
            }
            JsonHelper::processMonitorData($record,'plan_month', 
                initiator_user_id: $this->initiatorUserId ?? null,
                initiator_user_name: $this->initiatorUserName ?? null,
                json_user_id: $userID ?? null,
            );
        }
    }

    /**
     * Обработка ежедневных данных ДРНТ
     * Обрабатывает файл: "1 - СМГ ежедневный.json" из директории ДРНТ
     * @param array $fileInfo Информация о файле
     */
    private function processDrntDaily(array $fileInfo)
    {
        // Определение OIV_ID для ДРНТ
        $oivId = 1; // ДРНГТ - 1
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка ежедневных данных ДРНТ...');
        $this->parseBigJsonDataWithIgnoreObjectData($data,$oivId,'processDrntDaily');
    }

    /**
     * Обработка срывов и действий ДРНТ
     * Обрабатывает файл: "2 - СМГ срывы и действия.json" из директории ДРНТ
     * @param array $fileInfo Информация о файле
     */
    private function processDrntDelaysAndActions(array $fileInfo)
    {
        // Определение OIV_ID для ДРНТ
        $oivId = 1; // ДРНГТ - 1
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка срывов и действий ДРНТ...');
        // Здесь будет логика обработки данных
        foreach ($data as $record){
            $this->info("Processing record ID: {$record->{'УИН'}}");
            if($this->checkUin($record->{'УИН'}) === false){
                continue;
            }
            $object = ObjectModel::getByUIN($record->{'УИН'});
            JsonHelper::processBreakDownData($record,$object);
        }
    }

    /**
     * Обработка данных ресурсов плана ДРНТ
     * Обрабатывает файл: "3 - ОИВ ресурсы план.json" из директории ДРНТ
     * @param array $fileInfo Информация о файле
     */
    private function processDrntResourcesPlan(array $fileInfo)
    {
        // Определение OIV_ID для ДРНТ
        $oivId = 1; // ДРНГТ - 1
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка ресурсов плана ДРНТ...');

        foreach ($data as $record) {
            if($this->checkUin($record->{'УИН'}) === false){
                continue;
            }
            JsonHelper::processMonitorData($record,'plan_month', 
                initiator_user_id: $this->initiatorUserId ?? null,
                initiator_user_name: $this->initiatorUserName ?? null,
                json_user_id: $userID ?? null,
            );
        }
    }

    /**
     * Обработка данных плана ДРНТ
     * Обрабатывает файл: "4 - ОИВ план.json" из директории ДРНТ
     * @param array $fileInfo Информация о файле
     */
    private function processDrntPlan(array $fileInfo)
    {
        // Определение OIV_ID для ДРНТ
        $oivId = 1; // ДРНГТ - 1
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка плана ДРНТ...');
        $this->parseBigJsonData($data,$oivId,'processDrntPlan');
    }

    /**
     * Обработка данных факта ДРНТ
     * Обрабатывает файл: "5 - ОИВ факт.json" из директории ДРНТ
     * @param array $fileInfo Информация о файле
     */
    private function processDrntFact(array $fileInfo)
    {
        // Определение OIV_ID для ДРНТ
        $oivId = 1; // ДРНГТ - 1
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка факта ДРНТ...');
        $this->parseBigJsonDataWithSmallObjectDataFromOIVFact($data,$oivId,'processDrntFact');

        foreach ($data as $oneJSON){
            JsonHelper::processMonitorData($oneJSON, 
                initiator_user_id: $this->initiatorUserId ?? null,
                initiator_user_name: $this->initiatorUserName ?? null,
                json_user_id: $userID ?? null,
            );
        }
    }

    /**
     * Обработка данных культуры производства ДГС
     * Обрабатывает файл: "2 - культура производства.json" из директории ДГС
     * @param array $fileInfo Информация о файле содержащая путь и имя файла
     * @return void
     */
    private function processDgsCultureManufacture(array $fileInfo)
    {
        // Определение OIV_ID для ДГС
        $oivId = 2; // ДГС - 1
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка данных культуры ДГС...');
        // Здесь будет логика обработки данных
        foreach ($data as $record) {
            if($this->checkUin($record->{'УИН'}) === false){
                continue;
            }
            JsonHelper::processCultureManufactureData($record);
        }
    }

    /**
     * Обработка данных культуры производства ДСТИИ
     * Обрабатывает файл: "2 - культура производства.json" из директории ДСТИИ
     * @param array $fileInfo Информация о файле содержащая путь и имя файла
     * @return void
     */
    private function processDstiiCultureManufacture(array $fileInfo)
    {
        // Определение OIV_ID для ДСТИИ
        $oivId = 4; // ДСТИИ - 4
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка данных культуры ДСТИИ...');
        // Здесь будет логика обработки данных
        foreach ($data as $record) {
            if($this->checkUin($record->{'УИН'}) === false){
                continue;
            }
            JsonHelper::processCultureManufactureData($record);
        }
    }

    /**
     * Обработка данных культуры производства ДГП
     * Обрабатывает файл: "2 - культура производства.json" из директории ДГП
     * @param array $fileInfo Информация о файле содержащая путь и имя файла
     * @return void
     */
    private function processDgpCultureManufacture(array $fileInfo)
    {
        // Определение OIV_ID для ДГП
        $oivId = 3; // ДГП - 3
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка данных культуры ДГП...');
        // Здесь будет логика обработки данных
        foreach ($data as $record) {
            if($this->checkUin($record->{'УИН'}) === false){
                continue;
            }
            JsonHelper::processCultureManufactureData($record);
        }
    }

    /**
     * Обработка данных культуры производства ДРНТ
     * Обрабатывает файл: "2 - культура производства.json" из директории ДРНТ
     * @param array $fileInfo Информация о файле содержащая путь и имя файла
     * @return void
     */
    private function processDrntCultureManufacture(array $fileInfo)
    {
        // Определение OIV_ID для ДРНТ
        $oivId = 1; // ДРНГТ - 1
        $data = json_decode(File::get($fileInfo['path']), false);
        $this->info('Обработка данных культуры ДРНТ...');
        // Здесь будет логика обработки данных

        foreach ($data as $record) {
            if($this->checkUin($record->{'УИН'}) === false){
                continue;
            }
            JsonHelper::processCultureManufactureData($record);
        }
    }


    /**
     * Обновляет запись объекта или создает новую, если она не существует.
     *
     * @param array $newRecord
     * @return int|null
     */
    private function updateOrCreateObjectRecord(array $newRecord,$userID): ?int
    {
        // $uniqueUIN = $newRecord['uin'] ?? $newRecord['УИН'];
        $uniqueUIN = $newRecord['uin'];

        try {
            // Поиск записи по уникальному UIN
            $currentRecord = ObjectModel::where('uin', $uniqueUIN)->first();

            if (!$currentRecord) {
                Log::info("Запись с UIN $uniqueUIN не найдена, добавляем новую.");
                $this->info("Запись с UIN $uniqueUIN не найдена, добавляем новую.");
                $newObject = ObjectModel::create($newRecord);
                if ($newObject) {
                    MongoChangeLogModel::logCreate(
                        record: $newObject,
                        entity_type: 'Строительный объект',
                        initiator_user_id: $this->initiatorUserId ?? null,
                        initiator_user_name: $this->initiatorUserName ?? null,
                        json_user_id: $userID ?? null,
                    );
                }

                return $newObject->id; // Возвращаем ID новой записи
            }

            // Преобразуем записи в массивы для сравнения
            $currentData = $currentRecord->toArray();
            $newData = $newRecord;

            // Сравниваем даты обновления
            $currentDate = JsonHelper::validateAndAdaptDate($currentData['updated_date']);
            $newDate = JsonHelper::validateAndAdaptDate($newData['updated_date']);

            // Преобразуем строки в объекты DateTime, если они ещё не являются таковыми
            if (is_string($currentDate)) {
                $currentDateTIME = new DateTime($currentDate);
            }
            if (is_string($newDate)) {
                $newDateTIME = new DateTime($newDate);
            }

            if (!empty($currentDate) && !empty($newDate) && ($currentDateTIME > $newDateTIME)) {
                Log::warning("Дата записи более ранняя, или равна той, что в нашей БД - игнорируем обновление для UIN $uniqueUIN.");
                $this->warn("Дата записи более ранняя, или равна той, что в нашей БД - игнорируем обновление для UIN $uniqueUIN.");

                $this->warn("НОВАЯ: ".$newDate." / СТАРАЯ: ".$currentDate);
                return $currentRecord->id; // Возвращаем текущий ID
            }

            // Сравниваем изменения
            $changes = [];
            foreach ($newData as $key => &$value) {
                if (array_key_exists($key, $currentData)) {
                    // Пропускаем пустые значения
                    if (($value === null || $value === 'null' || $value === '' || $value === 0) && $currentData[$key] !== $value) {
                        unset($newData[$key]);
                        continue;
                    }

                    // Если значения различаются, добавляем в изменения для логирования
                    if (!($this->normalizeValue($currentData[$key]) == $this->normalizeValue($value))) {
                        $changes[$key] = [
                            'old' => $currentData[$key],
                            'new' => $value,
                        ];

                        if ($key === 'oks_status_id'){
                            OksStatusLibrary::updateOksStatusChanges(
                                $currentRecord,
                                $newData['oks_status_id'],
                                $userID,
                                $this->initiatorUserId,
                                $this->initiatorUserName,
                            );
                        }
                    }
                }
                unset($value);
            }

            if (empty($changes)) {
                Log::info("Нет изменений для записи с UIN $uniqueUIN.");
                return $currentRecord->id; // Возвращаем текущий ID
            }

            // Логируем изменения
            foreach ($changes as $key => $change) {
                if (!($this->normalizeValue($change['old']) == $this->normalizeValue($change['new']))) {
                    Log::info("Было: $key = {$change['old']} => Стало: $key = {$change['new']}");
                    $this->info("Было: $key = {$change['old']} => Стало: $key = {$change['new']}");
                }
            }

            // Обновляем запись
            $currentRecord->fill($newData);
            if ($currentRecord->isDirty()) {
                $beforeChanges = $currentRecord->getOriginal();
                $edited = $currentRecord->fill($newData)->save();

                if ($edited) {
                    MongoChangeLogModel::logEdit(
                        record: $currentRecord,
                        beforeChanges: $beforeChanges,
                        entity_type: 'Строительный объект',
                        initiator_user_id: null,
                        initiator_user_name: null,
                        json_user_id: null,
                    );
                }
            }

            Log::info("Запись с UIN $uniqueUIN успешно обновлена.");
            $this->info("Запись с UIN $uniqueUIN успешно обновлена.");

            return $currentRecord->id; // Возвращаем ID обновленной записи
        } catch (Exception $e) {
            Log::error("Ошибка при обновлении записи с UIN $uniqueUIN: " . $e->getMessage(),$e->getTrace());
            $this->error("Ошибка при обновлении записи с UIN $uniqueUIN: " . $e->getMessage());
            return null; // В случае ошибки возвращаем null
        }
    }

    private function parseBigJsonData($data,$oivId,$methodName):bool
    {
        foreach ($data as $record){
            try {
                    $this->info("Processing record ID: {$record->{'УИН'}}");
                    if($this->checkUin($record->{'УИН'}) === false){
                        $this->error("Ошибка в ID: {$record->{'УИН'}}. Данные от ОИВ_ID:".$oivId. ' Процесс: '.$methodName);
                        Log::error("Ошибка в ID: {$record->{'УИН'}}. Данные от ОИВ_ID:".$oivId. ' Процесс: '.$methodName);
                        continue;
                    }

                    $objectDataArr = JsonHelper::getObjectData($record,$oivId);
                    $this->userId = User::saveAndGetUserIDbyANOusername($record->{'user АНО СТРОИНФОРМ'});
                    $objectID = $this->updateOrCreateObjectRecord($objectDataArr, $this->userId);

                    $this->prepeareConstractionStageData($record);

                } catch (Throwable $e) {
                    $this->logException($e, $record->{'УИН'});
                    $this->error("Ошибка при обработке записи: " . $e->getMessage());
                    // Продолжаем работу со следующей записью
                    continue;
                }
            }
        return true;
    }


    private function parseBigJsonDataWithIgnoreObjectData($data, $oivId = null, $methodName):bool
    {
        foreach ($data as $record){
            try {
                $this->info("Processing record ID: {$record->{'УИН'}}");
                if($this->checkUin($record->{'УИН'}) === false){
                    $this->error("Ошибка в ID: {$record->{'УИН'}}. Данные от ОИВ_ID:".$oivId. ' Процесс: '.$methodName);
                    Log::error("Ошибка в ID: {$record->{'УИН'}}. Данные от ОИВ_ID:".$oivId. ' Процесс: '.$methodName);
                    continue;
                }

                $this->prepeareConstractionStageData($record);
            } catch (Throwable $e) {
                $this->logException($e, $record->{'УИН'});
                $this->error("Ошибка при обработке записи: " . $e->getMessage());
                // Продолжаем работу со следующей записью
                continue;
            }
        }
        return true;
    }

    private function parseBigJsonDataWithSmallObjectDataFromOIVFact($data,$oivId,$methodName):bool
    {
        foreach ($data as $record){
            try {
                $this->info("Processing record ID: {$record->{'УИН'}}");
                if($this->checkUin($record->{'УИН'}) === false){
                    $this->error("Ошибка в ID: {$record->{'УИН'}}. Данные от ОИВ_ID:".$oivId. ' Процесс: '.$methodName);
                    Log::error("Ошибка в ID: {$record->{'УИН'}}. Данные от ОИВ_ID:".$oivId. ' Процесс: '.$methodName);
                    continue;
                }

                $objectDataArr = JsonHelper::getObjectDataFromOivFact($record,$oivId);
                $this->userId = User::saveAndGetUserIDbyANOusername($record->{'user АНО СТРОИНФОРМ'});
                $this->updateOrCreateObjectRecord($objectDataArr, $this->userId);

                $this->prepeareConstractionStageData($record);

            } catch (Throwable $e) {
                $this->logException($e, $record->{'УИН'});
                $this->error("Ошибка при обработке записи: " . $e->getMessage());
                // Продолжаем работу со следующей записью
                continue;
            }
        }
        return true;
    }


//  СТРЭТАП
    private function prepeareConstractionStageData($record)
    {
        $this->userId = User::saveAndGetUserIDbyANOusername($record->{'user АНО СТРОИНФОРМ'});
        $updateDate = JsonHelper::validateAndAdaptDate($record->{'дата редактирования строки'});
        $objectId = ObjectModel::getIdByUIN($record->{'УИН'});

        if(!$objectId){
            $error = new \Exception("prepeareConstractionStageData: Не найден объект с УИН => " . $record->{'УИН'});
            $this->logException($error, $record->{'УИН'});
        }

        // Если $record — это объект, преобразуем его в JSON-строку
        if (is_object($record)) {
            $record = json_encode($record);
        }
        $recordArr = json_decode($record, true);
        // Проверка наличия триггерных свойств
        $constructionStageData = [];
        foreach ($recordArr as $key => $value) {
            if (str_contains($key, 'СТРЭТАП'))
            {
                $constructionStageID = ConstructionStagesLibrary::saveAndGetConstructionStageID($key);
                // Валидация по имени свойства
                $constructionStageAttrName = ObjectConstructionStage::getAttributeByName($key);

                if(in_array($constructionStageAttrName,['smg_fact_start','smg_fact_finish','oiv_plan_start','oiv_plan_finish'])){
                    $value = JsonHelper::validateAndAdaptDate($value);
                }elseif(in_array($constructionStageAttrName,['smg_plan', 'smg_fact', 'oiv_plan', 'oiv_fact'])){
                    $value = JsonHelper::percentValidate($value);
                }else{
                    continue;
                }

                $constructionStageData[$constructionStageID][$constructionStageAttrName] = $value;
            }
        }

        // Логика обработки данных строительных этапов
        if (!empty($constructionStageData)) {
            ObjectConstructionStage::saveConstructionStageData(
                $constructionStageData,
                $this->userId,
                $updateDate,
                $objectId,
                $this->initiatorUserId ?? null,
                $this->initiatorUserName ?? null,
            );
        } else {
            $this->info("Нет данных строительных этапов для обработки.");
        }
    }

    private function checkUin($uin){
        if ($uin === '0' || $uin === 0 || $uin === null || str_contains($uin, ' ') || str_contains($uin, ',')){
            $error = new \Exception("Не валидный УИН, пропускаем запись...  => " . $uin);
            $this->logException($error, $uin);
            $this->error("Не валидный УИН, пропускаем запись...  => ". $uin);
            return false;
        }

        // Регулярное выражение для проверки формата УИН
        $pattern = '/^[A-Za-z]{2}\d{4}-\d{2}-\d{4}-\d{3}$/';
        if (!preg_match($pattern, $uin)) {
            $error = new \Exception("Не валидный формат УИН, пропускаем запись...  => " . $uin);
            $this->logException($error, $uin);
            $this->error("Не валидный формат УИН, пропускаем запись...  => " . $uin);
            return false;
        }

        return true;
    }
    /**
     * Логирует исключение в детальном формате в ParserError.log и краткий формат в ParserErrorShort.log
     *
     * @param Throwable $e Исключение для логирования
     * @param string|null $context Дополнительный контекст (например, УИН записи)
     * @return void
     */
    private function logException(Throwable $e, ?string $context = null)
    {
        $this->errorCount++;

        $message = [];

        // Добавляем информацию о текущем обрабатываемом файле
        if ($this->currentProcessingFile) {
            $message[] = "Current processing file: {$this->currentProcessingFile['name']}";
            $message[] = "File path: {$this->currentProcessingFile['path']}";
            $message[] = "";
        }

        if ($context) {
            $message[] = "Processing record ID: $context";
            $message[] = "";
        }

        $message[] = get_class($e);
        $message[] = "";
        $message[] = "  " . $e->getMessage();
        $message[] = "";

        // Получаем путь к файлу относительно корня проекта
        $filePath = $e->getFile();
        $relativePath = str_replace(base_path() . '/', '', $filePath);

        $message[] = "  at $relativePath:{$e->getLine()}";

        // Добавляем 5 строк кода вокруг линии с ошибкой
        $file = file($filePath);
        $lineStart = max($e->getLine() - 2, 0);
        $lineEnd = min($e->getLine() + 2, count($file));

        for ($i = $lineStart; $i < $lineEnd; $i++) {
            $lineNumber = $i + 1;
            $prefix = ($lineNumber == $e->getLine()) ? "➜  " : "   ";
            $numPrefix = str_pad($lineNumber, 3, " ", STR_PAD_LEFT) . "▕ ";
            $message[] = "  $prefix$numPrefix" . rtrim($file[$i]);
        }

        $message[] = "";

        // Добавляем стек вызовов (максимум 3 уровня)
        $trace = $e->getTrace();
        $traceCount = min(count($trace), 3);

        for ($i = 0; $i < $traceCount; $i++) {
            $t = $trace[$i];
            $file = str_replace(base_path() . '/', '', $t['file'] ?? 'unknown');
            $line = $t['line'] ?? 0;
            $function = isset($t['class']) ? $t['class'] . $t['type'] . $t['function'] : $t['function'];
            $args = isset($t['args']) ? $this->formatArgs($t['args']) : '';

            $message[] = "  " . ($i + 1) . "   $file:$line";
            $message[] = "      $function($args)";
            $message[] = "";
        }

        $message[] = "--------------";
        $message[] = "";

        // Записываем детальное сообщение в ParserError.log
        Log::channel('ParserJson_error')->error($message);

        // Формируем краткое сообщение для ParserErrorShort.log
        $shortMessage = [
            "File: " . ($this->currentProcessingFile['name'] ?? 'unknown'),
            "Line: " . $e->getLine(),
            "UIN: " . ($context ?? 'N/A'),
            "Error: " . $e->getMessage(),
            "--------------",
            "",
        ];

        // Записываем краткое сообщение в ParserErrorShort.log
        Log::channel('ParserJson_error_short')->error($shortMessage);

        // Также записываем в обычный лог с указанием на специальный файл
        Log::error("Ошибка парсинга в файле " . ($this->currentProcessingFile['name'] ?? 'unknown') . " : " . $e->getMessage() . ". Подробности в tmp/ParserJsonError.log");
    }

    /**
     * Форматирует аргументы для отображения в логе
     *
     * @param array $args Аргументы функции
     * @return string Отформатированная строка аргументов
     */
    private function formatArgs(array $args): string
    {
        $result = [];
        foreach ($args as $arg) {
            if (is_scalar($arg)) {
                $result[] = var_export($arg, true);
            } elseif (is_array($arg)) {
                $result[] = 'Array';
            } elseif (is_null($arg)) {
                $result[] = 'null';
            } elseif (is_object($arg)) {
                $result[] = 'Object(' . get_class($arg) . ')';
            } else {
                $result[] = gettype($arg);
            }
        }
        return implode(", ", $result);
    }

    private function normalizeValue($value)
    {
        // Обработка числовых значений
        if (is_numeric($value)) {
            // return floatval($value);
            return (float)$value;
        }

        // Обработка булевых значений
        if (is_bool($value)) {
            return (bool)$value;
        }

        // Обработка дат
        $date = "";
        if (strtotime($value)) {
            if ($date = JsonHelper::validateAndAdaptDate($value)) {
                return $date;
            }
        }

        // Обработка пустых значений
        if ($value === '' || $value === 'null') {
            return null;
        }

        // Для строк: тримминг и нормализация
        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }
}
