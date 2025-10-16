<?php

namespace App\Console\Commands;

use App\Models\ObjectModel;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class TestObjectEndpoints extends Command
{
    protected $signature = 'test:object-endpoints 
                            {--limit= : Ограничить количество объектов для тестирования}
                            {--uids= : Список конкретных UIN для тестирования (через запятую)}
                            {--skip-unauthorized : Пропускать объекты, возвращающие 401}';
    protected $description = 'Тестирование эндпоинтов /dashboard/{uin} и /etapi/{uin} для объектов';

    private string $token;
    private array $allowedObjectIds;
    private string $logContent = '';
    private string $baseUrl;
    private TelegramService $telegramService;
    private int $maxRetries = 3;
    private int $retryDelay = 1; // задержка между попытками в секундах
    private int $emptyArraysCount = 0;
    private int $objectsWithoutPhotos = 0;
    private int $objectsWithoutOIV = 0;
    private int $objectsWithoutSMG = 0;

    public function __construct()
    {
        parent::__construct();
        $this->telegramService = new TelegramService();
    }

    public function handle()
    {
        $this->baseUrl = 'http://dashboard.cifrolab.site';
//        $this->baseUrl = 'http://predprod.dashboard.cifrolab.site';
//        $this->baseUrl = 'http://webserver:80'; // Для тестирования в Docker

        $this->logContent = "Тестирование эндпоинтов объектов\n";
        $this->logContent .= "URL: " . str_replace('/api', '', $this->baseUrl) . "\n";
        $this->logContent .= "Дата: " . now()->format('Y-m-d H:i:s') . "\n\n";

        if (!$this->login($this->baseUrl)) {
            $errorMsg = "Не удалось войти. Проверьте APP_URL (текущий: {$this->baseUrl})";
            $this->error($errorMsg);
            $this->logContent .= "ОШИБКА: $errorMsg\n";
            $this->sendLogFile();
            return 1;
        }

        $uins = $this->getUinsToTest();
        if (empty($uins)) {
            $errorMsg = 'Не найдено объектов для тестирования.';
            $this->error($errorMsg);
            $this->logContent .= "ОШИБКА: $errorMsg\n";
            $this->sendLogFile();
            return 1;
        }

        $this->info('Начато тестирование для ' . count($uins) . ' объектов...');
        $this->logContent .= "Тестируется " . count($uins) . " объектов\n\n";

        $failedTests = 0;
        $successCount = 0;
        $skippedCount = 0;

        // Сброс счетчиков перед тестированием
        $this->emptyArraysCount = 0;
        $this->objectsWithoutPhotos = 0;
        $this->objectsWithoutOIV = 0;
        $this->objectsWithoutSMG = 0;

        foreach ($uins as $uin) {
            $result = $this->testEndpoints($this->baseUrl, $uin);
            if ($result === true) {
                $successCount++;
            } elseif ($result === null) {
                $skippedCount++;
            } else {
                $failedTests++;
            }
        }

        $summary = "\nРезультаты тестирования:\n";
        $summary .= "Успешно: {$successCount}\n";
        $summary .= "Ошибки: {$failedTests}\n";
        $summary .= "Пропущено (неавторизовано): {$skippedCount}\n";
        $summary .= "Пустые массивы (предупреждения): {$this->emptyArraysCount}\n";
        $summary .= "Объектов без фото: {$this->objectsWithoutPhotos}\n";
        $summary .= "Объектов без Этапы строительства ОИВ: {$this->objectsWithoutOIV}\n";
        $summary .= "Объектов без Этапы строительства SMG: {$this->objectsWithoutSMG}\n";

        $this->info($summary);
        $this->logContent .= $summary;

        $this->sendLogFile();

        return $failedTests > 0 ? 1 : 0;
    }

    private function sendLogFile(): void
    {
        if (empty($this->logContent)) {
            return;
        }

        $logFileName = storage_path('logs/object_endpoints_test_' . now()->format('Y-m-d_H-i-s') . '.log');
        file_put_contents($logFileName, $this->logContent);

        $this->telegramService->sendDocument(
            $logFileName,
            "Пустые данные по объектам, требующие внимания аналитиков!\n" .
            "Ссылка: " . str_replace('/api', '', $this->baseUrl)
        );

        // Удаляем файл после отправки
        unlink($logFileName);
    }

    private function logError(string $message): void
    {
        $this->logContent .= "ОШИБКА: $message\n";
    }

    private function logWarning(string $message): void
    {
        $this->logContent .= "ПРЕДУПРЕЖДЕНИЕ: $message\n";
    }

    private function logInfo(string $message): void
    {
//        $this->logContent .= "ИНФО: $message\n";
    }

    private function login(string $baseUrl): bool
    {
        $credentials = [
            'email' => env('TEST_USER_EMAIL'),
            'password' => env('TEST_USER_PASSWORD'),
        ];

        try {
            $this->info("Проверка доступности сервера по адресу: {$baseUrl}");
            $this->logInfo("Проверка доступности сервера по адресу: {$baseUrl}");

            $pingResponse = Http::withHeaders([
                'Accept' => 'application/json',
            ])->get($baseUrl);

            if (!$pingResponse->successful()) {
                $errorMsg = "Сервер не отвечает. Статус: {$pingResponse->status()}";
                $this->error($errorMsg);
                $this->logError($errorMsg);
                return false;
            }

            $this->info("Попытка входа с email: {$credentials['email']}");
            $this->logInfo("Попытка входа с email: {$credentials['email']}");

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post("{$baseUrl}/api/login", $credentials);

            if (!$response->successful() || !$response->json('status')) {
                $errorMsg = 'Ошибка входа: ' . ($response->json('message') ?? 'Неизвестная ошибка');
                $this->error($errorMsg);
                $this->logError($errorMsg);
                return false;
            }

            $this->token = $response->json('token');
            $this->allowedObjectIds = ObjectModel::all()->pluck('id')->toArray();

            $successMsg = 'Успешный вход. Токен: ' . substr($this->token, 0, 10) . '...';
            $this->info($successMsg);
            $this->logInfo($successMsg);
            return true;
        } catch (\Exception $e) {
            $errorMsg = 'Исключение при входе: ' . $e->getMessage();
            $this->error($errorMsg);
            $this->logError($errorMsg);
            return false;
        }
    }

    private function getUinsToTest(): array
    {
        if ($this->option('uids')) {
            return explode(',', $this->option('uids'));
        }

        $query = ObjectModel::query();

        if ($this->option('limit')) {
            $query->limit((int)$this->option('limit'));
        }

        return $query->pluck('uin')->toArray();
    }

    private function testEndpoints(string $baseUrl, string $uin): ?bool
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];

        $this->info("\nТестирование объекта с UIN: {$uin}");
        $this->logInfo("\nТестирование объекта с UIN: {$uin}");

        $objectId = ObjectModel::where('uin', $uin)->value('id');

        if (!in_array($objectId, $this->allowedObjectIds)) {
            if ($this->option('skip-unauthorized')) {
                $msg = "Пропуск неавторизованного объекта UIN: {$uin} (ID: {$objectId})";
                $this->warn($msg);
                $this->logWarning($msg);
                return null;
            }

            $errorMsg = "Доступ запрещен к объекту UIN: {$uin} (ID: {$objectId})";
            $this->error($errorMsg);
            $this->logError($errorMsg);
            return false;
        }

        // Тестирование /dashboard/{uin} с повторами
        $dashboardResponse = $this->makeRequestWithRetry(
            "{$baseUrl}/api/dashboard/{$uin}",
            $headers,
            $uin,
            'Dashboard'
        );

        if ($dashboardResponse === null || !$this->validateResponse($dashboardResponse, $uin, 'Dashboard')) {
            return false;
        }

        // Тестирование /etapi/{uin} с повторами
        $etapiResponse = $this->makeRequestWithRetry(
            "{$baseUrl}/api/etapi/{$uin}",
            $headers,
            $uin,
            'Etapi'
        );

        if ($etapiResponse === null || !$this->validateResponse($etapiResponse, $uin, 'Etapi')) {
            return false;
        }

        $successMsg = "Успешно протестированы эндпоинты для UIN: {$uin}";
        $this->info($successMsg);
        $this->logInfo($successMsg);
        return true;
    }

    private function makeRequestWithRetry(string $url, array $headers, string $uin, string $endpoint): ?\Illuminate\Http\Client\Response
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->maxRetries) {
            try {
                $response = Http::withHeaders($headers)
                    ->timeout(30)
                    ->get($url);

                if ($response->successful()) {
                    return $response;
                }
                $attemptNumber = $attempt + 1;
                $lastError = "Ошибка {$endpoint} эндпоинта для UIN {$uin}: HTTP {$response->status()}";
                $this->warn("Попытка {$attemptNumber}: {$lastError}");
                $this->logWarning("Попытка {$attemptNumber}: {$lastError}");

                if ($response->status() === 401 && $this->option('skip-unauthorized')) {
                    $msg = "Пропуск неавторизованного {$endpoint} эндпоинта для UIN {$uin}";
                    $this->warn($msg);
                    $this->logWarning($msg);
                    return null;
                }
            } catch (\Exception $e) {
                $attemptNumber = $attempt + 1;
                $lastError = "Исключение при запросе {$endpoint} для UIN {$uin}: " . $e->getMessage();
                $this->warn("Попытка {$attemptNumber}: {$lastError}");
                $this->logWarning("Попытка {$attemptNumber}: {$lastError}");
            }

            $attempt++;
            if ($attempt < $this->maxRetries) {
                sleep($this->retryDelay);
            }
        }

        $errorMsg = "Не удалось получить ответ после {$this->maxRetries} попыток для {$endpoint} эндпоинта UIN {$uin}. Последняя ошибка: {$lastError}";
        $this->error($errorMsg);
        $this->logError($errorMsg);

        return null;
    }

    private function validateResponse($response, string $uin, string $endpoint): bool
    {
        if ($response === null) {
            return false;
        }

        if ($response->status() !== 200) {
            $errorMsg = "Ошибка {$endpoint} эндпоинта для UIN {$uin}: HTTP {$response->status()}";
            $this->error($errorMsg);
            $this->logError($errorMsg);
            $this->error("Ответ: " . $response->body());
            $this->logError("Ответ: " . $response->body());
            return false;
        }

        $data = $response->json();

        if ($data === null) {
            $errorMsg = "Получен пустой ответ (null) для UIN {$uin}";
            $this->error($errorMsg);
            $this->logError($errorMsg);
            return false;
        }

        if (!is_array($data)) {
            $errorMsg = "Некорректный формат ответа для UIN {$uin}. Ожидается массив, получен: " . gettype($data);
            $this->error($errorMsg);
            $this->logError($errorMsg);
            return false;
        }

        if (isset($data['error'])) {
            $errorMsg = "Ошибка {$endpoint} эндпоинта для UIN {$uin}: " . $data['error'];
            $this->error($errorMsg);
            $this->logError($errorMsg);
            return false;
        }

        $this->info("\nПроверка данных для UIN: {$uin} ({$endpoint})");
        $this->logInfo("Проверка данных для UIN: {$uin} ({$endpoint})");

        try {
            if ($endpoint === 'Dashboard') {
                $this->checkDashboardData($data, $uin);
            } else {
                $this->checkEtapiData($data, $uin);
            }
        } catch (\Exception $e) {
            $errorMsg = "Ошибка при проверке данных: " . $e->getMessage();
            $this->error($errorMsg);
            $this->logError($errorMsg);
            return false;
        }

        return true;
    }

    private function checkDashboardData(array $data, string $uin): void
    {
        $this->info("Основные данные объекта:");
        $this->logInfo("Основные данные объекта:");

        $requiredFields = ['id', 'uin', 'name', 'address'];
        $this->checkRequiredFields($data['object'], $requiredFields, $uin);

        // Проверяем и считаем пустые массивы
        $photosEmpty = $this->checkArrayData($data, 'photos', 'Фотографии', $uin);
        $oivEmpty = $this->checkArrayData($data, 'constructionStagesOIV', 'Этапы строительства OIV', $uin);
        $smgEmpty = $this->checkArrayData($data, 'constructionStagesSMG', 'Этапы строительства SMG', $uin);

        $this->checkArrayData($data, 'etapiiRealizacii', 'Этапы реализации', $uin);

        if ($photosEmpty) {
            $this->objectsWithoutPhotos++;
        }
        if ($oivEmpty) {
            $this->objectsWithoutOIV++;
        }
        if ($smgEmpty) {
            $this->objectsWithoutSMG++;
        }

        if (isset($data['panelMonitoring'])) {
            $this->info("Данные мониторинга:");
            $this->logInfo("Данные мониторинга:");
            $this->checkMonitoringData($data['panelMonitoring'], $uin);
        }
    }

    private function checkEtapiData(array $data, string $uin): void
    {
        $this->info("Данные контрольных точек:");
        $this->logInfo("Данные контрольных точек:");

        if (isset($data['control_points'])) {
            $this->checkControlPoints($data['control_points'], $uin);
        } else {
            $msg = "Отсутствуют данные контрольных точек";
            $this->warn($msg);
            $this->logWarning($msg);
        }

        if (isset($data['stroyGotovnost'])) {
            $msg = "Строительная готовность: {$data['stroyGotovnost']['fact']}%";
            $this->info($msg);
            $this->logInfo($msg);
        }

        if (isset($data['etapi_realizacii'])) {
            $count = count($data['etapi_realizacii']);
            $msg = "Этапы реализации: найдено {$count} элементов";
            $this->info($msg);
            $this->logInfo($msg);
        }

        if (isset($data['finance_planned_percent'])) {
            $msg = "Финансовая готовность: {$data['finance_planned_percent']}%";
            $this->info($msg);
            $this->logInfo($msg);
        }
    }

    private function checkControlPoints(array $controlPoints, string $uin): void
    {
        $this->info("Контрольные точки:");
        $this->logInfo("Контрольные точки:");

        if (isset($controlPoints['in_progress'])) {
            $msg = "В процессе: {$controlPoints['in_progress']['count']} шт.";
            $this->info($msg);
            $this->logInfo($msg);
        }

        if (isset($controlPoints['complete'])) {
            $msg = "Завершено: {$controlPoints['complete']['count']} шт.";
            $this->info($msg);
            $this->logInfo($msg);
        }

        if (isset($controlPoints['all'])) {
            $msg = "Всего: {$controlPoints['all']} шт.";
            $this->info($msg);
            $this->logInfo($msg);
        }

        if (isset($controlPoints['complete_percent'])) {
            $msg = "Процент завершения: {$controlPoints['complete_percent']}%";
            $this->info($msg);
            $this->logInfo($msg);
        }
    }

    private function checkRequiredFields(array $data, array $requiredFields, string $uin): void
    {
        $missingFields = [];
        $nullFields = [];

        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $data)) {
                $missingFields[] = $field;
            } elseif ($data[$field] === null) {
                $nullFields[] = $field;
            }
        }

        if (!empty($missingFields) && count($missingFields)>0) {
            $msg = "Отсутствуют обязательные поля: " . implode(', ', $missingFields);
            $this->error($msg);
            $this->logError("UIN {$uin}: $msg");
        }

        if (!empty($nullFields)) {
            $msg = "Обязательные поля с NULL значениями: " . implode(', ', $nullFields);
            $this->error($msg);
            $this->logError("UIN {$uin}: $msg");
        }

        if (empty($missingFields) && empty($nullFields)) {
            $msg = "Все обязательные поля присутствуют и заполнены";
            $this->info($msg);
            $this->logInfo("UIN {$uin}: $msg");
        }
    }

    private function checkArrayData(array $data, string $key, string $name, string $uin): bool
    {
        $isEmpty = false;

        if (isset($data[$key])) {
            $count = count($data[$key]);
            if ($count === 0) {
                $this->emptyArraysCount++;
                $isEmpty = true;
                $msg = "{$name}: найдено {$count} элементов";
                $this->warn($msg);
                $this->logWarning("UIN {$uin}: $msg");
            } else {
                $msg = "{$name}: найдено {$count} элементов";
                $this->info($msg);
                $this->logInfo("UIN {$uin}: $msg");
            }
        } else {
            $msg = "Отсутствует ключ '{$key}' в ответе";
            $this->warn($msg);
            $this->logWarning("UIN {$uin}: $msg");
            $isEmpty = true;
        }

        return $isEmpty;
    }

    private function checkMonitoringData(array $monitoring, string $uin): void
    {
        $sections = [
            'monitor_people_days' => 'Люди (дни)',
            'monitor_people_weeks' => 'Люди (недели)',
            'monitor_people_months' => 'Люди (месяцы)',
//            'monitor_technica_days' => 'Техника (дни)',
//            'monitor_technica_weeks' => 'Техника (недели)',
//            'monitor_technica_months' => 'Техника (месяцы)'
        ];

        foreach ($sections as $key => $name) {
            if (isset($monitoring[$key])) {
                $count = count($monitoring[$key]);
                if ($count === 0) {
                    $this->emptyArraysCount++;
                    $msg = "  {$name}: {$count} записей";
                    $this->warn($msg);
                    $this->logWarning("UIN {$uin}: $msg");
                } else {
                    $msg = "  {$name}: {$count} записей";
                    $this->info($msg);
                    $this->logInfo("UIN {$uin}: $msg");
                }

                foreach ($monitoring[$key] as $item) {
                    foreach ($item as $field => $value) {
                        if ($value === null) {
                            $msg = "    NULL значение в поле {$field} для {$name}";
                            $this->warn($msg);
                            $this->logWarning("UIN {$uin}: $msg");
                        }
                    }
                }
            } else {
                $msg = "  Отсутствует раздел {$name}";
                $this->warn($msg);
                $this->logWarning("UIN {$uin}: $msg");
            }
        }
    }
}