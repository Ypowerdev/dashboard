<?php

namespace App\Http\Controllers\Api;

use App\Helpers\JsonHelper;
use App\Models\CultureManufacture;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\ObjectModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class RatingPODVEDController extends Controller
{

    /**
     * Получаем рейтинги подведов для фронта
     * @param Request $request
     * @return JsonResponse
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function podveds(Request $request): \Illuminate\Http\JsonResponse
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

            $yearsArr = $request->has('years')
                ? array_map('intval', (array)$request->get('years'))
                : [];

            $filters = $this->getFiltersForPodveds($request);
            $objects = $this->podvedData($yearsArr, $filters);

            return response()->json($objects, 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    private function podvedData(array $yearsArr, array $filters): array
    {
        try {
            // Получаем все подведомственные организации из JSON
            $allPodvedsArr = JsonHelper::getPodvedArrayWithOIV();

            // Собираем все ИНН подведомственных организаций
            $podvedINNs = [];
            foreach ($allPodvedsArr as $oiv) {
                foreach ($oiv['podved_org'] as $podved) {
                    $podvedINNs[] = $podved['podved_inn'];
                }
            }
            $podvedINNs = array_unique($podvedINNs);

            // Если нет ИНН - возвращаем пустой результат
            if (empty($podvedINNs)) {
                return [];
            }

            // return $podvedINNs;

            $objectWithCultureManufactureArr = CultureManufacture::pluck('object_id')->toArray();

            $query = ObjectModel::with([
                'etapiRealizaciiLibraryWithPivot',
                'customer',
                'developer',
                'contractor',
            ]);

            // Фильтр по годам
            if (!empty($yearsArr)) {
                $query->where(function ($q) use ($yearsArr) {
                    foreach ($yearsArr as $year) {
                        $q->orWhereYear('planned_commissioning_directive_date', $year);
                    }
                });
            }

            // dd([
            //     '$filters[aip_flag]' => $filters['aip_flag'],
            // ]);
            // Фильтр АИП
            if (isset($filters['aip_flag']) && $filters['aip_flag'] === true) {
                // dd(true);
                $query->where('aip_flag', true);
            }

        if (isset($filters['is_object_directive']) && $filters['is_object_directive'] === true) {
            $query->where('is_object_directive', true);
        }

            if (isset($filters['aip_years'])) {
                $query->whereIn('aip_year', $filters['aip_years']);
            }

            // Фильтр по принадлежности к ПОДВЕДам
            $query->where(function ($q) use ($podvedINNs) {
                $q->whereHas('customer', function ($sub) use ($podvedINNs) {
                    $sub->whereIn('inn', $podvedINNs);
                })
                ->orWhereHas('developer', function ($sub) use ($podvedINNs) {
                    $sub->whereIn('inn', $podvedINNs);
                })
                ->orWhereHas('contractor', function ($sub) use ($podvedINNs) {
                    $sub->whereIn('inn', $podvedINNs);
                });
            });

            $objects = $query->get();

            // Группируем объекты по ИНН подведомственных организаций
            $groupedByPodved = [];
            foreach ($objects as $object) {
                $inns = [];

                // Проверяем все связи объекта
                if ($object->customer && in_array($object->customer->inn, $podvedINNs)) {
                    $inns[] = $object->customer->inn;
                }
                if ($object->developer && in_array($object->developer->inn, $podvedINNs)) {
                    $inns[] = $object->developer->inn;
                }
                if ($object->contractor && in_array($object->contractor->inn, $podvedINNs)) {
                    $inns[] = $object->contractor->inn;
                }

                // Добавляем объект ко всем связанным ИНН
                foreach (array_unique($inns) as $inn) {
                    if (!isset($groupedByPodved[$inn])) {
                        $groupedByPodved[$inn] = [];
                    }
                    $groupedByPodved[$inn][] = $object;
                }
            }

            // Создаем список подведомственных организаций для результата
            $podvedsList = [];
            foreach ($allPodvedsArr as $oiv) {
                foreach ($oiv['podved_org'] as $podved) {
                    $inn = $podved['podved_inn'];
                    $podvedsList[$inn] = [
                        'id' => $inn,
                        'name' => $podved['name'] ?? 'Неизвестно',
                        'short_name' => $podved['short_name'] ?? 'Неизвестно',
                        'oiv_name' => $oiv['name'] ?? '',
                        'oiv_en_short_name' => $oiv['en_short_name'] ?? '',
                        'oiv_id' => $oiv['id'] ?? null,
                    ];
                }
            }

            $result = [];
            foreach ($podvedsList as $inn => $podvedInfo) {
                $totalPlannedToIntroduce = 0;
                $introducedALL = 0;
                $introducedWithViolations = 0;
                $pendingWithoutViolations = 0;
                $pendingWithViolations = 0;
                $remarks = 0;
                $problematicShare = 0;

                if (isset($groupedByPodved[$inn])) {
                    foreach ($groupedByPodved[$inn] as $object) {
                        $objectHasRB = false;
                        $problemDetected = false;
                        $totalPlannedToIntroduce++;

                        // Проверяем этапы реализации
                        if ($object->etapiRealizaciiLibraryWithPivot) {
                            foreach ($object->etapiRealizaciiLibraryWithPivot as $etap) {
                                if ($etap->name === 'РВ' && $etap->pivot->fact_finish_date) {
                                    $introducedALL++;
                                    $objectHasRB = true;

                                    // Проверка на нарушение срока
                                    if ($etap->pivot->fact_finish_date > $etap->pivot->plan_finish_date) {
                                        $introducedWithViolations++;
                                    }
                                }
                            }
                        }

                        // Если объект еще не введен
                        if (!$objectHasRB) {
                            // Проверяем нарушение сроков по прогнозу и договору
                            if ($object->forecasted_commissioning_date && $object->contract_commissioning_date) {
                                if ($object->forecasted_commissioning_date > $object->contract_commissioning_date) {
                                    $pendingWithViolations++;
                                    $problemDetected = true;
                                } else {
                                    $pendingWithoutViolations++;
                                }
                            }

                            // Проверяем замечания по культуре производства
                            if (in_array($object->id, $objectWithCultureManufactureArr)) {
                                $remarks++;
                                $problemDetected = true;
                            }

                            if ($problemDetected) {
                                $problematicShare++;
                            }
                        }
                    }
                }

                // Рассчитываем долю проблемных объектов
                $problematicPercent = $totalPlannedToIntroduce > 0
                    ? round(($pendingWithViolations + $introducedWithViolations) / $totalPlannedToIntroduce * 100)
                    : 0;

                if ($problematicPercent > 100) {
                    $problematicPercent = 100;
                }

                if ($problematicPercent < 0) {
                    $problematicPercent = 0;
                }

                $result[] = [
                    'id' => $podvedInfo['id'],
                    'name' => $podvedInfo['name'],
                    'short_name' => $podvedInfo['short_name'],
                    'oiv_name' => $podvedInfo['oiv_name'],
                    'oiv_en_short_name' => $podvedInfo['oiv_en_short_name'],
                    'oiv_id' => $podvedInfo['oiv_id'],
                    'totalPlannedToIntroduce' => $totalPlannedToIntroduce,
                    'introducedALL' => $introducedALL,
                    'introducedWithViolations' => $introducedWithViolations,
                    'pendingWithoutViolations' => $pendingWithoutViolations,
                    'pendingWithViolations' => $pendingWithViolations,
                    'pendingWithRemarkCultureManufacture' => $remarks,
                    'problematicShare' => $problematicPercent,
                ];
            }

            return array_values($result);
        } catch (\Throwable $th) {
            Log::error('ошибка внутри RatingPODVEDController/podvedData()', [
                'exception' => $th,
            ]);

            return [];
        }
    }

    private function getFiltersForPodveds(Request $request): array
    {
        try {
            return [
                'aip_years' => $request->has('aip_years')
                    ? array_map('intval', (array)$request->get('aip_years'))
                    : null,
                'aip_flag' => $request->has('aip_flag') && $request->get('aip_flag') == 'true',
                'is_object_directive' => $request->has('is_object_directive') && $request->get('is_object_directive') == 'true',
                'category' => $request->get('category', false),
                'contractSizes' => $request->has('contractSizes')
                    ? array_map('intval', (array)$request->get('contractSizes'))
                    : [1, 2, 3]
            ];
        } catch (\Throwable $th) {
            Log::error('ошибка внутри RatingPODVEDController/getFiltersForPodveds()', [
                'exception' => $th,
            ]);

            return [];
        }
    }

}
