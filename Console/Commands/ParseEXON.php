<?php

namespace App\Console\Commands;

use App\Helpers\JsonHelper;
use App\Models\Library\ConstructionStagesLibrary;
use App\Models\Library\ControlPointsLibrary;
use App\Models\Library\EtapiRealizaciiLibrary;
use App\Models\Library\OksStatusLibrary;
use App\Models\Library\ProjectManagerLibrary;
use App\Models\MonitorPeople;
use App\Models\ObjectConstructionStage;
use App\Models\ObjectControlPoint;
use App\Models\ObjectModel;
use App\Models\User;
use App\Services\TelegramService;
use DateTime;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Класс ParseJSON обрабатывает EXON файлы из определенных директорий
 * и применяет соответствующие методы обработки в зависимости от расположения файла
 */
class ParseEXON extends Command
{
    /**
     * Сигнатура команды с добавленным параметром для папки
     *
     * @var string
     */
    protected $signature = 'app:parse-exon
                            {--folder=07 : Название папки внутри storage/app/exon_samples}
                            {--file= : Полный путь к конкретному файлу для обработки}
                            {--username= : Имя пользователя }';

    /**
     * Описание команды
     *
     * @var string
     */
    protected $description = 'Парсинг EXON файлов с данными объектов';

    private $errorCount = 0;
    private $currentProcessingFile = null; // Текущий обрабатываемый файл
    private $initiatorUserName = null;
    private $initiatorUserId = null;

    /**
     * Основной метод выполнения команды.
     *
     * @return int Возвращает 0 при успешном выполнении, 1 при ошибке.
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

                // Сохраняем относительный путь от storage/app/exon_samples
                $relativePath = str_replace(storage_path('app/exon_samples/'), '', $filePath);

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

            // Получаем название папки из параметра команды
            $folderName = $this->option('folder');

            $this->scanJsonFiles($folderName);

            if (empty($this->jsonFiles)) {
                $this->error('EXON файлы не найдены в директории storage/app/exon_samples');
                return 1;
            }

            $selectedFile = $this->selectFile();
            if ($selectedFile) {
                $this->processJsonFile($selectedFile);
            }

            // Вывод информации об ошибках в конце работы
            if ($this->errorCount > 0) {
                $this->warn("Обработка завершена с {$this->errorCount} ошибками. Подробности в лог файле");
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
     * Сканирует EXON файлы в указанной директории.
     *
     * @param string $folderName Название папки для сканирования.
     * @return void
     */
    private function scanJsonFiles($folderName)
    {
        $basePath = storage_path('app/exon_samples');

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
     * Выбирает файл для обработки из списка доступных.
     *
     * @return array|null Возвращает информацию о выбранном файле или null, если выбран автоматический режим.
     */
    private function selectFile()
    {
        $choices = collect($this->jsonFiles)->pluck('name')->toArray();
        $choices[] = '[АВТО] Обработать все файлы автоматически';

        $selectedFileName = $this->choice(
            'Выберите EXON файл для обработки:',
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
     * Обрабатывает JSON файл, определяя нужный обработчик на основе его расположения.
     *
     * @param array $fileInfo Массив с информацией о файле (путь, имя).
     * @return void
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

    }

    /**
     * Определяет обработчик для файла на основе его пути.
     *
     * @param array $fileInfo Массив с информацией о файле (путь, имя).
     * @return string|null Возвращает название метода-обработчика или null, если обработчик не найден.
     */
    private function determineProcessor(array $fileInfo)
    {
        $path = mb_strtolower($fileInfo['name']);
        $prefix = 'processEXON';
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
        if (str_contains($path, '3') && str_contains($path, 'ресурсы')) {
            return $prefix . 'ResourcesPlanFact';
        }
        if (str_contains($path, '4') && str_contains($path, 'оив план')) {
            return $prefix . 'PlanOIV';
        }
        if (str_contains($path, '5') && str_contains($path, 'оив факт')) {
            return $prefix . 'Fact';
        }
        if (str_contains($path, '6') && str_contains($path, 'оив кт')) {
            return $prefix . 'ControlPoints';
        }

        return null;
    }

    /**
     * Обработчик для ежедневных данных.
     *
     * @return void
     */
    private function processEXONDaily()
    {
                echo 'ПРОЦЕСС: processEXONDaily';
    }

    /**
     * Обработчик для данных о задержках и действиях.
     *
     * @return void
     */
    private function processEXONDelaysAndActions()
    {
        echo 'ПРОЦЕСС: processEXONDelaysAndActions';
    }

    /**
     * Обработчик для данных о культуре производства.
     *
     * @param array $fileInfo Массив с информацией о файле (путь, имя).
     * @return void
     */
    private function processEXONCultureManufacture(array $fileInfo)
    {

        echo 'ПРОЦЕСС: processEXONCultureManufacture';

        $data = $this->getJsonFromFile($fileInfo['path']);
        $this->info('Обработка данных культуры ДСТИИ...');
        // Здесь будет логика обработки данных
        foreach ($data as $record) {
            if($this->checkMasterCodeDC($record->{'Мастер код ДС'}) === false){
                continue;
            }
            JsonHelper::processCultureManufactureData($record);
        }
    }

    /**
     * Обработчик для данных плана ОИВ.
     *
     * @param array $fileInfo Массив с информацией о файле (путь, имя).
     * @return void
     */
    private function processEXONPlanOIV(array $fileInfo)
    {
        $data = $this->getJsonFromFile($fileInfo['path']);
        $this->info('Обработка processEXONPlanOIV...');
        // Здесь будет логика обработки данных
        foreach ($data as $record){
            try {
                $this->info("Processing record ID: {$record->{'Мастер код ДС'}}");
                if($this->checkMasterCodeDC($record->{'Мастер код ДС'}) === false){
                    $this->error("Ошибка в ID: {$record->{'Мастер код ДС'}}. Процесс: processEXONPlanOIV");
                    Log::error("Ошибка в ID: {$record->{'Мастер код ДС'}}. Процесс: processEXONPlanOIV");
                    continue;
                }

                $this->prepeareConstractionStageData($record);

            } catch (Throwable $e) {
                $this->logException($e, $record->{'Мастер код ДС'});
                $this->error("Ошибка при обработке записи: " . $e->getMessage());
                // Продолжаем работу со следующей записью
                continue;
            }
        }
    }

    /**
     * Обработчик для фактических данных ОИВ.
     *
     * @param array $fileInfo Массив с информацией о файле (путь, имя).
     * @return void
     */
    private function processEXONFact($fileInfo)
    {
        $data = $this->getJsonFromFile($fileInfo['path']);
        $this->info('Обработка processEXONFact...');
        // Здесь будет логика обработки данных
        foreach ($data as $record){
            try {
                $this->info("Processing record ID: {$record->{'Мастер код ДС'}}");
                if($this->checkMasterCodeDC($record->{'Мастер код ДС'}) === false){
                    $this->error("Ошибка в ID: {$record->{'Мастер код ДС'}}. Процесс: processEXONPlanOIV");
                    Log::error("Ошибка в ID: {$record->{'Мастер код ДС'}}. Процесс: processEXONPlanOIV");
                    continue;
                }

                $this->prepeareConstractionStageData($record);

            } catch (Throwable $e) {
                $this->logException($e, $record->{'Мастер код ДС'});
                $this->error("Ошибка при обработке записи: " . $e->getMessage());
                // Продолжаем работу со следующей записью
                continue;
            }
        }
    }

    private function processEXONControlPoints($fileInfo)
    {
        $data = $this->getJsonFromFile($fileInfo['path']);
        $this->info('Обработка processEXONControlPoints...');
        // Здесь будет логика обработки данных
        foreach ($data as $record){
            try {
                $this->info("Processing record ID: {$record->{'Мастер код ДС'}}");
                if($this->checkMasterCodeDC($record->{'Мастер код ДС'}) === false){
                    $this->error("Ошибка в ID: {$record->{'Мастер код ДС'}}. Процесс: processEXONPlanOIV");
                    Log::error("Ошибка в ID: {$record->{'Мастер код ДС'}}. Процесс: processEXONPlanOIV");
                    continue;
                }

                $this->processControlPointRecord($record);

            } catch (Throwable $e) {
                $this->logException($e, $record->{'Мастер код ДС'});
                $this->error("Ошибка при обработке записи: " . $e->getMessage());
                // Продолжаем работу со следующей записью
                continue;
            }
        }

        $telegramService = new TelegramService();
        $telegramService->sendMessage('Запуск формирования Этапов Реализации из Контрольных точек');
        Artisan::call('sync:control-points-to-etapi');

    }

    /**
     * Обновляет запись объекта, если она существует.
     *
     * @param array $newRecord Новые данные для записи.
     * @return int|null Возвращает ID обновленной записи или null при ошибке.
     */
    private function updateObjectRecord(array $newRecord, $userID = null): ?int
    {
        $uniqueCOD_DC = $newRecord['master_kod_dc'];

        try {
            // Поиск записи по уникальному master_kod_dc
            $currentRecord = ObjectModel::where('master_kod_dc', $uniqueCOD_DC)->first();

            if (!$currentRecord) {
                dd('нет объекта с таким КОД ДС: '.$uniqueCOD_DC);
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
                Log::warning("Дата записи более ранняя, или равна той, что в нашей БД - игнорируем обновление для master_kod_dc  $uniqueCOD_DC.");
                $this->warn("Дата записи более ранняя, или равна той, что в нашей БД - игнорируем обновление для master_kod_dc  $uniqueCOD_DC.");

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
                    if ($currentData[$key] != $value) {
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
                Log::info("Нет изменений для записи с master_kod_dc  $uniqueCOD_DC.");
                return $currentRecord->id; // Возвращаем текущий ID
            }

            // Логируем изменения
            foreach ($changes as $key => $change) {
                Log::info("Было: $key = {$change['old']} => Стало: $key = {$change['new']}");
                $this->info("Было: $key = {$change['old']} => Стало: $key = {$change['new']}");
            }

            // Обновляем запись
            $currentRecord->update($newData);

            Log::info("Запись с master_kod_dc  $uniqueCOD_DC успешно обновлена.");
            $this->info("Запись с master_kod_dc  $uniqueCOD_DC успешно обновлена.");

            return $currentRecord->id; // Возвращаем ID обновленной записи
        } catch (Exception $e) {
            Log::error("Ошибка при обновлении записи с master_kod_dc  $uniqueCOD_DC: " . $e->getMessage());
            $this->error("Ошибка при обновлении записи с master_kod_dc  $uniqueCOD_DC: " . $e->getMessage());
            return null; // В случае ошибки возвращаем null
        }
    }

    private function parseBigJsonDataWithIgnoreObjectData($data,$oivId,$methodName):bool
    {
        foreach ($data as $record){
            try {
                $this->info("Processing record ID: {$record->{'Мастер код ДС'}}");
                if($this->checkMasterCodeDC($record->{'Мастер код ДС'}) === false){
                    $this->error("Ошибка в ID: {$record->{'Мастер код ДС'}}. Данные от ОИВ_ID:".$oivId. ' Процесс: '.$methodName);
                    Log::error("Ошибка в ID: {$record->{'Мастер код ДС'}}. Данные от ОИВ_ID:".$oivId. ' Процесс: '.$methodName);
                    continue;
                }

                $this->prepeareConstractionStageData($record);
                $this->processControlPointRecord($record);

            } catch (Throwable $e) {
                $this->logException($e, $record->{'Мастер код ДС'});
                $this->error("Ошибка при обработке записи: " . $e->getMessage());
                // Продолжаем работу со следующей записью
                continue;
            }
        }
        return true;
    }

    /**
     * Парсит большие JSON данные, игнорируя данные объектов.
     *
     * @param mixed $data Данные для обработки.
     * @param int $oivId ID ОИВ.
     * @param string $methodName Название метода обработки.
     * @return bool Возвращает true при успешной обработке.
     */
    private function processControlPointRecord($record) {
        $userId = User::where('name','EXON_PARCER')->pluck('id')->firstOrFail();
        $updateDate = JsonHelper::validateAndAdaptDate($record->{'дата редактирования строки'});
        $objectId = ObjectModel::getIdByMasterCodeDC($record->{'Мастер код ДС'});

        // Если $record — это объект, преобразуем его в EXON-строку
        if (is_object($record)) {
            $record = json_encode($record);
        }
        // Декодируем EXON-строку в массив
        $recordArr = json_decode($record, true);

        foreach ($recordArr as $key => $value){
            if (str_contains($key, 'ОБЩКОНТРТОЧКА')){
                $ParentControlPointLibraryId = ControlPointsLibrary::saveAndGetIDbyName($key);

                if ($ParentControlPointLibraryId === null) {
                    $this->logControlPointNull($key, $value, $recordArr['Мастер код ДС']);
                    continue;
                }

                foreach ($value as $key2 => $value2){
                    if (str_contains($key2, 'КОНТРТОЧКА') && is_array($value) === true){
                        // Получаем чистое название дочерней КТ
                        $cleanChildName = trim(str_replace('КОНТРТОЧКА ', '', $key2));
                        $cleanChildName = mb_strtoupper($cleanChildName);

                        $childControlPointLibraryId = ControlPointsLibrary::saveAndGetIDbyName($key2, $ParentControlPointLibraryId);

                        if ($childControlPointLibraryId === null) {
                            $this->logControlPointNull($key2, $value2, $recordArr['Мастер код ДС']);
                            continue;
                        }

                        ObjectControlPoint::saveObjectControlPointFromEXONParcer(
                            $objectId,
                            $childControlPointLibraryId,
                            $value2,
                            $userId,
                            $updateDate
                        );
                    }
                }
            }

            if (str_contains($key, 'КОНТРТОЧКА') && !str_contains($key, 'ОБЩКОНТРТОЧКА') && is_array($value)){
                // Получаем чистое название самостоятельной КТ
                $cleanSelfName = trim(str_replace('КОНТРТОЧКА ', '', $key));
                $cleanSelfName = mb_strtoupper($cleanSelfName);

                $selfControlPointLibraryId = ControlPointsLibrary::saveAndGetIDbyName($key);

                if ($selfControlPointLibraryId === null) {
                    $this->logControlPointNull($key, $value, $recordArr['Мастер код ДС']);
                    continue;
                }

            ObjectControlPoint::saveObjectControlPointFromEXONParcer(
                $objectId,
                $selfControlPointLibraryId,
                $value,
                $userId,
                $updateDate
            );
        }

            if (str_contains($key, 'Руководитель проекта')) {
                $projectManagerId = null;

                if ($value !== null) {
                    $normalized = trim(preg_replace('/\s+/', ' ', (string) $value));

                    if (
                        $normalized !== '' &&
                        $normalized !== 'Не указан' &&
                        !is_numeric($value)
                    ) {
                        $projectManagerId = ProjectManagerLibrary::saveAndGetIdByName($normalized);
                    }
                }

                $object = ObjectModel::find($objectId);
                $object->project_manager_id = $projectManagerId;
                $object->save();
            }

            // Обработка ссылки на КСГ СУИД
            if (str_contains($key, 'Ссылка на КСГ СУИД')) {
                try {
                    $suidKsgUrl = trim($value);

                    if (!empty($suidKsgUrl)) {
                        if ($this->isValidSuidKsgUrl($suidKsgUrl)) {
                            $object = ObjectModel::find($objectId);
                            if (!$object) {
                                throw new Exception("Объект с ID {$objectId} не найден");
                            }

                            $object->suid_ksg_url = $suidKsgUrl;
                            $object->save();

                            $this->info("Ссылка на КСГ СУИД сохранена для объекта {$objectId}: {$suidKsgUrl}");
                        } else {
                            throw new Exception("Невалидный URL: {$suidKsgUrl}");
                        }
                    }
                } catch (Throwable $e) {
                    $this->logException($e, $recordArr['Мастер код ДС'] ?? 'unknown');
                    $this->warn("Ошибка при обработке ссылки на КСГ СУИД для объекта {$objectId}: " . $e->getMessage());

                    // Логируем невалидные URL
                    Log::warning("Ошибка обработки ссылки на КСГ СУИД", [
                        'object_id' => $objectId,
                        'master_kod_dc' => $recordArr['Мастер код ДС'] ?? 'unknown',
                        'url_value' => $value,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return true;
    }

    /**
     * Логирует информацию о неизвестной контрольной точке.
     *
     * @param string $pointName Название точки.
     * @param mixed $pointValue Значение точки.
     * @param string $masterCodeDC Мастер код ДС.
     * @return void
     */
    private function logControlPointNull(string $pointName, $pointValue, string $masterCodeDC): void
    {
        $logMessage = [
            "Мастер код ДС: $masterCodeDC",
            "Название контрольной точки: $pointName",
            "Значение: " . json_encode($pointValue, JSON_UNESCAPED_UNICODE),
            "--------------",
            ""
        ];

        Log::channel('ParserExon_control_point')->warning($logMessage);

        // Также логируем в общий лог для информации
        Log::channel('parser')->warning("Неизвестная контрольная точка", [
            'Мастер код ДС' => $masterCodeDC,
            'Название точки' => $pointName,
            'Значение' => $pointValue
        ]);
    }

    /**
     * Подготавливает данные строительных этапов.
     *
     * @param mixed $record Данные записи.
     * @return void
     */
    private function prepeareConstractionStageData($record)
    {
        $userId = User::where('name','EXON_PARCER')->pluck('id')->firstOrFail();
        $updateDate = JsonHelper::validateAndAdaptDate($record->{'дата редактирования строки'});
        $objectId = ObjectModel::getIdByMasterCodeDC($record->{'Мастер код ДС'});

        // Если $record — это объект, преобразуем его в EXON-строку
        if (is_object($record)) {
            $record = json_encode($record);
        }
        $recordArr = json_decode($record, true);
        // Проверка наличия триггерных свойств
        $constructionStageData = [];
        foreach ($recordArr as $key => $value) {
            if (str_contains($key, 'СТРЭТАП'))
            {
// сценарий, когда мы попали на запись вида => "СТРЭТАП Благоустройство:: Озеленение (план)": 100,
                if (str_contains($key, ':: ')){
                    $partsOfName = explode(':: ',$key);
                    $parentConstructionStageID = ConstructionStagesLibrary::saveAndGetConstructionStageID($partsOfName[0]);
                    $constructionStageID = ConstructionStagesLibrary::saveAndGetConstructionStageID($partsOfName[1],$parentConstructionStageID);
                    $constructionStageAttrName = ObjectConstructionStage::getAttributeByName($key);
                }else{
                    $constructionStageID = ConstructionStagesLibrary::saveAndGetConstructionStageID($key);
                    // Валидация по имени свойства
                    $constructionStageAttrName = ObjectConstructionStage::getAttributeByName($key);
                }


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
            ObjectConstructionStage::saveConstructionStageData($constructionStageData,$userId,$updateDate,$objectId);
        } else {
            $this->info("Нет данных строительных этапов для обработки.");
        }
    }

    /**
     * Обработка данных ресурсов план факта из ЭКЗОНА
     * Обрабатывает файл: "3 - ОИВ ресурсы план факт.json"
     * @param array $fileInfo Информация о файле
     */
    private function processEXONResourcesPlanFact(array $fileInfo)
    {
        $data = $this->getJsonFromFile($fileInfo['path']);
        $this->info('Обработка данных ресурсов план факта из ЭКЗОНА...');
//        dd($data);

        foreach ($data as $record) {
            try {
                if ($this->checkMasterCodeDC($record->{'Код ДС'}) === false) {
                    continue;
                }

                // Преобразуем объект в массив
                $recordArray = (array) $record;

                // Вызываем метод записи и проверяем результат
                $result = MonitorPeople::addingMonitoringDataFromExonParser($recordArray);

                if (!$result['success']) {
                    // Создаем исключение из ошибки
                    $exception = new \Exception($result['error']);
                    $this->logException($exception, $record->{'Код ДС'});
                    $this->error("Ошибка обработки записи: " . $result['error']);
                } else {
                    $this->info("Успешно обработана запись для Код ДС: {$result['master_kod_dc']}, действие: {$result['action']}");
                }

            } catch (Throwable $e) {
                $this->logException($e, $record->{'Код ДС'} ?? 'unknown');
                $this->error("Ошибка при обработке записи ресурсов: " . $e->getMessage());
                continue;
            }
        }
    }

    /**
     * Проверяет валидность Мастер кода ДС.
     *
     * @param mixed $master_kod_dc Мастер код ДС для проверки.
     * @return bool Возвращает true, если код валиден, иначе false.
     */
    private function checkMasterCodeDC($master_kod_dc){
        if ($master_kod_dc === '0' || $master_kod_dc === 0 || $master_kod_dc === null || str_contains($master_kod_dc, ' ') || str_contains($master_kod_dc, ',')){
            $error = new \Exception("Не валидный Мастер код ДС, пропускаем запись...  => " . $master_kod_dc);
            $this->logException($error, $master_kod_dc);
            $this->error("Не валидный Мастер код ДС, пропускаем запись...  => ". $master_kod_dc);
            return false;
        }

        return true;
    }
    /**
     * Логирует исключение в детальном формате в ParserError.log и краткий формат в ParserErrorShort.log
     *
     * @param Throwable $e Исключение для логирования
     * @param string|null $context Дополнительный контекст (например, Мастер код ДС записи)
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
        Log::channel('ParserExon_error')->error(implode("\n", $message));

        // Формируем краткое сообщение для ParserErrorShort.log
        $shortMessage = [
            "File: " . ($this->currentProcessingFile['name'] ?? 'unknown'),
            "Line: " . $e->getLine(),
            "master_kod_dc: " . ($context ?? 'N/A'),
            "Error: " . $e->getMessage(),
            "--------------",
            "",
        ];

        // Записываем краткое сообщение в ParserErrorShort.log
        Log::channel('ParserExon_error_short')->error(implode("\n", $shortMessage));

        // Также записываем в обычный лог с указанием на специальный файл
        Log::error("Ошибка парсинга в файле " . ($this->currentProcessingFile['name'] ?? 'unknown') . " : " . $e->getMessage() . ". Подробности в tmp/ParserExonError.log");
    }

    /**
     * Форматирует аргументы для отображения в логе.
     *
     * @param array $args Аргументы функции.
     * @return string Отформатированная строка аргументов.
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

    function getJsonFromFile($path) {
        $content = File::get($path);

        // Проверяем наличие \r\n - если есть, это неправильная кодировка
//        if (strpos($content, "\r\n") !== false) {
//            throw new Exception('Неправильная кодировка файла. Файл должен использовать LF (\n), а не CRLF (\r\n)');
//        }
//
//        // Проверяем наличие одиночных \r - тоже недопустимо
//        if (strpos($content, "\r") !== false && strpos($content, "\r\n") === false) {
//            throw new Exception('Неправильная кодировка файла. Обнаружены символы CR (\r)');
//        }

//        dd($content);

        $data = json_decode($content, false);


//        dd($data);

//        if (json_last_error() !== JSON_ERROR_NONE) {
//            throw new Exception('Ошибка декодирования JSON: ' . json_last_error_msg());
//        }
//
//        if ($data === null) {
//            throw new Exception('JSON декодирован в NULL - возможно, пустой или невалидный файл');
//        }

        return $data;
    }


    /**
     * Проверяет валидность URL для ссылки на КСГ СУИД с использованием filter_var
     *
     * @param string $url URL для проверки
     * @return bool Возвращает true, если URL валиден, иначе false
     */
    private function isValidSuidKsgUrl(string $url): bool
    {
        $url = trim($url);

        if (empty($url)) {
            return false;
        }

        // Проверяем наличие http/https протокола
        if (!preg_match('/^(http|https):\/\//i', $url)) {
            return false;
        }

        // Используем встроенную функцию PHP для валидации URL
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // Дополнительная проверка на минимальную длину
        if (strlen($url) < 11) {
            return false;
        }

        return true;
    }

}
