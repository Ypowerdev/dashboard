<?php

namespace App\Console\Commands;

use App\Jobs\ProcessUGDPhotosBatch;
use Illuminate\Console\Command;
use App\Models\ObjectModel;
use App\Jobs\GetPhotoFromUGD;
use App\Services\TelegramService;

class GetPhotosFromUGD extends Command
{
    /**
     * Название и сигнатура консольной команды
     *
     * @var string
     */
    protected $signature = 'photos:get-from-ugd 
                            {uins? : Список УИН через запятую}
                            {--all : Получить фото для всех объектов}
                            {--queue=ugd-photos-batch-debug44 : Название очереди}';

    /**
     * Описание консольной команды
     *
     * @var string
     */
    protected $description = 'Получение фотографий из UGD для объектов';

    /**
     * Выполнение консольной команды
     *
     * @return int
     */
    public function handle()
    {
        // Проверяем наличие кредов UGD
        $config = config('services.ugd');
        if (empty($config['username']) || empty($config['password'])) {
            $this->error('UGD credentials are not configured. Check .env file');
            $this->sendTelegramMessage("❌ UGD credentials are not configured");
            return 1;
        }

        $uins = $this->getUins();

        if (empty($uins)) {
            $this->error('Не указаны УИН объектов для обработки');
            return 1;
        }

        $this->info("Найдено объектов для обработки: " . count($uins));

        // Отправляем стартовое сообщение в Telegram
        $this->sendStartTelegramMessage(count($uins));

        $batches = array_chunk($uins, 5); // Группируем по 5 UIN

        foreach ($batches as $batch) {

//           todo: ТУТ подключенна дебажная история - удалить

            $jobForDebug = new ProcessUGDPhotosBatch($batch);
            $jobForDebug->handle();
//            ProcessUGDPhotosBatch::dispatch($batch);



            $this->line("Добавлен батч в очередь: " . implode(', ', $batch));
        }

        $this->info("Все задания добавлены в очередь '{$this->option('queue')}'");

        return 0;
    }

    /**
     * Получить список УИН для обработки
     *
     * @return array
     */
    private function getUins()
    {
        if ($this->option('all')) {
            // Получаем все УИН из базы данных
            return ObjectModel::pluck('uin')->toArray();
        }

        if ($this->argument('uins')) {
            // Получаем УИН из аргумента команды
            return array_map('trim', explode(',', $this->argument('uins')));
        }

        return [];
    }

    /**
     * Отправить стартовое сообщение в Telegram
     *
     * @param int $objectCount
     * @return void
     */
    private function sendStartTelegramMessage(int $objectCount): void
    {
        try {
            $message = "🚀 Запуск получения фотографий из UGD\n" .
                "📊 Количество объектов: {$objectCount}\n" .
                "⏰ Время начала: " . now()->format('d.m.Y H:i');

            $telegramService = new TelegramService();
            $telegramService->sendMessage($message);
        } catch (\Exception $e) {
            $this->error("Ошибка отправки Telegram: " . $e->getMessage());
        }
    }

    /**
     * Отправить сообщение об ошибке конфигурации в Telegram
     *
     * @param string $message
     * @return void
     */
    private function sendTelegramMessage(string $message): void
    {
        try {
            $telegramService = new TelegramService();
            $telegramService->sendMessage($message);
        } catch (\Exception $e) {
            $this->error("Ошибка отправки Telegram: " . $e->getMessage());
        }
    }
}