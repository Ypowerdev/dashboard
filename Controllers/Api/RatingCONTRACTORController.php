<?php

namespace App\Http\Controllers\Api;

use App\Models\CultureManufacture;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\ObjectModel;
use App\Models\Contractor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class RatingCONTRACTORController extends Controller
{

    /**
     * Получаем рейтинги подрядчиков для фронта
     * @param Request $request
     * @return JsonResponse
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function contractors(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            // Эдакое кэшовое проксирование $user->access_to_raiting
            $raitingAccess = cache()->get('cacheRaitingAccess_'.request()->bearerToken());

            if($raitingAccess !== true){
                return response()->json([
                    'access' => false,
                    'message' => 'Вам закрыт доступ на эту страницу',
                ], 401);
            }

            // Из полученных годов формируем даты периодов
            $yearsArr = $request->has('years') ? array_map('intval', (array)$request->get('years')) : [];

            // Получаем переданные фильтры, если они есть
            $filters = $this->getFiltersForContractors($request);

            $objects = $this->contractorData($yearsArr, $filters);

            return response()->json($objects, 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     *
     * @return array
     */
    private function contractorData(array $yearsArr, array $filters):array
    {
        try {
            // Загружаем только необходимых "Подрядчиков"
            $contractorArr = Contractor::select(['id', 'inn', 'total_cost_mlrd_rub', 'name'])
                ->when(isset($filters['contractSizes']) && !empty($filters['contractSizes']), function($query) use ($filters) {
                    $query->where(function($subQuery) use ($filters) {
                        foreach ($filters['contractSizes'] as $size) {
                            switch ($size) {
                                case 1:
                                    $subQuery->orWhere('total_cost_mlrd_rub', '>=', 40);
                                    break;
                                case 2:
                                    $subQuery->orWhereBetween('total_cost_mlrd_rub', [10, 40]);
                                    break;
                                case 3:
                                    $subQuery->orWhere('total_cost_mlrd_rub', '<=', 10);
                                    break;
                            }
                        }
                    });
                })
                ->get()
                ->keyBy('id')
                ->toArray();

            $objectWithCultureManufactureArr = CultureManufacture::pluck('object_id')->flip()->toArray();

            $query = ObjectModel::with([
                'etapiRealizaciiLibraryWithPivot',
                'fno_level',
            ]);

    //      Если фильтр по годам, то в выборку идут лишь те, что по годам
            if(count($yearsArr) > 0){
                $query->where(function ($query) use ($yearsArr) {
                    foreach ($yearsArr as $year) {
                        $query->orWhere('aip_year', $year);
                    }
                });
            }
    //      Если в фильтре АИП
            if (isset($filters['aip']) && $filters['aip'] === true) {
                $query->where('aip_flag', true);
            }

    // Применяем фильтры по категориям
            if (isset($filters['category'])) {
                switch ($filters['category']) {
                    case 'civilians':
                        // Для гражданских: (нежилые ИЛИ жилые) И БЕЗ реновации
                        $query->where(function($q) {
                            $q->whereHas('fno_level', function($subQuery) {
                                $subQuery->where('lvl1_name', 'Нежилые')
                                    ->orWhere('lvl1_name', 'Жилые');
                            })
                                ->where('renovation', false); // Без реновации
                        });
                        break;

                    case 'underground':
                        // Для метро
                        $query->whereHas('fno_level', function($subQuery) {
                            $subQuery->where('lvl1_name', 'Метро');
                        });
                        break;

                    case 'roads':
                        // Для дорог
                        $query->whereHas('fno_level', function($subQuery) {
                            $subQuery->where('lvl1_name', 'Дороги');
                        });
                        break;
                    case 'renovation':
                        $query->where('renovation', true);
                        break;
                }
            }

        $objects = $query->get(['id', 'fno_type_code', 'contractor_id', 'aip_flag', 'planned_commissioning_directive_date', 'is_object_directive', 'forecasted_commissioning_date', 'contract_commissioning_date']);

            $groupedObjects = $objects->groupBy('contractor_id');

            foreach ($contractorArr as $key => &$oneContractor){

                $totalPlannedToIntroduce = 0;
                $introducedWithViolations = 0;
                $introducedWithViolationsDelta = 0;
                $withoutViolations = 0;
                $withViolations = 0;
                $remarks = 0;
                $problematicShare = 0; // проблемные объекты - в ШТУКАХ

    // ПОЯСНЕНИЕ ОТ АЛИНЫ: ( личка в ТГ от 08.04.2025 )
    //  всего запланировано для ввода - тут смотрим по плановому вводу по директивному графику (это дата, которая как ни крути будет заполнена)
    //
    // введено — смотрим, чтобы была заполнена фактическая дата получения РВ (на статус завязываться рискованно, но в целом можно попробовать, я вообще хочу в конце концов прийти к тому, чтобы статус рассчитывать по КТ)
    //
    // реализуется без нарушения сроков — тут берем объекты без фактической даты РВ и смотрим на два поля: плановый ввод по договору и прогнозируемый срок ввода, и для данной категории берем те объекты, у которых прогнозируемый срок ввода МЕНЬШЕ или равен прогнозируемому сроку ввода
    //
    // реализуются с нарушением сроков — тут берем объекты без фактической даты РВ и смотрим те же два поля, но для данной категории берем те объекты, у которых прогнозируемый срок ввода БОЛЬШЕ прогнозируемого срока ввода

                // Получаем только объекты для текущего contractor_id
                if (isset($groupedObjects[$key])) {
                    foreach ($groupedObjects[$key] as $object) {
                        $objectHasRB = false;
    //                  Всего запланировано для ввода
                        $totalPlannedToIntroduce++;

    //                  Введено (с нарушениями) - два показателя
                        if($object->etapiRealizaciiLibraryWithPivot)
                        {
                            foreach ($object->etapiRealizaciiLibraryWithPivot as $oneEtap){
                                if($oneEtap->name === 'РВ'){
                                    if($oneEtap->pivot->fact_finish_date!==null){
                                        // объект введен ВСЕГО
                                        $introducedWithViolations++;
                                        $objectHasRB = true;
    //                                    Если введен с просрочкой
                                        if($oneEtap->pivot->fact_finish_date > $oneEtap->pivot->plan_finish_date){
                                            $introducedWithViolationsDelta++;
                                        }
                                    }
                                }
                            }
                        }

    //                  Реализуются без нарушения сроков
                        if(($objectHasRB === false && $object->forecasted_commissioning_date)
                            && ($object->contract_commissioning_date)
                            && $object->forecasted_commissioning_date <= $object->contract_commissioning_date){
                            $withoutViolations++;
                        }

    //                  Реализуются с нарушениями сроков
                        if(($objectHasRB === false && $object->forecasted_commissioning_date)
                            && ($object->contract_commissioning_date)
                            && $object->forecasted_commissioning_date > $object->contract_commissioning_date){
                            $withViolations++;
                        }
                    }
                }

                if($totalPlannedToIntroduce>0){
                    $dolyaProblemObjects = ($introducedWithViolationsDelta + $withViolations) / $totalPlannedToIntroduce * 100;
                }
                else{
                    $dolyaProblemObjects = 0;
                }

                $oneContractor['id'] = $key;
                $oneContractor['totalCost'] = $oneContractor['total_cost_mlrd_rub'];
                $oneContractor['totalPlannedToIntroduce'] = $totalPlannedToIntroduce;
                $oneContractor['introducedALL'] = $introducedWithViolations;
                $oneContractor['introducedWithViolations'] =$introducedWithViolationsDelta;
                $oneContractor['pendingWithoutViolations'] = $withoutViolations;
                $oneContractor['pendingWithViolations'] = $withViolations;
                $oneContractor['pendingWithRemarkCultureManufacture'] = $remarks;
                $oneContractor['problematicShare'] = $dolyaProblemObjects;

    //            Убираем из списка ПОДРЯДЧИКА, если он без объектов
                if($totalPlannedToIntroduce === 0){
                    unset($contractorArr[$key]);
                }

                unset($oneContractor);
            }


            return array_values($contractorArr);
        } catch (\Throwable $th) {
            Log::error('ошибка внутри RatingCONTRACTORController/contractorData()', [
                'exception' => $th,
            ]);

            return [];
        }
    }

    /**
     * Формируем фильтр из полученных параметров
     * @param Request $request
     * @return array
     */
    private function getFiltersForContractors(Request $request): array
    {
        try {
            $contractSizesArr = $request->has('contractSizes') ? array_map('intval', (array)$request->get('contractSizes')) : [1,2,3];

            $aip = $request->has('aip') && $request->get('aip') == 'yes';
            $category = $request->has('category') ? $request->get('category') : false;
            $filters = [];

            $filters['contractSizes'] = $contractSizesArr;

            if ($aip) {
                $filters['aip'] = true;
            }

            if ($category) {
                $filters['category'] = $category;
            }

            return $filters;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри RatingCONTRACTORController/getFiltersForContractors()', [
                'exception' => $th,
            ]);

            return [];
        }
    }

}
