<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogRequest;
use App\Models\ObjectList;
use App\Repositories\Interfaces\ObjectModelRepositoryInterface;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер CatalogController отвечает за работу с каталогом объектов.
 *
 * Этот контроллер предоставляет API для получения списка объектов с пагинацией и дополнительной информацией.
 */
class CatalogController extends Controller
{

    private ObjectModelRepositoryInterface $objectModelRepository;

    public function __construct(ObjectModelRepositoryInterface $objectModelRepository)
    {
        $this->objectModelRepository = $objectModelRepository;
    }

    /**
     * Метод для получения списка объектов с пагинацией.
     *
     * Возвращает JSON-ответ с данными об объектах, включая связанные сущности (OIV, регион, источник финансирования и т.д.).
     * Поддерживает пагинацию: по умолчанию возвращает 40 объектов на страницу.
     *
     * @param CatalogRequest $request Входящий HTTP-запрос. Может содержать параметр `page` для указания номера страницы.
     * * @return JsonResponse JSON-ответ с данными об объектах и пагинацией.
     */
    public function index(CatalogRequest $request): JsonResponse
    {
        try{
            $validatedData = $request->validated();

            // Получаем подсчеты для всех возможных значений фильтров
            $objects = $this->objectModelRepository->getCatalogData($validatedData);
            $filterCounts = $this->objectModelRepository->getFilterCountsForCatalog($validatedData);
            $list = ObjectList::where('id', $request->object_list_id)->first();

            return response()->json([
                'list' => $list,
                'objects' => ['data' => $objects],
                'filter_counts' => $filterCounts
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
            'error' => 'Произошла ошибка при получении данных каталога',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
            ], 500);
        }
    }
}
