<?php

namespace App\Services;

use App\Models\Library\ConstructionStagesLibrary;
use App\Models\ObjectConstructionStage;
use App\Models\ObjectModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExternalAIPhotoService
{
    private string $baseUrl;
    private string $token;
    private const CACHE_KEY = 'external_api_uin_to_id_map';
    private const CACHE_TTL = 86400; // 24 часа в секундах

    public function __construct()
    {
        // Безопасная загрузка конфигурации с значениями по умолчанию
        $this->baseUrl = config('services.external_ai_service_api.url', 'https://msi.construction-monitoring.contextmachine.cloud');
        $this->token = config('services.external_ai_service_api.token', '');

        // Валидация конфигурации
        if (empty($this->baseUrl)) {
            throw new \InvalidArgumentException('URL внешнего API не настроен');
        }

        if (empty($this->token)) {
            throw new \InvalidArgumentException('Токен внешнего API не настроен');
        }
    }

    /**
     * Получить базовый URL API
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Получить токен API
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * Получить все проекты и сохранить в кэш
     */
    private function fetchAndCacheAllProjects(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
            ])->timeout(30)
                ->get($this->baseUrl . '/get_all_projects');

            if ($response->successful()) {
                $projects = $response->json();

                if (!is_array($projects)) {
                    Log::error('Некорректный формат ответа от /get_all_projects', [
                        'response' => $response->body()
                    ]);
                    return [];
                }

                $uinToIdMap = [];

                foreach ($projects as $project) {
                    if (isset($project['UIN']) && isset($project['id'])) {
                        $uinToIdMap[$project['UIN']] = $project['id'];
                    }
                }

                if (!empty($uinToIdMap)) {
                    Cache::put(self::CACHE_KEY, $uinToIdMap, self::CACHE_TTL);
                    Log::info('Кэш проектов успешно обновлен', ['count' => count($uinToIdMap)]);
                } else {
                    Log::warning('Получен пустой список проектов от API');
                }

                return $uinToIdMap;
            }

            Log::error('Не удалось получить список проектов', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return [];

        } catch (\Exception $e) {
            Log::error('Исключение при получении списка проектов: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Получить кэшированные соответствия UIN -> ID
     */
    private function getCachedUinToIdMap(): array
    {
        $cachedData = Cache::get(self::CACHE_KEY, []);

        // Если кэш пустой, пытаемся загрузить данные
        if (empty($cachedData)) {
            $cachedData = $this->fetchAndCacheAllProjects();
        }

        return is_array($cachedData) ? $cachedData : [];
    }

    /**
     * Получить ID проекта по УИН (с использованием кэша)
     */
    public function getProjectByUin(string $uin): ?string
    {
        if (empty($uin)) {
            Log::error('Передан пустой UIN');
            return null;
        }

        try {
            // Получаем кэшированные данные
            $uinToIdMap = $this->getCachedUinToIdMap();

            // Если UIN найден в кэше, возвращаем ID
            if (isset($uinToIdMap[$uin])) {
                Log::debug('ID проекта найден в кэше', [
                    'uin' => $uin,
                    'id' => $uinToIdMap[$uin]
                ]);
                return $uinToIdMap[$uin];
            }

            // Если UIN не найден в кэше, обновляем кэш и проверяем снова
            Log::warning('UIN не найден в кэше, пытаемся обновить кэш', ['uin' => $uin]);
            $uinToIdMap = $this->fetchAndCacheAllProjects();

            if (isset($uinToIdMap[$uin])) {
                return $uinToIdMap[$uin];
            }

            // Если UIN всё ещё не найден после обновления кэша
            Log::error('UIN не найден в системе внешнего API даже после обновления кэша', [
                'uin' => $uin,
                'available_uins' => array_keys($uinToIdMap)
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Исключение при получении проекта по УИН: ' . $e->getMessage(), [
                'uin' => $uin,
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Принудительно обновить кэш проектов
     */
    public function refreshProjectsCache(): array
    {
        $result = $this->fetchAndCacheAllProjects();
        return [
            'success' => !empty($result),
            'count' => count($result),
            'uins' => array_keys($result)
        ];
    }

    /**
     * Получить статистику кэша
     */
    public function getCacheStats(): array
    {
        $cachedData = $this->getCachedUinToIdMap();

        return [
            'cached_items' => count($cachedData),
            'cache_key' => self::CACHE_KEY,
            'cache_ttl' => self::CACHE_TTL,
            'has_cache' => Cache::has(self::CACHE_KEY),
            'available_uins' => array_keys($cachedData)
        ];
    }

    /**
     * Отправить фотографию на внешний сервер (обновленная версия с проверкой кэша)
     */
    public function uploadPhoto(string $photoPath, string $date, string $uin): bool
    {
        // ВАЖНО: Проверка существования файла должна быть в вызывающем коде
        // Этот метод предполагает, что файл уже проверен

        try {
            // Получаем project_id из кэша по UIN
            $projectId = $this->getProjectByUin($uin);

            if (!$projectId) {
                Log::error('Не удалось получить ID проекта для загрузки фото. UIN не найден.', [
                    'uin' => $uin,
                    'photo_path' => $photoPath
                ]);
                return false;
            }

            $carbonDate = Carbon::parse($date);
            $timestampWithMicro = (int)$carbonDate->format('U')*1000;

            // Логируем запрос ДО отправки
            $this->logRequestDetails($photoPath, $timestampWithMicro, $projectId);

//            $requestDump = [
//                'url' => $this->baseUrl . '/create_upload_and_photos_db',
//                'headers' => [
//                    'Authorization' => 'Bearer ' . $this->token,
//                    'Accept' => 'application/json',
//                ],
//                'form_data' => [
//                    'load_date' => $timestampWithMicro,
//                    'shot_date' => $timestampWithMicro,
//                    'project_id' => $projectId,
//                ],
//                'file' => [
//                    'field' => 'upl_img',
//                    'path' => $photoPath,
//                    'name' => basename($photoPath),
//                    'size' => filesize($photoPath),
//                ]
//            ];

            // ВЫВОДИМ СЛЕПОК И ПРЕРЫВАЕМ ВЫПОЛНЕНИЕ
//            dd('REQUEST DUMP', $requestDump);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
            ])->timeout(60)
                ->attach('upl_img', file_get_contents($photoPath), basename($photoPath))
                ->post($this->baseUrl . '/create_upload_and_photos_db?' . http_build_query([
                    'load_date' => $timestampWithMicro,
                    'shot_date' => $timestampWithMicro,
                    'project_id' => $projectId
                ]));

            if ($response->successful()) {
                Log::info('Фотография успешно загружена', [
                    'photo_path' => $photoPath,
                    'project_id' => $projectId,
                    'uin' => $uin
                ]);
                return true;
            }

            // Логируем ошибку в отдельный файл
            $this->logToExternalFile('Ошибка загрузки фотографии: ' . $response->body(), [
                'photo_path' => $photoPath,
                'project_id' => $projectId,
                'uin' => $uin,
                'date' => $date,
                'status' => $response->status()
            ]);

            return false;

        } catch (\Exception $e) {
            $this->logToExternalFile('Исключение при загрузке фотографии: ' . $e->getMessage(), [
                'photo_path' => $photoPath,
                'uin' => $uin,
                'date' => $date,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Упрощенная версия для загрузки с предварительной проверкой
     */
    public function uploadPhotoWithValidation(string $photoPath, string $date, string $uin): bool
    {
        // Проверка файла перед отправкой
        if (!file_exists($photoPath)) {
            Log::error('Файл фотографии не найден', ['path' => $photoPath]);
            return false;
        }

        if (!$this->isValidImage($photoPath)) {
            Log::error('Файл не является валидным изображением', ['path' => $photoPath]);
            return false;
        }

        return $this->uploadPhoto($photoPath, $date, $uin);
    }

    /**
     * Проверяет, является ли файл валидным изображением
     */
    private function isValidImage(string $filePath): bool
    {
        try {
            $imageInfo = @getimagesize($filePath);
            return $imageInfo !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Логирование в отдельный файл
     */
    private function logToExternalFile(string $message, array $context = []): void
    {
        try {
            $logMessage = date('Y-m-d H:i:s') . ' - ' . $message;
            if (!empty($context)) {
                $logMessage .= ' - Контекст: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
            }

            // Используем прямое файловое взаимодействие вместо Storage
            $logPath = storage_path('logs/external_ai_service_api_errors.log');
            file_put_contents($logPath, $logMessage . PHP_EOL, FILE_APPEND | LOCK_EX);

        } catch (\Exception $e) {
            // Резервное логирование в стандартный лог
            Log::error('Не удалось записать во внешний лог: ' . $e->getMessage());
        }
    }

    /**
     * Пакетная загрузка всех фотографий
     */
    public function uploadAllPhotos(array $photos): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($photos as $index => $photo) {
            if (!isset($photo['path']) || !isset($photo['date']) || !isset($photo['uin'])) {
                $results['failed']++;
                $results['errors'][] = "Неверный формат данных для фото #{$index}";
                continue;
            }

            $success = $this->uploadPhotoWithValidation(
                $photo['path'],
                $photo['date'],
                $photo['uin']
            );

            if ($success) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = "Не удалось загрузить: {$photo['path']} (UIN: {$photo['uin']})";
            }
        }

        return $results;
    }

    /**
     * Проверка соединения с API
     */
    public function testConnection(): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
            ])->timeout(15)
                ->get($this->baseUrl . '/get_all_projects');

            return $response->successful();

        } catch (\Exception $e) {
            Log::error('Тест соединения с API не удался: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Получить список всех доступных UIN из кэша
     */
    public function getAvailableUins(): array
    {
        $cachedData = $this->getCachedUinToIdMap();
        return array_keys($cachedData);
    }

    /**
     * Проверить, существует ли UIN в системе
     */
    public function isUinExists(string $uin): bool
    {
        return $this->getProjectByUin($uin) !== null;
    }

    /**
     * Обработка данных AI по этапам строительства из внешнего API
     */
    public function processConstructionStagesData(array $data, string $uin): array
    {
        $results = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        try {
            // Получаем объект по UIN
            $object = ObjectModel::where('uin', $uin)->first();

            if (!$object) {
                $error = "Объект с UIN '{$uin}' не найден в системе";
                $results['errors'][] = $error;
                Log::error($error);
                return $results;
            }

            // Проверяем наличие даты
            if (!isset($data['date']) || empty($data['date'])) {
                $error = "Отсутствует дата в данных";
                $results['errors'][] = $error;
                Log::error($error);
                return $results;
            }

            // Преобразуем дату в понедельник недели
            $date = Carbon::parse($data['date']);
            $mondayDate = $date->copy()->startOfWeek();

            // Проверяем наличие этапов
            if (!isset($data['stages']) || !is_array($data['stages']) || empty($data['stages'])) {
                $error = "Отсутствуют этапы в данных";
                $results['errors'][] = $error;
                Log::error($error);
                return $results;
            }

            $results['processed'] = count($data['stages']);

            foreach ($data['stages'] as $index => $stageData) {
                try {
                    // Валидация данных этапа
                    if (!isset($stageData['stage']['id_db']) || !isset($stageData['stage']['name_db'])) {
                        $error = "Этап #{$index}: отсутствует id_db или name_db";
                        $results['errors'][] = $error;
                        $results['failed']++;
                        continue;
                    }

                    if (!isset($stageData['percent'])) {
                        $error = "Этап #{$index}: отсутствует percent";
                        $results['errors'][] = $error;
                        $results['failed']++;
                        continue;
                    }

                    $stageId = $stageData['stage']['id_db'];
                    $stageNameFromApi = $stageData['stage']['name_db'];
                    $aiFact = $stageData['percent'];

                    // Проверяем, существует ли этап в нашей библиотеке
                    $stage = ConstructionStagesLibrary::find($stageId);

                    if (!$stage) {
                        $error = "Этап #{$index}: этап с ID {$stageId} не найден в библиотеке";
                        $results['errors'][] = $error;
                        $results['failed']++;
                        continue;
                    }

                    // Сравниваем названия этапов
                    if ($stage->name !== $stageNameFromApi) {
                        $error = "Этап #{$index}: несоответствие названий. Наше: '{$stage->name}', от API: '{$stageNameFromApi}'";
                        $results['errors'][] = $error;
                        Log::warning($error, [
                            'our_stage_id' => $stage->id,
                            'our_stage_name' => $stage->name,
                            'api_stage_name' => $stageNameFromApi
                        ]);
                        // Продолжаем обработку, но логируем несоответствие
                    }

                    // Преобразуем percent в integer (0-100)
                    $aiFactValue = $this->normalizeAiFact($aiFact);

                    // Ищем или создаем запись строительного этапа
                    $constructionStage = ObjectConstructionStage::updateOrCreate(
                        [
                            'object_id' => $object->id,
                            'construction_stages_library_id' => $stageId,
                            'created_date' => $mondayDate->format('Y-m-d'),
                        ],
                        [
                            'ai_fact' => $aiFactValue,
                        ]
                    );

                    if ($constructionStage) {
                        $results['success']++;
                        Log::info("Успешно обработан этап #{$index}", [
                            'object_id' => $object->id,
                            'stage_id' => $stageId,
                            'ai_fact' => $aiFactValue,
                            'date' => $mondayDate->format('Y-m-d')
                        ]);
                    } else {
                        $error = "Этап #{$index}: не удалось сохранить данные";
                        $results['errors'][] = $error;
                        $results['failed']++;
                    }

                } catch (\Exception $e) {
                    $error = "Этап #{$index}: ошибка обработки - " . $e->getMessage();
                    $results['errors'][] = $error;
                    $results['failed']++;
                    Log::error($error, ['trace' => $e->getTraceAsString()]);
                }
            }

            return $results;

        } catch (\Exception $e) {
            $error = "Общая ошибка обработки данных: " . $e->getMessage();
            $results['errors'][] = $error;
            Log::error($error, ['trace' => $e->getTraceAsString()]);
            return $results;
        }
    }

    /**
     * Нормализует значение AI факта (0-100)
     */
    private function normalizeAiFact($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        // Преобразуем в число
        $numericValue = is_numeric($value) ? (float)$value : 0;

        // Ограничиваем диапазон 0-100
        $normalized = max(0, min(100, $numericValue));

        // Преобразуем в integer
        return (int)round($normalized);
    }

    /**
     * Получить статистику по обработке данных этапов
     */
    public function getProcessingStats(array $results): array
    {
        return [
            'total_processed' => $results['processed'],
            'successful' => $results['success'],
            'failed' => $results['failed'],
            'success_rate' => $results['processed'] > 0
                ? round(($results['success'] / $results['processed']) * 100, 2)
                : 0,
            'error_count' => count($results['errors']),
            'errors' => $results['errors']
        ];
    }

    /**
     * Преобразует дату в формат с микросекундами
     */
    private function convertDateToMicroseconds(string $date): string
    {
        try {
            // Создаем объект Carbon из переданной даты
            $carbonDate = Carbon::parse($date);

            // Получаем Unix timestamp с микросекундами
            $timestampWithMicro = $carbonDate->format('U.u');

            return $timestampWithMicro;

        } catch (\Exception $e) {
            // В случае ошибки возвращаем текущее время с микросекундами
            Log::warning('Ошибка преобразования даты, используется текущее время', [
                'original_date' => $date,
                'error' => $e->getMessage()
            ]);

            return Carbon::now()->format('U.u');
        }
    }


    /**
     * Логирует детали запроса перед отправкой
     */
    private function logRequestDetails(string $photoPath, string $timestampWithMicro, string $projectId): void
    {
        try {
            $logData = [
                'timestamp' => now()->toDateTimeString(),
                'url' => $this->baseUrl . '/create_upload_and_photos_db',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept' => 'application/json',
                ],
                'form_data' => [
                    'load_date' => $timestampWithMicro,
                    'shot_date' => $timestampWithMicro,
                    'project_id' => $projectId,
                ],
                'file' => [
                    'field_name' => 'upl_img',
                    'file_path' => $photoPath,
                    'file_name' => basename($photoPath),
                    'file_exists' => file_exists($photoPath),
                    'file_size' => file_exists($photoPath) ? filesize($photoPath) : 0,
                ],
                'timestamp_details' => [
                    'original_value' => $timestampWithMicro,
                    'as_float' => (float)$timestampWithMicro,
                ]
            ];

            // Логируем в отдельный файл
            $this->logToExternalFile('REQUEST DUMP - Before sending', $logData);

            // Также логируем в стандартный лог
            Log::debug('API Request Dump', $logData);

        } catch (\Exception $e) {
            Log::error('Failed to log request details: ' . $e->getMessage());
        }
    }
}
