<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\ObjectModel;
use App\Models\Photo;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;
use Throwable;
use Illuminate\Http\Client\RequestException;

class ProcessUGDPhotosBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $uins;

    public function __construct(array $uins)
    {
        try {
            $this->uins = $uins;
            $this->onQueue('ugd-photos-batch-debug44');
            Log::info("ProcessUGDPhotosBatch constructed", ['uins' => $uins]);
        } catch (Throwable $e) {
            Log::error("Error in ProcessUGDPhotosBatch constructor: " . $e->getMessage());
            throw $e;
        }
    }

    public function handle()
    {
        Log::info("ProcessUGDPhotosBatch START HANDLE");
        try {
            Log::info("ProcessUGDPhotosBatch started", ['uins' => $this->uins]);

            if (count($this->uins) > 5) {
                $error = "Максимум 5 UIN в батче";
                Log::error($error);
                throw new Exception($error);
            }

            Log::info("Fetching photos from UGD");
            $photosData = $this->fetchPhotosFromUGD($this->uins);
            Log::info("Photos fetched", ['count' => count($photosData)]);

            foreach ($this->uins as $uin) {
                if (!empty($photosData[$uin])) {
                    Log::info("Processing photos for UIN", ['uin' => $uin, 'photo_count' => count($photosData[$uin])]);
                    $this->processPhotosForUin($uin, $photosData[$uin]);
                } else {
                    Log::warning("No photos found for UIN", ['uin' => $uin]);
                }
            }

            Log::info("ProcessUGDPhotosBatch completed successfully");

        } catch (Throwable $e) {
            Log::error("Error in ProcessUGDPhotosBatch handle: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function fetchPhotosFromUGD(array $uins): array
    {
        try {
            Log::debug("Making UGD API request", ['uins' => $uins]);

            $config = config('services.ugd');

            // Детальная проверка конфигурации
            if (empty($config['username']) || empty($config['password'])) {
                $error = "UGD credentials are not configured. Check .env file";
                Log::error($error, ['config' => $config]);
                throw new Exception($error);
            }

            if (empty($config['api_url'])) {
                $error = "UGD API URL is not configured";
                Log::error($error, ['config' => $config]);
                throw new Exception($error);
            }

            Log::debug("UGD config check passed", [
                'api_url' => $config['api_url'],
                'username' => $config['username'],
                'has_password' => !empty($config['password'])
            ]);

            // Детальный лог того, что мы отправляем
            Log::debug("Request details", [
                'url' => $config['api_url'],
                'method' => 'POST',
                'body' => $uins,
                'auth_type' => 'Basic Auth'
            ]);

            // Тестовый запрос для диагностики
//            $this->testConnection($config);

            $response = Http::timeout($config['timeout'] ?? 30)
                ->retry($config['retry_attempts'] ?? 3, 1000)
                ->withBasicAuth($config['username'], $config['password'])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'MSI-Tech-UGD-Client/1.0'
                ])
                ->withOptions([
                    'debug' => false,
                    'verify' => true
                ])
                ->post($config['api_url'], $uins);

            Log::debug("UGD API response status", ['status' => $response->status()]);
            Log::debug("UGD API response headers", ['headers' => $response->headers()]);

            if ($response->status() === 401) {
                $this->handle401Error($config, $response);
            }

            if ($response->status() === 403) {
                $error = "Ошибка доступа 403: Доступ запрещен";
                Log::error($error, [
                    'username' => $config['username'],
                    'api_url' => $config['api_url'],
                    'response_body' => $response->body()
                ]);
                throw new Exception($error);
            }

            if (!$response->successful()) {
                $error = "HTTP ошибка: " . $response->status() . " - " . $response->body();
                Log::error($error, [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new Exception($error);
            }

            $responseBody = $response->body();
            Log::debug("UGD API response body", ['body' => $responseBody]);

            if ($responseBody === '"Максимум 5 штук"') {
                throw new Exception("Превышен лимит: максимум 5 UIN за запрос");
            }

            $data = $response->json();
            Log::debug("UGD API parsed response", ['data' => $data]);

            if (!is_array($data)) {
                throw new Exception("Неверный формат ответа от UGD API: " . gettype($data));
            }

            return $data;

        } catch (RequestException $e) {
            $this->handleRequestException($e);
        } catch (Throwable $e) {
            Log::error("Error in fetchPhotosFromUGD: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Тестовый запрос для диагностики соединения
     */
    protected function testConnection(array $config): void
    {
        try {
            Log::debug("Testing connection to UGD API");

            // Простой HEAD запрос для проверки доступности
            $testResponse = Http::timeout(10)
                ->withBasicAuth($config['username'], $config['password'])
                ->withHeaders(['Accept' => 'application/json'])
                ->head($config['api_url']);

            Log::debug("Connection test result", [
                'status' => $testResponse->status(),
                'headers' => $testResponse->headers()
            ]);

        } catch (RequestException $e) {
            Log::warning("Connection test failed: " . $e->getMessage());
            // Продолжаем работу, это только тест
        }
    }

    /**
     * Обработка 401 ошибки с детальной диагностикой
     */
    protected function handle401Error(array $config, $response): void
    {
        $error = "Ошибка аутентификации 401: Неверные учетные данные UGD";

        $diagnosticInfo = [
            'username' => $config['username'],
            'api_url' => $config['api_url'],
            'response_status' => $response->status(),
            'response_body' => $response->body(),
            'response_headers' => $response->headers(),
            'auth_header_present' => !empty($config['username']) && !empty($config['password']),
            'url_matches_docs' => strpos($config['api_url'], '/app/ugd/ps/openApi/getMediasByObjectId') !== false
        ];

        Log::error($error, $diagnosticInfo);

        // Проверяем соответствие URL документации
        $expectedUrlPattern = '/app/ugd/ps/openApi/getMediasByObjectId';
        if (strpos($config['api_url'], $expectedUrlPattern) === false) {
            $error .= ". URL не соответствует документации. Ожидается: ...{$expectedUrlPattern}";
        }

        throw new Exception($error . " | URL: " . $config['api_url']);
    }

    /**
     * Обработка RequestException с детальной диагностикой
     */
    protected function handleRequestException(RequestException $e): void
    {
        $error = "HTTP Request Exception: " . $e->getMessage();

        $diagnosticInfo = [
            'exception' => get_class($e),
            'code' => $e->getCode(),
        ];

        if ($e->response) {
            $diagnosticInfo['status'] = $e->response->status();
            $diagnosticInfo['body'] = substr($e->response->body(), 0, 500);
            $diagnosticInfo['headers'] = $e->response->headers();

            $error .= " - Status: " . $e->response->status();
            $error .= " - Body: " . substr($e->response->body(), 0, 200);
        }

        Log::error($error, $diagnosticInfo);
        throw new Exception($error);
    }





    protected function processPhotosForUin(string $uin, array $photos)
    {
        try {
            Log::info("Looking for object with UIN", ['uin' => $uin]);
            $object = ObjectModel::where('uin', $uin)->first();

            if (!$object) {
                Log::warning("Object not found for UIN", ['uin' => $uin]);
                return;
            }

            $processed = 0;
            foreach ($photos as $index => $photoData) {
                Log::debug("Processing photo", ['uin' => $uin, 'index' => $index]);
                if ($this->processPhoto($object, $photoData)) {
                    $processed++;
                }
            }

            if ($processed > 0) {
                Log::info("Photos processed successfully", ['uin' => $uin, 'count' => $processed]);
                $this->sendTelegramMessage(
                    "✅ Обработан объект {$uin}\n📸 Загружено фото: {$processed}"
                );
            } else {
                Log::warning("No photos were processed for UIN", ['uin' => $uin]);
            }

        } catch (Throwable $e) {
            Log::error("Error in processPhotosForUin for UIN {$uin}: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Обработать и сохранить фотографию
     *
     * @param ObjectModel $object
     * @param array $photoData
     * @return bool
     */
    protected function processPhoto(ObjectModel $object, array $photoData): bool
    {
        try {
            Log::debug("Processing photo data", [
                'uin' => $object->uin,
                'loaded' => $photoData['loaded'] ?? null,
                'has_base64' => !empty($photoData['base64'])
            ]);

            // Добавляем проверки на существование ключей
            if (!isset($photoData['loaded']) || !$photoData['loaded'] || empty($photoData['base64'] ?? '')) {
                Log::warning("Фото не загружено или отсутствует base64", [
                    'uin' => $object->uin,
                    'link' => $photoData['link'] ?? 'unknown',
                    'photo_data' => $photoData
                ]);
                return false;
            }

            $takenAt = now();
            $fileName = $this->generateFileName($photoData['link'] ?? 'unknown');
            Log::debug("Generated filename", ['filename' => $fileName]);

            // Декодируем base64 и сохраняем файл
            $imageContent = base64_decode($photoData['base64']);
            if ($imageContent === false) {
                throw new Exception("Ошибка декодирования base64");
            }

            // Сохраняем файл
            $filePath = "objphotos/{$takenAt->format('Y-m-d')}/{$fileName}";
            Log::debug("Saving file", ['path' => $filePath]);

            // Создаем директорию если не существует
            $directory = dirname($filePath);
            if (!Storage::disk('public')->exists($directory)) {
                Storage::disk('public')->makeDirectory($directory);
            }

            Storage::disk('public')->put($filePath, $imageContent);

            // Сохраняем в базу данных
            Photo::create([
                'photo_url' => $fileName,
                'object_uin' => $object->uin,
                'taken_at' => $takenAt
            ]);

            Log::info("Photo saved successfully", ['uin' => $object->uin, 'filename' => $fileName]);
            return true;

        } catch (Throwable $e) {
            Log::error("Error processing photo for {$object->uin}: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'photo_data' => $photoData
            ]);
            return false;
        }
    }

    /**
     * Сгенерировать имя файла
     *
     * @param string $link
     * @return string
     */
    protected function generateFileName(string $link): string
    {
        try {
            // Используем часть link + случайную строку для уникальности
            $linkPart = Str::slug(substr($link, 0, 10));
            return $linkPart . '_' . Str::random(10) . '.jpg';
        } catch (Throwable $e) {
            Log::error("Error in generateFileName: " . $e->getMessage());
            return 'unknown_' . Str::random(10) . '.jpg';
        }
    }

    /**
     * Отправить сообщение в Telegram
     *
     * @param string $message
     * @return void
     */
    protected function sendTelegramMessage(string $message): void
    {
        try {
            $telegramService = new TelegramService();
            $telegramService->sendMessage($message);
        } catch (Throwable $e) {
            Log::error("Ошибка отправки Telegram: " . $e->getMessage());
        }
    }

    /**
     * Обработка неудачного задания
     */
    public function failed(Throwable $exception)
    {
        try {
            Log::error("ProcessUGDPhotosBatch failed", [
                'uins' => $this->uins,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);

            $uinsList = implode(', ', $this->uins);

            // Специальная обработка для ошибок аутентификации
            $errorMessage = $exception->getMessage();
            if (strpos($errorMessage, '401') !== false ||
                strpos($errorMessage, 'аутентификации') !== false ||
                strpos($errorMessage, 'authentication') !== false) {

                $config = config('services.ugd');
                $message = "🚨 КРИТИЧЕСКАЯ ОШИБКА: Проблема с аутентификацией UGD API\n";
                $message .= "📋 УИН: {$uinsList}\n";
                $message .= "❌ Ошибка: " . substr($errorMessage, 0, 200) . "\n";
                $message .= "🔐 URL: " . ($config['api_url'] ?? 'не настроен') . "\n";
                $message .= "👤 User: " . ($config['username'] ?? 'не настроен') . "\n";
                $message .= "⚠️ Проверьте:\n";
                $message .= "   • Соответствие URL документации\n";
                $message .= "   • UGD_API_USERNAME в .env\n";
                $message .= "   • UGD_API_PASSWORD в .env\n";
                $message .= "   • UGD_API_URL в .env\n";
            } else {
                $message = "🚨 Задание ProcessUGDPhotosBatch провалено\n";
                $message .= "📋 УИН: {$uinsList}\n";
                $message .= "❌ Ошибка: " . substr($errorMessage, 0, 200) . "\n";
                $message .= "📊 Тип: " . get_class($exception);
            }

            $this->sendTelegramMessage($message);

        } catch (Throwable $e) {
            Log::error("Error in failed method: " . $e->getMessage());
        }
    }

}