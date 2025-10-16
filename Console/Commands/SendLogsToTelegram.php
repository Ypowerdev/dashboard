<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Команда для отправки всех логов приложения через Telegram
 */
class SendLogsToTelegram extends Command
{
    /**
     * Название и сигнатура консольной команды.
     *
     * @var string
     */
    protected $signature = 'telegram:send-logs';

    /**
     * Описание консольной команды.
     *
     * @var string
     */
    protected $description = 'Отправляет все логи из storage/logs zip-архивом через Telegram';

    /**
     * Выполнение консольной команды.
     *
     * @param TelegramService Класс-сервис для работы с рассылкой сообщений в Telegram
     */
    public function handle(TelegramService $telegramService)
    {
        $logsPath = storage_path('logs');
        $zipPath = storage_path('app/logs_archive.zip');

        // Получаем список файлов логов
        $files = File::files($logsPath);

        // Проверяем, есть ли файлы для архивирования
        if (count($files) === 0) {
            $telegramService->sendMessage('Нет файлов логов для отправки.');
            return;
        }

        // Создаем архив
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            // Добавляем все файлы из папки logs в архив
            foreach ($files as $file) {
                $zip->addFile($file->getPathname(), $file->getFilename());
            }

            $zip->close();

            // Проверяем размер архива (не более 50MB)
            $maxSize = 50 * 1024 * 1024;
            if (File::size($zipPath) > $maxSize) {
                File::delete($zipPath);
                $telegramService->sendMessage('Размер архива с логами превышает 50MB и не может быть отправлен.');
                return;
            }

            // Отправляем архив через Telegram
            $telegramService->sendDocument(
                $zipPath,
                'Архив логов от ' . config('app.name')
            );

            // Удаляем временный архив
            File::delete($zipPath);
        } else {
            $telegramService->sendMessage('Ошибка при создании архива логов.');
        }
    }
}
