<?php

namespace App\Services\ExcelServices;

use App\Helpers\JsonHelper;
use App\Models\ExcelUploadFileStatus;
use App\Models\Library\ConstructionStagesLibrary;
use App\Models\MonitorTechnica;
use App\Models\ObjectConstructionStage;
use App\Models\ObjectModel;
use App\Models\User;
use App\Services\ObjectConstructionStagesViewService;
use App\Services\FileFolderService;
use App\Services\ObjectEtapiRealizaciiViewService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExcelDataService
{
    protected static array $etaps = [
        'ГПЗУ',
        'ТУ от РСО',
        'АГР',
        'Экспертиза ПСД',
        'РНС',
        'СМР',
        'Техприс',
        'ЗОС',
        'РВ',
    ];

    /**
     * Форматирует флаг AIP
     * @param bool|null $aipFlag
     * @return string
     */
    public static function formatAipFlag($aipFlag): string
    {
        if ($aipFlag === true) {
            return 'Да';
        } elseif ($aipFlag === false) {
            return 'Нет';
        } else {
            return ''; // null или другое значение
        }
    }

    /**
     * Форматирует булевый флаг
     * @param bool|null $flag
     * @return string
     */
    public static function formatBooleanFlag($flag): string
    {
        if ($flag === true) {
            return 'Да';
        } elseif ($flag === false) {
            return 'Нет';
        } else {
            return '';
        }
    }

    /**
     * Генерирует ключ свойства для строительного этапа
     * @param array $stage
     * @param string $type (start|end)
     * @return string
     */
    public static function generateStagePropertyKey(array $stage, string $type): string
    {
        if (!empty($stage['parent']) && !empty($stage['parent']['name'])) {
            $key = $stage['parent']['name'] . ' / ' . $stage['name'];
        } else {
            $key = $stage['name'];
        }

        // Преобразуем в допустимое имя свойства
        $key = preg_replace('/[^a-zA-Z0-9а-яА-Я\s\/\-_]/u', '', $key);
        $key = str_replace([' ', '/', '-', '(', ')'], '_', $key);
        $key = preg_replace('/_+/', '_', $key);
        $key = strtolower(trim($key, '_'));

        return $key . '_' . $type;
    }

    /**
     * Форматирует дату в формат dd.mm.yyyy
     * @param string|null $dateString
     * @return string
     */
    public static function formatDate(?string $dateString): string
    {
        if (empty($dateString)) {
            return '';
        }

        try {
            // Пытаемся распарсить дату в различных форматах
            $carbon = Carbon::parse($dateString);
            return $carbon->format('d.m.Y'); // dd.mm.yyyy
        } catch (\Exception $e) {
            // Если не удалось распарсить, возвращаем исходную строку
            return $dateString;
        }
    }

    /**
     * Отправляет на обновление даты этапов реализации, полученных из файла импорта
     *
     * @param $mappedRow
     * @param ObjectModel $object
     * @return boolean
     */
    public static function setObjectDatesForEtapiRealizacii($mappedRow, ObjectModel $object)
    {
        $service = new ObjectEtapiRealizaciiViewService();

        foreach ($mappedRow as $index => $date) {
            if (in_array(self::$etaps, preg_replace('/\s*\(.*\)$/', '', $index))) {
                $result = 'факт';
                if (preg_match('/\(([^)]+)\)/', $index, $matches)) {
                    $result = $matches[1];
                }
                if($result === 'план'){
                    $service->setPlanDateByEtapName($object, preg_replace('/\s*\(.*\)$/', '', $index), $date);
                }elseif($result === 'факт'){
                    $service->setFactDateByEtapName($object, preg_replace('/\s*\(.*\)$/', '', $index), $date);
                }
            }
        }
        return true;
    }

    /**
     * Метод принимает массив строительных этапов объекта
     * и отправляет на сохранение новые даты
     * @param $constructionStages
     * @param ObjectModel $object
     * @return void
     */
    public static function setObjectConstructionStagesDates($constructionStages, ObjectModel $object): void
    {
        $service = new ObjectConstructionStagesViewService();

        foreach ($constructionStages as $index => $date) {

            if($date){
                $constructionStageName = self::getConstructionStageName($index);

                $result = 'плановая дата начала';

                if (preg_match('/\(([^)]+)\)/', $index, $matches)) {
                    $result = $matches[1];
                }

                if($result === 'плановая дата начала'){

                    $service->setObjectConstructionStagePlanStartDateByName($object, $constructionStageName, $date);

                }elseif($result === 'плановая дата завершения'){

                    $service->setObjectConstructionStagePlanFinishDateByName($object, $constructionStageName, $date);

                }
            }

        }
    }

    /**
     * Метод обрабатывает строку типа "ВНУТРЕННЯЯ ОТДЕЛКА / ЧЕРНОВАЯ ОТДЕЛКА"
     * и возвращает реальное имя строительного этапа без родителя
     * @param string $index
     * @return string
     */
    public static function getConstructionStageName(string $index): string
    {
        if (str_contains($index, '/')) {
            $result = trim(explode('/', $index)[1]);
        } else {
            $result = $index;
        }
        return $result;
    }

    /**
     * Преобразование дат в формат БД
     */
    public static function convertDateToDbFormat(string $date): string
    {
        try {
            $date = self::convertExcelDate($date);
            $date = \DateTime::createFromFormat('d.m.Y', $date);
            if ($date) {
                $date = $date->format('Y-m-d');
            }
        } catch (\Exception $e) {
            // Оставляем оригинальное значение если не удалось преобразовать
        }

        return $date;
    }

    /**
     * Выделяет строительные этапы
     * (поля строго с "плановая дата начала" или "плановая дата завершения")
     */
    public static function extractConstructionStages(array $data): array
    {
        $constructionStages = [];

        $monitoring = [
            'Количество техники (план)',
            'Количество техники (факт)',
            'Количество рабочих (план)',
            'Количество рабочих (факт)'
        ];

        foreach ($data as $fieldName => $value) {
            if (!in_array($fieldName, $monitoring) &&
                (str_contains($fieldName, '(плановая дата начала)') ||
                str_contains($fieldName, '(плановая дата завершения)') ||
                str_contains($fieldName, '(план)') ||
                str_contains($fieldName, '(факт)'))
                && !in_array(self::extractStageName($fieldName), self::$etaps)
            ) {
                $constructionStages[$fieldName] = $value;
            }
        }

        return $constructionStages;
    }

    protected static function extractStageName(string $fieldName): string
    {
        // Удаляем все что в скобках и сами скобки
        return preg_replace('/\s*\([^)]*\)/', '', $fieldName);
    }

    /**
     * Логирует ошибки в файл с форматом {'УИН1': ['ошибка1', 'ошибка2'], 'УИН2': [...]}
     * Каждая инициализация метода содержит массив ошибок по 1 УИН
     */
    public static function logErrorsToFile(array $errors, string $uin, $filePath, string $fileName, string $originFileName, string $sheetName, string $uploadUuid, $user): void
    {
        try {
            $disk = Storage::disk('excel_import_LOGS');
            $directory = explode('/',$filePath);
            FileFolderService::makeDirIfNotExist('excel_import_LOGS',$directory[0]);

            // Читаем существующие ошибки или создаем новый массив
            $allErrors = [];
            if ($disk->exists($filePath)) {
                $existingContent = $disk->get($filePath);
                $allErrors = json_decode($existingContent, true) ?? [];
            }

            // Добавляем новые ошибки
            $timestamp = now()->toISOString();
            $allErrors[$uin] = $errors;

            // Сохраняем обратно
            $jsonContent = json_encode($allErrors, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $disk->put($filePath, $jsonContent);

//            ExcelUploadFileStatus::updateOrCreate([
//                'uploaded_filename' => $originFileName,
//                'filename' => $fileName,
//            ],[
//                'status' => 'Обрабатывается',
//                'log_file_link' => $filePath,
//            ]);

            ExcelUploadFileStatus::updateOrCreate([
                'upload_uuid' => $uploadUuid,

            ],[
                'uploaded_filename' => $originFileName,
                'sheet_name' => $sheetName,
                'filename' => $fileName,
                'status' => 'Обрабатывается',
                'user_id' => $user->id,
                'log_file_link' => $filePath,

            ]);

            Log::error("Ошибки валидации для УИН {$uin} записаны в файл: {$filePath}");

        } catch (\Exception $e) {
            Log::error("Не удалось записать ошибки в файл: {$e->getMessage()}");

            // Fallback: запись в стандартный лог
            Log::error("Ошибки для УИН {$uin}: " . json_encode($errors, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Конвертация Excel даты (числа) в формат d.m.Y
     */
    public static function convertExcelDate($excelDate): ?string
    {
        if (empty($excelDate)) {
            return null;
        }

        // Если это уже строка с датой, возвращаем как есть
        if (is_string($excelDate) && preg_match('/\d{1,2}\.\d{1,2}\.\d{4}/', $excelDate)) {
            return $excelDate;
        }

        // Если это число Excel (например, 45854)
        if (is_numeric($excelDate)) {
            try {
                // Excel использует 1 января 1900 как точку отсчета (но с ошибкой на 1 день)
                $timestamp = ($excelDate - 25569) * 86400; // 25569 = дней от 1900-01-01 до 1970-01-01
                $date = new \DateTime("@$timestamp");
                return $date->format('d.m.Y');
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Метод возвращает плановое значение количества техники на конкретную дату
     * @param ObjectModel $objectModel
     * @param string $date
     * @return string|null
     */
    public static function getMonitorTechnicaPlanByDate(ObjectModel $objectModel, string $date): ?string
    {
        return $objectModel->monitorTechnica()->where('date', $date)->first()?->count_plan;
    }

    /**
     * Метод возвращает фактическое значение количества техники на конкретную дату
     * @param ObjectModel $objectModel
     * @param string $date
     * @return string|null
     */
    public static function getMonitorTechnicaFactByDate(ObjectModel $objectModel, string $date): ?string
    {
        return $objectModel->monitorTechnica()->where('date', $date)->first()?->count_fact;
    }

    /**
     * Метод возвращает плановое значение количества рабочих на конкретную дату
     * @param ObjectModel $objectModel
     * @param string $date
     * @return string|null
     */
    public static function getMonitorPeoplePlanByDate(ObjectModel $objectModel, string $date): ?string
    {
        return $objectModel->monitorPeople()->where('date', $date)->first()?->count_plan;
    }

    /**
     * Метод возвращает фактическое значение количества рабочих на конкретную дату
     * @param ObjectModel $objectModel
     * @param string $date
     * @return string|null
     */
    public static function getMonitorPeopleFactByDate(ObjectModel $objectModel, string $date): ?string
    {
        return $objectModel->monitorPeople()->where('date', $date)->first()?->count_fact;
    }

    /**
     * Метод сохраняет полученные из Excel значения для MonitorTechnica и MonitorPeople
     *
     * @param ObjectModel $objectModel
     * @param array $data
     * @return void
     *
     */
    public static function setObjectMonitorData(ObjectModel $objectModel, array $data): void
    {
        if (empty($data['дата редактирования строки'])) {
            return; // Не обрабатываем если нет даты
        }

        $date = self::convertDateToDbFormat($data['дата редактирования строки']);

        $technica = [];
        $people = [];

        // Собираем данные с проверкой на пустые и нечисловые значения
        foreach ($data as $fieldName => $value) {
            if ($value === '' || $value === null || !is_numeric($value)) {
                continue;
            }

            $value = (int)$value; // Приводим к integer

            switch ($fieldName) {
                case 'Количество техники (план)':
                    $technica['count_plan'] = $value;
                    break;
                case 'Количество техники (факт)':
                    $technica['count_fact'] = $value;
                    break;
                case 'Количество рабочих (план)':
                    $people['count_plan'] = $value;
                    break;
                case 'Количество рабочих (факт)':
                    $people['count_fact'] = $value;
                    break;
            }
        }

        // Обрабатываем технику
        self::updateOrCreateMonitorRecord($objectModel->monitorTechnica(), $date, $technica);

        // Обрабатываем людей
        self::updateOrCreateMonitorRecord($objectModel->monitorPeople(), $date, $people);
    }

    /**
     * Вспомогательный метод для обновления или создания записи
     */
    private static function updateOrCreateMonitorRecord($relation, string $date, array $data): void
    {
        if (empty($data)) {
            return;
        }

        $record = $relation->where('date', $date)->first();

        if ($record) {
            $record->update($data);
        } else {
            $relation->create(array_merge($data, ['date' => $date]));
        }
    }

    public static function prepeareConstractionStageData($record, $constructionStages): void
    {
        $userId = User::saveAndGetUserIDbyANOusername($record['user АНО СТРОИНФОРМ']);
        $updateDate = JsonHelper::validateAndAdaptDate($record['дата редактирования строки']);
        $objectId = ObjectModel::getIdByUIN($record['УИН']);

        if(!$objectId){
            $error = new \Exception("prepeareConstractionStageData: Не найден объект с УИН => " . $record['УИН']);
        }
        $constructionStageData = [];
        foreach ($constructionStages as $key => $value) {
            if($value && $value != ''){
                $constructionStageID = ConstructionStagesLibrary::saveAndGetConstructionStageID($key);
                // Валидация по имени свойства
                $constructionStageAttrName = ObjectConstructionStage::getAttributeByName($key);

                if(in_array($constructionStageAttrName,['oiv_plan_start','oiv_plan_finish'])){
                    $value = self::convertDateToDbFormat($value);
                }elseif(in_array($constructionStageAttrName,['oiv_plan', 'oiv_fact'])){
                    $value = JsonHelper::percentValidate($value);
                }else{
                    continue;
                }

                if($value && $value != ''){
                    $constructionStageData[$constructionStageID][$constructionStageAttrName] = $value;
                }

            }
        }

        // Логика обработки данных строительных этапов
        if (!empty($constructionStageData)) {
            ObjectConstructionStage::saveConstructionStageData($constructionStageData,$userId,$updateDate,$objectId,
                Auth::user()?->id ?? null,
                Auth::user()?->name ?? null,
            );
        } else {
            Log::info("Нет данных строительных этапов для обработки - " . $record['УИН'] . ".");
        }
    }
}
