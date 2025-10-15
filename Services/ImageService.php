<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

final class ImageService
{
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
}
