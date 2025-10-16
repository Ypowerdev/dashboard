<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Services\ExternalAIPhotoService;
use Illuminate\Console\Command;

class UploadAllExistingPhotos extends Command
{
    protected $signature = 'photos:upload-all-existing';
    protected $description = 'Загрузка всех существующих фотографий на внешний сервер';

    //    todo: использовать storage_config
    private const STORAGE_BASE_PATH = '/home/objphotos';

    private ExternalAIPhotoService $externalAIPhotoService;

    public function __construct(ExternalAIPhotoService $externalAIPhotoService)
    {
        parent::__construct();
        $this->externalAIPhotoService = $externalAIPhotoService;
    }

    public function handle()
    {
        $this->info('Начало загрузки всех существующих фотографий...');

        // Проверка соединения с API
        if (!$this->externalAIPhotoService->testConnection()) {
            $this->error('Нет соединения с внешним API. Проверьте настройки.');
            return 1;
        }

        $photos = Photo::with('object')->get();
        $total = $photos->count();
        $processed = 0;
        $success = 0;
        $failed = 0;

        $this->output->progressStart($total);

        foreach ($photos as $photo) {
            try {
                $localPath = self::STORAGE_BASE_PATH . "/{$photo->object_uin}/{$photo->taken_at->format('Y-m-d')}/{$photo->photo_url}";

                if (!file_exists($localPath)) {
                    $this->warn("Файл не найден: {$localPath}");
                    $failed++;
                    continue;
                }

                // Проверка валидности изображения
                if (!$this->isValidImage($localPath)) {
                    $this->warn("Файл не является валидным изображением: {$localPath}");
                    $failed++;
                    continue;
                }

                // Теперь передаем UIN вместо projectId
                // Сервис сам найдет ID через кэш
                $uploadSuccess = $this->externalAIPhotoService->uploadPhoto(
                    $localPath,
                    $photo->taken_at->format('Y-m-d'), // Форматируем дату правильно
                    $photo->object_uin // Передаем UIN вместо projectId
                );

                if ($uploadSuccess) {
                    $success++;
                } else {
                    $failed++;
                    $this->warn("Не удалось загрузить фото: {$photo->photo_url}");
                }

            } catch (\Exception $e) {
                $this->error("Ошибка обработки фото {$photo->id}: " . $e->getMessage());
                $failed++;
            }

            $processed++;
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->info("\nЗагрузка завершена!");
        $this->info("Всего обработано: {$processed}");
        $this->info("Успешно: {$success}");
        $this->info("Не удалось: {$failed}");

        // Статистика кэша для отладки
        $cacheStats = $this->externalAIPhotoService->getCacheStats();
        $this->info("Кэш проектов: {$cacheStats['cached_items']} записей");

        return 0;
    }

    /**
     * Проверка валидности изображения
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
}
