<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CultureManufacture;
use App\Models\Library\Oiv;
use App\Models\ObjectModel;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RatingOIVController extends Controller
{
    /**
     * Получаем рейтинги ОИВ для фронта
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function oivs(Request $request): \Illuminate\Http\JsonResponse
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

            $commissioning_years = (int)$request->commissioning_years;
            $aip = (bool)($request->aip === "true"); // true | false
            $is_object_directive = (bool)($request->is_object_directive === "true"); // true | false

            return response()->json($this->oivData($commissioning_years, $aip, $is_object_directive), 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * @param $periodDate
     * @param $aip
     * @return array
     */
    private function oivData($commissioning_years, $aip, $is_object_directive): array
    {
        try {
            $oivArr = Oiv::select(['id', 'name', 'en_short_name'])->get()->keyBy('id')->toArray();
            $objectWithCultureManufactureArr = CultureManufacture::pluck('object_id')->flip()->toArray();

            $query = ObjectModel::with([
                'etapiRealizaciiLibraryWithPivot',
            ]);

            //      Если в фильтре АИП
            if ($aip === true) {
                $query->where('aip_flag', true);
            }

        if ($is_object_directive === true) {
            $query->where('is_object_directive', true);
            $query->whereYear('planned_commissioning_directive_date', $commissioning_years);
        } else {
            $query->whereYear('forecasted_commissioning_date', $commissioning_years);
        }

        $objects = $query->get(['id','uin', 'fno_type_code', 'oiv_id', 'aip_flag', 'planned_commissioning_directive_date', 'is_object_directive', 'forecasted_commissioning_date', 'contract_commissioning_date','year_of_introduction']);

            $groupedObjects = $objects->groupBy('oiv_id');
            $result = [];

            foreach ($oivArr as $key => &$oneOIV) {

                $totalPlannedToIntroduce = 0;
                $introducedALL = 0;
                $pendingWithoutViolations = 0;
                $pendingWithViolations = 0;
                $pendingWithRemarkCultureManufacture = 0;
                $problemsObjectsCount = 0;

                // ПОЯСНЕНИЕ ОТ АЛИНЫ: ( личка в ТГ от 08.04.2025 )
                //  всего запланировано для ввода - тут смотрим по плановому вводу по year_of_introduction
                // (это дата, которая как ни крути будет заполнена)
                //
                // введено — смотрим, чтобы была заполнена фактическая дата получения РВ (на статус завязываться рискованно,
                // но в целом можно попробовать, я вообще хочу в конце концов прийти к тому, чтобы статус рассчитывать по КТ)
                //
                // реализуется без нарушения сроков — тут берем объекты без фактической даты РВ и смотрим на два поля:
                // плановый ввод по договору и прогнозируемый срок ввода, и для данной категории берем те объекты,
                // у которых прогнозируемый срок ввода МЕНЬШЕ или равен прогнозируемому сроку ввода
                //
                // реализуются с нарушением сроков — тут берем объекты без фактической даты РВ и смотрим те же два поля,
                // но для данной категории берем те объекты, у которых прогнозируемый срок ввода БОЛЬШЕ прогнозируемого срока ввода

                // Получаем только объекты для текущего oiv_id
                if (isset($groupedObjects[$key])) {
                    foreach ($groupedObjects[$key] as $object) {
                        $objectHasRB = false;
                        $problemDetected = false;
                        //                  Всего запланировано для ввода
                        $totalPlannedToIntroduce++;

                        if ($object->etapiRealizaciiLibraryWithPivot) {
                            foreach ($object->etapiRealizaciiLibraryWithPivot as $oneEtap) {
                                if ($oneEtap->name === 'РВ' && $oneEtap->pivot->fact_finish_date !== null) {
                                    $introducedALL++;
                                    $objectHasRB = true;
                                }
                            }
                        }

                        //                  Реализуются без нарушения сроков
                        if (($objectHasRB === false && $object->forecasted_commissioning_date)
                            && ($object->contract_commissioning_date)
                            && $object->forecasted_commissioning_date <= $object->contract_commissioning_date) {
                            $pendingWithoutViolations++;
                        }

                        //                  Реализуются с нарушениями сроков
                        if (($objectHasRB === false && $object->forecasted_commissioning_date)
                            && ($object->contract_commissioning_date)
                            && $object->forecasted_commissioning_date > $object->contract_commissioning_date) {
                            $pendingWithViolations++;
                            $problemDetected = true;
                        }

                        //                  С замечаниями по культуре производства
                        if (isset($objectWithCultureManufactureArr[$object->id])) {
                            $pendingWithRemarkCultureManufacture++;
                            $problemDetected = true;
                        }

                        if ($problemDetected === true) {
                            $problemsObjectsCount++;
                        }
                    }
                }

                $result[$oneOIV['en_short_name']] = [
                    'id' => $key,
                    'oiv' => $oneOIV['en_short_name'] ?? '',
                    'name' => $oneOIV['name'] ?? '',
                    'totalPlannedToIntroduce' => $totalPlannedToIntroduce,
                    'introducedALL' => $introducedALL,
                    'pendingWithoutViolations' => $pendingWithoutViolations,
                    'pendingWithViolations' => $pendingWithViolations,
                    'productionProblemShare' => [  // Если в знаменатель идет НОЛЬ, то НОЛЬ идет и в значение
                        $totalPlannedToIntroduce ? $this->normalPercent(($pendingWithViolations / $totalPlannedToIntroduce) * 100) : 0,
                    ]
                ];
                unset($oneOIV);

            }

            return $result;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри RatingOIVController/oivData()', [
                'exception' => $th,
            ]);

            return [];
        }
    }


    /**
     * Нормализует процентное значение
     */
    private function normalPercent(float $value): float
    {
        try {
            return round($value);
        } catch (\Throwable $th) {
            Log::error('ошибка внутри RatingOIVController/normalPercent()', [
                'exception' => $th,
            ]);

            return $value;
        }
    }
}
