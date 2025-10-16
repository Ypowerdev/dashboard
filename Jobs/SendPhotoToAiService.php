<?php

namespace App\Jobs;

use App\Services\ExternalAIPhotoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Log;

/**
 * Задача на отправку фото объекта в сервис ИИ-анализа
 */
class SendPhotoToAiService implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** Сколько секунд можно выполнять эту джобу */
    public int $timeout = 3600;

    /** Сколько попыток (чтобы не дублировать тяжёлую выгрузку) */
    public int $tries = 3;

    /**
     * Создание задачи.
     * @param string $filePath Абсолютный путь
     * @param string $date
     * @param string $uin
     */
    public function __construct(protected string $filePath, protected string $date, protected string $uin)
    {
        $this->onQueue('ai-photo-upload');
    }


    /**
     * Выполняем задачу с переданными данными.
     * @param ExternalAIPhotoService $externalAIPhotoService
     */
    public function handle(ExternalAIPhotoService $externalAIPhotoService): void
    {
        Log::info('Отправка фото объекта в ExternalAiPhotoService', [
            'filePath' => $this->filePath,
            'date' => $this->date,
            'uin' => $this->uin,
        ]);

        $result = $externalAIPhotoService->uploadPhotoWithValidation($this->filePath, $this->date, $this->uin);

        if ($result === true) {
            Log::info('Фото объекта было успешно отправлено');
        } else {
            Log::info('Фото объекта не было загружено в ИИ-сервис');
        }
    }
}
