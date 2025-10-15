<?php

namespace App\Services;

use App\DTO\TempFile;
use App\Jobs\SendPhotoToAiService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Photo;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class FileFolderService
{
    public static function makeDirIfNotExist(string $diskName, string $dirName = null, $permissions = 0777)
    {
        try {
            $disk = Storage::disk($diskName);

            // Сначала создаем/проверяем корневую папку диска
            $rootPath = $disk->path('');
            if (!is_dir($rootPath)) {
                $disk->makeDirectory('');
                chmod($rootPath, $permissions);
            }

            $targetPath = $rootPath;

            // Затем работаем с указанной папкой (если передана)
            if ($dirName !== null) {
                $disk->makeDirectory($dirName);
                $targetPath = $disk->path($dirName);
                chmod($targetPath, $permissions);
            }

            return $targetPath;

        } catch (\Exception $e) {
            // Логирование ошибки
            Log::error("FileFolderService:: makeDirIfNotExist: $diskName/$dirName - " . $e->getMessage());
            throw $e; // Перебрасываем исключение для обработки на уровне выше
        }
    }

    public static function jsonSampleDateTimeFolderCreateWithLogs($formattedDate)
    {
        $formattedDate = str_replace(':','_',$formattedDate);

        self::makeDirIfNotExist('json_samples_TMP', $formattedDate);
        self::makeDirIfNotExist('json_samples_LOGS', $formattedDate);

        self::makeDirIfNotExist('json_samples_LOGS', $formattedDate.'/ДГП');
        self::makeDirIfNotExist('json_samples_LOGS', $formattedDate.'/ДГС');
        self::makeDirIfNotExist('json_samples_LOGS', $formattedDate.'/ДСТИИ');
        self::makeDirIfNotExist('json_samples_LOGS', $formattedDate.'/СМГ');
    }

    public static function exonSampleDateTimeFolderCreateWithLogs($formattedDate)
    {
        $formattedDate = str_replace(':','_',$formattedDate);

        self::makeDirIfNotExist('exon_samples_TMP', $formattedDate);
        self::makeDirIfNotExist('exon_samples_LOGS', $formattedDate);
    }

    public function createFolderForPhoto(string $uin, string $dateYmd): string
    {
        self::makeDirIfNotExist('photos');
        // inline sanitize
        $sanitize = static fn(string $s) => (function ($s) {
            $s = trim(str_replace(['/', '\\'], '-', $s));
            $s = preg_replace('/[^A-Za-z0-9._-]/u', '-', $s);
            $s = preg_replace('/-+/', '-', $s);
            return $s ?: 'unknown';
        })($s);

        $uinSan  = $sanitize($uin);
        $dateSan = $sanitize($dateYmd);

        $dir = self::makeDirIfNotExist('root_photos',$uinSan . '/' . $dateSan);

        return $dir;
    }

    public function uploadPhoto(string $uin, string $dateYmd, string $bytes, ?string $ext = null): array
    {
        self::makeDirIfNotExist('photos');
        // те же inline sanitize (без доп. методов)
        $sanitize = static fn(string $s) => (function ($s) {
            $s = trim(str_replace(['/', '\\'], '-', $s));
            $s = preg_replace('/[^A-Za-z0-9._-]/u', '-', $s);
            $s = preg_replace('/-+/', '-', $s);
            return $s ?: 'unknown';
        })($s);

        $uinSan  = $sanitize($uin);
        $dateSan = $sanitize($dateYmd);

        // гарантируем каталог (0777)
        $dir = $this->createFolderForPhoto($uinSan, $dateSan);

        // имя и расширение
        $ext = strtolower($ext ?? 'jpg');
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'jpg';
        $filename  = Str::uuid() . '.' . $ext;

        Photo::create([
            'photo_url'  => $filename,
            'object_uin' => $uin,
            'taken_at'   => $dateSan,
        ]);

        // пути
        $absolute = $dir . '/' . $filename;
        $relative = $uinSan . '/' . $dateSan . '/' . $filename;

        // запись файла
        $bytesWritten = @file_put_contents($absolute, $bytes, LOCK_EX);
        if ($bytesWritten === false) {
            throw new \RuntimeException("Не удалось сохранить файл: {$absolute}");
        }
        @chmod($absolute, 0644);

        // валидация изображения (кроме heic/heif)
        if (!in_array($ext, ['heic','heif'], true)) {
            $im = @imagecreatefromstring($bytes);
            if ($im === false) {
                @unlink($absolute);
                throw new \RuntimeException('Файл повреждён или не является изображением');
            }
            imagedestroy($im);
        }

        return [
            'absolute_path' => $absolute,
            'relative_path' => $relative,
            'filename'      => $filename,
        ];
    }

    public function resizeImageIfNeeded(string $bytes, string $ext): string
    {
        // Максимальный размер файла в байтах (300 КБ)
        // Если файл меньше или равен 300 КБ, не ресайзим
        $maxSize = 300;
        $maxSizeBytes = 300 * 1024;

        // Проверяем размер файла в байтах
        $fileSizeBytes = strlen($bytes);
        $fileSizeKb = $fileSizeBytes / 1024;

        // Если файл меньше или равен 300 КБ, не ресайзим
        if ($fileSizeKb <= $maxSize) {
            return $bytes;
        }

        try {
            $image = @imagecreatefromstring($bytes);
            if (!$image) {
                return $bytes; // Не изображение, возвращаем как есть
            }

            $width = imagesx($image);
            $height = imagesy($image);

            // Вычисляем целевой коэффициент масштабирования
            // Формула: target_size / original_size = scale_factor^2
            // Поэтому: scale_factor = sqrt(target_size / original_size)
            $targetRatio = $maxSizeBytes / $fileSizeBytes;
            $scaleFactor = sqrt($targetRatio) * 0.85; // Делаем немного меньше для гарантии

            // Ограничиваем коэффициент разумными пределами (минимум 10% от оригинала)
            $scaleFactor = max(0.1, min(1.0, $scaleFactor));

            $newWidth = max(1, (int)($width * $scaleFactor));
            $newHeight = max(1, (int)($height * $scaleFactor));

            $newImage = imagecreatetruecolor($newWidth, $newHeight);

            // Сохраняем прозрачность для PNG
            if ($ext === 'png') {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                // Создаем прозрачный фон
                $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
                imagefill($newImage, 0, 0, $transparent);
            } else {
                // Для JPG создаем белый фон
                $white = imagecolorallocate($newImage, 255, 255, 255);
                imagefill($newImage, 0, 0, $white);
            }

            imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            // Сохраняем в нужном формате
            ob_start();
            if ($ext === 'png') {
                imagepng($newImage, null, 9); // Максимальное сжатие для PNG
            } else {
                imagejpeg($newImage, null, 85); // 85% качество для JPG
            }
            $resizedBytes = ob_get_contents();
            ob_end_clean();

            imagedestroy($image);
            imagedestroy($newImage);

            // Проверяем, что ресайз действительно уменьшил размер
            if (strlen($resizedBytes) < $fileSizeBytes) {
                return $resizedBytes;
            }
        } catch (\Exception $e) {
            // В случае ошибки возвращаем оригинальные байты
            Log::warning('Image resize failed', ['error' => $e->getMessage()]);
        }

        return $bytes;
    }

    public function processSinglePhoto($raw, string $uin, string $date): TempFile|null
    {
        $tmp = null;

        try {
            self::makeDirIfNotExist('tmp');

            /** @var \App\Services\UploadTempService $tmpService */
            $tmpService = app(\App\Services\UploadTempService::class, [
                'dir'  => 'photos',
            ]);
            /** @var \App\Services\ImageService $imgService */
            $imgService = app(\App\Services\ImageService::class);

            // 1) Забираем файл из Livewire в свой tmp (стримово, атомарно)
            $tmp = $tmpService->takeOwnership($raw);

            // 2) Ресайз (по пути), получаем бинарь
            $bytes = @file_get_contents($raw->getRealPath());
            $bytes = $imgService->resizeImageIfNeeded($bytes, $tmp->extension);

            // 3) Финальная запись туда, где ты хранишь (как и раньше)
            $uploadResult = $this->uploadPhoto($uin, $date, $bytes, $tmp->extension);

            if (isset($uploadResult['absolute_path'])) {
                SendPhotoToAiService::dispatch($uploadResult['absolute_path'], $date, $uin);
            }

            return $tmp;
        } catch (\Throwable $e) {
            Log::error('FileFolderService:: Возникла ошибка в методе processSinglePhoto', [
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    public static function isFileExist(string $diskName, string $filename)
    {
        try {
            $disk = Storage::disk($diskName);

            // Проверяем доступность диска
            if (!$disk) {
                Log::debug("isFileExist:: FALSE (disk '{$diskName}' not found)");
                return false;
            }

            $exists = $disk->exists($filename);

            // Логируем с дополнительной информацией
            $status = $exists ? 'FOUND' : 'NOT FOUND';
            Log::debug("isFileExist:: {$status} - disk: {$diskName}, file: {$filename}");

            return $exists;
        } catch (\Exception $e) {
            Log::debug("isFileExist:: ERROR - disk: {$diskName}, file: {$filename}, error: " . $e->getMessage());
            return false;
        }
    }

}

