<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Photo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Контроллер API для получения фотографий объектов по UIN.
 */
class PhotoController extends Controller
{
    /**
     * Получение списка фотографий, сгруппированных по дате, по UIN объекта.
     *
     * @param string $uin
     * @return JsonResponse
     */
    public function getPhotos($uin): JsonResponse
    {
        try {
            // Валидация UIN
            $this->validateUIN($uin);

            $photos = $this->getPhotosGroupedByDate($uin);
            return response()->json($photos);
        } catch (ValidationException $e) {
            return response()->json([
                'сообщение' => 'Ошибка валидации',
                'ошибка' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            Log::error('Ошибка при получении фотографий', [
                'uin' => $uin,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Ошибка на сервере',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Валидация UIN
     *
     * @param string $uin
     * @throws ValidationException
     */
    protected function validateUIN(string $uin): void
    {
        try {
            $validator = Validator::make(
                ['uin' => $uin],
                [
                    'uin' => [
                        'required',
                        'string',
                        'max:20',
                        'regex:/^[A-Z0-9]{6}-\d{2}-\d{4}-\d{3}$/',
                    ],
                ],
                [
                    'uin.regex' => 'UIN должен быть в формате: XXXXXX-XX-XXXX-XXX (пример: HI0714-10-0001-001)',
                ]
            );

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
        } catch (\Throwable $th) {
            Log::error('ошибка внутри PhotoController/validateUIN()', [
                'exception' => $th,
            ]);
        }
    }

    /**
     * Получение фотографий, сгруппированных по дате
     *
     * @param string $uin
     * @return array
     */
    public function getPhotosGroupedByDate($uin): array
    {
        try {
            $photos = Photo::where('object_uin', $uin)
                ->get()
                ->groupBy(function ($photo) {
                    // Обрезаем время, оставляем только дату
                    return substr($photo->taken_at, 0, 10);
                })
                ->map(function ($group) {
                    return $group->pluck('photo_url')->toArray();
                });

            return $photos->toArray();
        } catch (\Throwable $th) {
            Log::error('ошибка внутри PhotoController/getPhotosGroupedByDate()', [
                'exception' => $th,
            ]);

            return [];
        }
    }
}
