<?php

namespace App\Console\Commands;

use App\Models\ObjectModel;
use App\Models\Photo;
use App\Services\ExternalAIPhotoService;
use App\Services\TelegramService;
use DateTime;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Класс ParsePhotos
 *
 * Команда для парсинга и загрузки фотографий с удаленного сервера.
 * Включает детальную проверку существования файлов, логирование ошибок
 * и проверку соответствия БД и фактических файлов.
 *
 * @package App\Console\Commands
 */
class ParsePhotos extends Command
{
    /** @var string Сигнатура команды */
    protected $signature = 'app:photos';

    /** @var string Описание команды */
    protected $description = 'Парсинг и загрузка фотографий с удаленного сервера';

    /** @var string Базовый URL удаленного сервера */
    private const BASE_URL = 'https://stroimprosto.mos.ru/tb/prod/stroimon_back';

    /** @var string Базовый путь для хранения фотографий на сервере */
//    todo: использовать storage_config
    private const STORAGE_BASE_PATH = '/home/objphotos';

    /** @var string Путь для лог-файла */
    private const LOG_PATH = '/var/www/html/storage/logs/photo_parser.log';

    /** @var array Статистика по новым файлам */
    private array $newFilesStats = [];

    /** @var array Список битых изображений */
    private array $brokenImages = [];

    /** @var array Статистика отправки на внешний сервер */
    private array $externalAIServiceUploadStats = [
        'success' => 0,
        'failed' => 0,
        'errors' => []
    ];

    /** @var ExternalAIPhotoService Сервис для работы с внешним API */
    private ExternalAIPhotoService $externalAIServicePhotoService;

    /**
     * Конструктор
     */
    public function __construct(ExternalAIPhotoService $externalAIServicePhotoService)
    {
        parent::__construct();
        $this->externalAIServicePhotoService = $externalAIServicePhotoService;
    }
    /**
     * Основной метод выполнения команды
     *
     * @return void
     */
    public function handle()
    {
        // Проверяем соединение с API здесь, после полной инициализации
        try {
            if (!$this->externalAIServicePhotoService->testConnection()) {
                $this->warn('externalAIService API connection test failed. Check your configuration.');
                Log::warning('externalAIService API connection test failed');
                // Можно спросить пользователя, продолжать ли выполнение
                if (!$this->confirm('Continue without external API?', false)) {
                    $this->error('Aborted by user');
                    return 1;
                }
            }
        } catch (\InvalidArgumentException $e) {
            $this->error('externalAIService API configuration error: ' . $e->getMessage());
            Log::error('externalAIService API configuration error: ' . $e->getMessage());
            $this->warn('Continuing without external API functionality');
        }

        // Остальная логика обработки...
        if (!is_writable(self::STORAGE_BASE_PATH)) {
            $this->error('Storage path is not writable!');
            Log::error('Storage path is not writable', [
                'path' => self::STORAGE_BASE_PATH,
                'perms' => substr(sprintf('%o', fileperms(self::STORAGE_BASE_PATH)), -4)
            ]);
            return 1;
        }

        $this->initLogging();
        $this->info('Начало обработки фотографий объектов');
        Log::info('========== НАЧАЛО РАБОТЫ СКРИПТА ==========');

        $telegramService = new TelegramService();
        $telegramService->sendMessage('Запуск скрипта обновления фотографий на Проде');

        $objects = ObjectModel::all();

        foreach ($objects as $object) {
            $this->warn("Обработка объекта с УИН: {$object->uin}");
            Log::info("Начало обработки объекта", ['uin' => $object->uin]);

            // Инициализируем статистику для текущего UIN
            $this->newFilesStats[$object->uin] = 0;

            try {
                // Проверяем существование папки объекта
                $this->checkObjectDirectory($object->uin);

                $photos = $this->getPhotosList($object->uin);
                if (empty($photos)) {
                    $this->warn("Фотографии не найдены для УИН: {$object->uin}");
                    Log::warning("Фотографии не найдены для объекта", ['uin' => $object->uin]);
                    continue;
                }

                // Детальная проверка БД и файлов перед обработкой
                $this->verifyObjectPhotos($object, $photos);

                foreach ($photos as $date => $photoFiles) {
                    foreach ($photoFiles as $fileName) {
                        $this->processPhoto($object->uin, $date, $fileName);
                    }
                }
            } catch (Exception $e) {
                $this->error("Ошибка обработки УИН {$object->uin}: " . $e->getMessage());
                Log::error("Ошибка обработки объекта", [
                    'uin' => $object->uin,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        $this->info('Установка прав доступа...');
        $this->executePermissionCommands();

        // Выводим статистику
        $this->printStatistics();

        $telegramService->sendMessage('Фотографий обновлены, добавлены');

        $this->info('Обработка завершена!');
        Log::info('========== ЗАВЕРШЕНИЕ РАБОТЫ СКРИПТА ==========');
    }

    /**
     * Инициализация логирования
     */
    private function initLogging(): void
    {
        if (!file_exists(dirname(self::LOG_PATH))) {
            mkdir(dirname(self::LOG_PATH), 0755, true);
        }
    }

    /**
     * Проверяет существование директории объекта
     *
     * @param string $uin УИН объекта
     * @throws Exception
     */
    private function checkObjectDirectory(string $uin): void
    {
        $objectDir = self::STORAGE_BASE_PATH . "/{$uin}";

        if (!file_exists($objectDir)) {
            $this->info("Директория объекта {$uin} не существует, будет создана");
            Log::info("Создание директории объекта", ['uin' => $uin, 'path' => $objectDir]);

            if (!mkdir($objectDir, 0755, true)) {
                $error = "Не удалось создать директорию для объекта {$uin}";
                Log::error($error, ['path' => $objectDir]);
                throw new Exception($error);
            }
        }
    }

    /**
     * Проверяет соответствие фотографий в БД и файловой системе
     *
     * @param ObjectModel $object Объект недвижимости
     * @param array $remotePhotos Массив фотографий с удаленного сервера
     */
    private function verifyObjectPhotos(ObjectModel $object, array $remotePhotos): void
    {
        $this->info("Проверка соответствия БД и файлов для объекта {$object->uin}");
        Log::info("Проверка соответствия БД и файлов", ['uin' => $object->uin]);

        // Получаем все фотографии объекта из БД
        $dbPhotos = Photo::where('object_uin', $object->uin)->get();

        // 1. Проверяем фотографии в БД на наличие файлов
        foreach ($dbPhotos as $dbPhoto) {
            $filePath = $this->getLocalPhotoPath($object->uin, $dbPhoto->taken_at->format('Y-m-d'), $dbPhoto->photo_url);

            if (!file_exists($filePath)) {
                $message = "Фотография есть в БД, но отсутствует на сервере: {$filePath}";
                $this->warn($message);
                Log::warning($message, [
                    'uin' => $object->uin,
                    'photo_id' => $dbPhoto->id,
                    'file_path' => $filePath
                ]);

                // Можно добавить опциональное удаление записи из БД
                $dbPhoto->delete();
            }
        }

        // 2. Проверяем фотографии с удаленного сервера на наличие в БД и локально
        foreach ($remotePhotos as $date => $files) {
            foreach ($files as $file) {
                $filePath = $this->getLocalPhotoPath($object->uin, $date, $file);
                $photoExists = Photo::where('photo_url', $file)
                    ->where('object_uin', $object->uin)
                    ->exists();

                if (file_exists($filePath)) {
                    if (!$photoExists) {
                        $message = "Файл есть на сервере, но отсутствует в БД: {$filePath}. Добавляем запись в БД";
                        $this->warn($message);
                        Log::warning($message, [
                            'uin' => $object->uin,
                            'file_path' => $filePath
                        ]);

                        // Проверяем, является ли файл валидным изображением
                        if (!$this->isValidImage($filePath)) {
                            // Удаляем битый файл
                            unlink($filePath);
                            throw new Exception("Фотография {$filePath} повреждена и не может быть открыта");
                        }

                        // Добавляем отсутствующую запись в БД
                        try {
                            Photo::create([
                                'photo_url' => $file,
                                'object_uin' => $object->uin,
                                'taken_at' => new DateTime($date)
                            ]);
                            $this->info("Запись в БД успешно добавлена для файла: {$file}");
                        } catch (Exception $e) {
                            $this->error("Ошибка при добавлении записи в БД: " . $e->getMessage());
                            Log::error("Ошибка при добавлении записи в БД", [
                                'uin' => $object->uin,
                                'file' => $file,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                } else {
                    $this->info("Файл будет загружен: {$file}");
                }
            }
        }
    }

    /**
     * Получает полный локальный путь к фотографии
     *
     * @param string $uin УИН объекта
     * @param string $date Дата фотографии
     * @param string $fileName Имя файла
     * @return string Полный путь к файлу
     */
    private function getLocalPhotoPath(string $uin, string $date, string $fileName): string
    {
        return sprintf('%s/%s/%s/%s',
            self::STORAGE_BASE_PATH,
            $uin,
            $date,
            $fileName
        );
    }

    /**
     * Получает список фотографий для указанного УИН
     *
     * @param string $uin УИН объекта
     * @return array Массив с датами и именами файлов
     * @throws Exception
     */
    private function getPhotosList(string $uin): array
    {
        $response = Http::get(self::BASE_URL . "/get_photos.php", [
            'uin' => $uin
        ]);

        if (!$response->successful()) {
            $error = "Не удалось получить список фотографий для УИН: {$uin}";
            Log::error($error, ['uin' => $uin, 'status' => $response->status()]);
            throw new Exception($error);
        }

        return $response->json() ?? [];
    }

    /**
     * Обработка одной фотографии
     *
     * @param string $uin УИН объекта
     * @param string $date Дата фотографии
     * @param string $fileName Имя файла фотографии
     * @return void
     */
    private function processPhoto(string $uin, string $date, string $fileName): void
    {
        $photoUrl = self::BASE_URL . "/oks_photos/{$uin}/{$date}/{$fileName}";
        $localPath = $this->getLocalPhotoPath($uin, $date, $fileName);
        $directory = dirname($localPath);

        $this->info("Обработка фотографии: {$fileName}");
        Log::info("Начало обработки фотографии", [
            'uin' => $uin,
            'date' => $date,
            'file' => $fileName,
            'remote_url' => $photoUrl,
            'local_path' => $localPath
        ]);

        try {
            // Проверяем и создаем директорию при необходимости
            if (!file_exists($directory)) {
                $this->info("Создание директории: {$directory}");
                if (!mkdir($directory, 0755, true)) {
                    throw new Exception("Не удалось создать директорию: {$directory}");
                }
            }

            // Проверяем существование файла
            if (file_exists($localPath)) {
                $fileSize = filesize($localPath);
                $this->warn("Файл уже существует: {$localPath} ({$fileSize} bytes)");
                Log::warning("Файл уже существует", [
                    'path' => $localPath,
                    'size' => $fileSize
                ]);

                // Проверяем, является ли файл валидным изображением
                if (!$this->isValidImage($localPath)) {
                    // Удаляем битый файл
                    unlink($localPath);
                    $this->addBrokenImage($photoUrl);
                    throw new Exception("Фотография {$fileName} повреждена и не может быть открыта");
                }

                return;
            }

            // Загружаем файл
            $response = Http::get($photoUrl);
            if (!$response->successful()) {
                throw new Exception("Не удалось скачать фотографию {$fileName}. HTTP статус: {$response->status()}");
            }

            // Сохраняем файл
            $bytesWritten = file_put_contents($localPath, $response->body());
            if ($bytesWritten === false) {
                throw new Exception("Не удалось сохранить файл: {$localPath}");
            }

            // Проверяем, является ли файл валидным изображением
            if (!$this->isValidImage($localPath)) {
                // Удаляем битый файл
                unlink($localPath);
                $this->addBrokenImage($photoUrl);
                throw new Exception("Фотография {$fileName} повреждена и не может быть открыта");
            }

            $this->info("Фотография сохранена: {$localPath} ({$bytesWritten} bytes)");
            Log::info("Фотография успешно сохранена", [
                'path' => $localPath,
                'size' => $bytesWritten
            ]);

            // Увеличиваем счетчик новых файлов для этого UIN
            $this->newFilesStats[$uin]++;

            // Создаем запись в БД
            $photo = Photo::create([
                'photo_url' => $fileName,
                'object_uin' => $uin,
                'taken_at' => new DateTime($date)
            ]);

            $this->info("Запись в БД создана для фотографии: {$fileName}");

            // Отправляем фото на внешний сервер
            $this->sendToExternalAIServiceApi($localPath, $date, $uin);

        } catch (Exception $e) {
            $this->error("Ошибка обработки фотографии {$fileName}: " . $e->getMessage());
            Log::error("Ошибка обработки фотографии", [
                'uin' => $uin,
                'date' => $date,
                'file' => $fileName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Отправляет фотографию на внешний API
     *
     * @param string $localPath Локальный путь к файлу
     * @param string $date Дата съемки
     * @param string $uin УИН объекта
     */
    private function sendToExternalAIServiceApi(string $localPath, string $date, string $uin): void
    {
        try {
            $this->info("Отправка фото на внешний сервер: " . basename($localPath));

            // Проверка существования файла перед отправкой
            if (!file_exists($localPath)) {
                $error = "Файл не существует: {$localPath}";
                $this->error($error);
                Log::error($error);
                $this->externalAIServiceUploadStats['failed']++;
                $this->externalAIServiceUploadStats['errors'][] = $error;
                return;
            }

            // Проверка что файл является изображением
            if (!$this->isValidImage($localPath)) {
                $error = "Файл не является валидным изображением: {$localPath}";
                $this->error($error);
                Log::error($error);
                $this->externalAIServiceUploadStats['failed']++;
                $this->externalAIServiceUploadStats['errors'][] = $error;
                return;
            }

            // Теперь используем UIN вместо projectId
            // ExternalAIPhotoService сам найдет ID через кэш
            $success = $this->externalAIServicePhotoService->uploadPhoto($localPath, $date, $uin);

                if ($success) {
                    $this->info("Фото успешно отправлено на внешний сервер");
                    $this->externalAIServiceUploadStats['success']++;
                } else {
                    $this->warn("Не удалось отправить фото на внешний сервер");
                    $this->externalAIServiceUploadStats['failed']++;
                }

        } catch (Exception $e) {
            $error = "Ошибка при отправке на внешний API: " . $e->getMessage();
            $this->error($error);
            Log::error($error);
            $this->externalAIServiceUploadStats['failed']++;
            $this->externalAIServiceUploadStats['errors'][] = $error;
        }
    }

    /**
     * Установка прав доступа
     */
    private function executePermissionCommands()
    {
        try {
            $this->info('Установка прав 777 на директории');
            $chmodResult = Process::run('sudo chmod -R 777 ' . self::STORAGE_BASE_PATH . '/');

            if (!$chmodResult->successful()) {
                throw new Exception("Ошибка chmod: " . $chmodResult->errorOutput());
            }

            Log::info("Права доступа успешно установлены", ['path' => self::STORAGE_BASE_PATH]);
        } catch (Exception $e) {
            $this->error('Ошибка установки прав: ' . $e->getMessage());
            Log::error("Ошибка установки прав", [
                'path' => self::STORAGE_BASE_PATH,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Проверяет, является ли файл валидным изображением
     *
     * @param string $filePath Путь к файлу
     * @return bool
     */
    private function isValidImage(string $filePath): bool
    {
        try {
            // Пытаемся создать изображение из файла
            $image = @imagecreatefromstring(file_get_contents($filePath));

            if ($image === false) {
                return false;
            }

            imagedestroy($image);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Добавляет битое изображение в список
     *
     * @param string $imageUrl URL битого изображения
     */
    private function addBrokenImage(string $imageUrl): void
    {
        $this->brokenImages[] = $imageUrl;
        Log::warning("Обнаружено битое изображение", ['url' => $imageUrl]);
    }

    /**
     * Выводит статистику выполнения и отправляет ее в Telegram в виде файла
     */
    private function printStatistics(): void
    {
        $this->info("\n=== Статистика обработки фотографий ===");

        // Создаем временный файл для статистики
        $tempFile = tempnam(sys_get_temp_dir(), 'photo_stats_') . '.txt';
        $fileHandle = fopen($tempFile, 'w');

        // Записываем заголовок в файл
        fwrite($fileHandle, "=== Статистика обработки фотографий ===\n");
        fwrite($fileHandle, "Дата: " . now()->format('d.m.Y H:i') . "\n\n");

        // Записываем статистику по новым файлам
        fwrite($fileHandle, "Статистика по новым фотографиям:\n");
        $totalNew = 0;
        foreach ($this->newFilesStats as $uin => $count) {
            if ($count > 0) {
                fwrite($fileHandle, " - {$uin}: добавлено {$count} фото\n");
                $totalNew += $count;
            }
        }

        if ($totalNew === 0) {
            fwrite($fileHandle, "Новых фотографий не добавлено\n");
        } else {
            fwrite($fileHandle, "\nВсего новых фото: {$totalNew}\n");
        }

        // Записываем статистику отправки на внешний сервер
        fwrite($fileHandle, "\nОтправка на внешний сервер:\n");
        fwrite($fileHandle, "✅ Успешно отправлено: {$this->externalAIServiceUploadStats['success']}\n");
        fwrite($fileHandle, "❌ Не отправлено: {$this->externalAIServiceUploadStats['failed']}\n");

        if (!empty($this->externalAIServiceUploadStats['errors'])) {
            fwrite($fileHandle, "\nОшибки отправки:\n");
            foreach ($this->externalAIServiceUploadStats['errors'] as $error) {
                fwrite($fileHandle, " - " . substr($error, 0, 100) . "...\n");
            }
        }

        // Записываем информацию о битых изображениях
        fwrite($fileHandle, "\nБитые изображения:\n");
        if (!empty($this->brokenImages)) {
            fwrite($fileHandle, "Обнаружено: " . count($this->brokenImages) . " битых файлов\n");
            foreach ($this->brokenImages as $url) {
                fwrite($fileHandle, " - " . basename($url) . "\n");
            }
        } else {
            fwrite($fileHandle, "Битые изображения не обнаружены\n");
        }

        fclose($fileHandle);

        // Отправляем файл в Telegram
        $telegramService = new TelegramService();
        $telegramService->sendDocument(
            $tempFile,
            "📅 " . now()->format('d.m.Y') . "\n" .
            "🖼 Фотографии объектов обновлены\n" .
            "✅ Всего новых фото: {$totalNew}\n" .
            "📤 Успешно отправлено: {$this->externalAIServiceUploadStats['success']}\n" .
            "❌ Не отправлено: {$this->externalAIServiceUploadStats['failed']}\n" .
            "🚨 Битых файлов: " . count($this->brokenImages)
        );

        // Удаляем временный файл
        unlink($tempFile);

        // Выводим статистику в консоль
        $this->info("\nДобавлено новых фотографий по UIN:");
        foreach ($this->newFilesStats as $uin => $count) {
            if ($count > 0) {
                $this->info("{$uin} - добавлено новых: {$count} фотографий");
            }
        }

        $this->info("\nОтправка на внешний сервер:");
        $this->info("✅ Успешно отправлено: {$this->externalAIServiceUploadStats['success']}");
        $this->info("❌ Не отправлено: {$this->externalAIServiceUploadStats['failed']}");

        if (!empty($this->brokenImages)) {
            $this->error("\nОбнаружены битые изображения:");
            foreach ($this->brokenImages as $url) {
                $this->error("- {$url}");
            }
        } else {
            $this->info("\nБитые изображения не обнаружены.");
        }

        $this->info("\n=====================================");
    }
}
