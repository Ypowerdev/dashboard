<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MongoChangeLogModel;
use App\Models\MonitorPeople;
use App\Models\MonitorTechnica;
use App\Models\ObjectModel;
use DateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер DetailController отвечает за получение детальной информации об объекте.
 *
 * Этот контроллер предоставляет API для получения подробной информации об объекте по его ID,
 * включая связанные данные (OIV, регион, источник финансирования, этапы строительства и т.д.).
 */
class DetailController extends Controller
{
    /**
     * Возвращает детальную информацию об объекте по ID или УИН.
     *
     * Включает основные данные объекта, связанные сущности (регион, источник финансирования и т.д.),
     * этапы реализации, этапы строительства (OIV и SMG), фото, мониторинг и другую информацию.
     *
     * @param int|string $idOrUin ID или УИН объекта
     * @return JsonResponse JSON-ответ с детальной информацией
     */
    public function show(int|string $idOrUin): JsonResponse
    {
        try {
            $object = ObjectModel::findByIdOrUin($idOrUin);

            if (!$object) {
                return response()->json(['message' => 'Объект не найден'], 404);
            }

            if(!in_array($object->id,request()->input('allowedObjectIDS', []))){
                return response()->json([
                    'access' => false,
                    'message' => 'Данный объект недоступен Вам для просмотра',
                ], 401);
            }
            // Получаем основной объект
            $object = ObjectModel::with([
                'region',
                'finSource',
                'customer',
                'developer',
                'contractor',
                'objectControlPoint',
                'oksStatusWithPivot',
                'fno',
                'photos',
                'etapiRealizaciiLibraryWithPivot',
                'coords',
                'projectManager',
                'videoLinks',
            ])->whereIn('id', request()->input('allowedObjectIDS', []))
                ->findOrFail($object->id);

            // Проверяем, что объект найден и не является коллекцией
            if ($object instanceof \Illuminate\Database\Eloquent\Collection) {
                return response()->json(['error' => 'Объект не найден или является коллекцией'], 404);
            }

            $aip_sum = $object->aip_sum ?? null;
            $aip_prepay = $object->aip_prepay ?? null;
            $contract_sum = $object->contract_sum ?? null;

            // Вычисляем aip_prepay_percent, если оба значения существуют
            if ($contract_sum !== null && $aip_prepay !== null && $contract_sum != 0) {
                $aip_prepay_percent = ($aip_prepay / $contract_sum) * 100;
            } else {
                $aip_prepay_percent = null;
            }

            $finance_planned = $object->finance_planned ?? null;
            $finance_advance = $object->finance_advance ?? null;
            $finance_readiness = $object->finance_readiness ?? null;
            $finance_planned_percent = $this->calculatePercent($contract_sum, $finance_planned);
            $finance_advance_percent = $this->calculatePercent($contract_sum, $finance_advance);
            $finance_readiness_percent = $this->calculatePercent($contract_sum, $finance_readiness);

            $mongo_data = MongoChangeLogModel::where('entity_id', $object->uin)
                ->where('entity_type', 'Строительный объект')
                ->where('type_of_action', 'Обновление')
                ->whereRaw([
                    '$expr' => [
                        '$and' => [
                            // сравнение полей между собой
                            ['$ne' => [
                                'before.planned_commissioning_directive_date',
                                'after.planned_commissioning_directive_date',
                            ]],
                            // оба существуют
                            ['$ne' => ['before.planned_commissioning_directive_date', null]],
                            ['$ne' => ['after.planned_commissioning_directive_date', null]],
                            // оба не пустые строки
                            ['$ne' => ['before.planned_commissioning_directive_date', '']],
                            ['$ne' => ['after.planned_commissioning_directive_date', '']],
                        ],
                    ],
                ])
                ->latest('updated_at')
                ->first();

            $who_decided       = data_get($mongo_data, 'json_user_name');
            // if (!$who_decided || empty($who_decided)) {
            //     $who_decided_data = MongoChangeLogModel::where('entity_id', $object->uin)
            //         ->where('entity_type', 'Строительный объект')
            //         ->where('type_of_action', 'Обновление')
            //         ->whereNotNull('json_user_name')
            //         ->whereRaw([
            //             '$and' => [
            //                 ['json_user_name' => ['$ne' => null]],
            //                 ['json_user_name' => ['$ne' => '']],
            //             ],
            //         ])
            //         ->orderBy('updated_at', 'desc')
            //         ->first();

            //     $who_decided = data_get($who_decided_data, 'json_user_name');
            // }

            $previous_deadline = data_get($mongo_data, 'before.planned_commissioning_directive_date');
            $updated_at  = data_get($mongo_data, 'updated_at');

            // Формируем результат
            $result = [
                'object' => [
                    'id' => $object->id,
                    'uin' => $object->uin,
                    'name' => $object->name,
                    'address' => $object->address,
                    'ssr_cost' => $object->ssr_cost ?? null,
                    'renovation' => (bool)$object->renovation ?? null,
                    'area' => $object->area ?? null,
                    'risks_flag' => (bool)$object->risks_flag ?? null,
                    'breakdowns_flag' => (bool)$object->breakdowns_flag ?? null,
                    'overdue' => (bool)$object->overdue ?? null,
                    'expiration_possible' => (bool)$object->expiration_possible ?? null,
                    'deadline' => $object->deadline ?? null,
                    'readiness' => $object->readiness ?? null,
                    'readiness_percentage' => $object->readiness_percentage ?? null,
                    'link' => $object->link ?? null,
                    'suid_ksg_url' => $object->suid_ksg_url ?? null,
                    'fno_type_code' => $object->fno_type_code ?? null,
                    'oiv' => $object->oiv ?? null,
                    'region' => [
                        'name' => $object->region->name ?? null
                    ],
                    'fin_source' => [
                        'name' => $object->finSource->name ?? null
                    ],
                    'customer' => [
                        'inn' => $object->customer->inn ?? null,
                        'name' => $object->customer->name ?? null
                    ],
                    'developer' => [
                        'inn' => $object->developer->inn ?? null,
                        'name' => $object->developer->name ?? null
                    ],
                    'contractor' => [
                        'inn' => $object->contractor->inn ?? null,
                        'name' => $object->contractor->name ?? null
                    ],
                    // Получаем данные о статусе ОКС (который является коллекцией)
                    'oks_status' => ($object->oksStatusWithPivot && $object->oksStatusWithPivot->count() > 0) ? [
                        'id' => $object->oksStatusWithPivot->first()->id,
                        'name' => $object->oksStatusWithPivot->first()->name
                    ] : null,
                    'fno_library' => [
                        'group_name' => $object->fno->group_name ?? null,
                        'subgroup_name' => $object->fno->subgroup_name ?? null,
                        'type_name' => $object->fno->type_name ?? null,
                        'type_code' => $object->fno->type_code ?? null
                    ],
                    'aip_inclusion_date' => isset($object->aip_inclusion_date) ? (new DateTime($object->aip_inclusion_date))->format('d.m.Y') : null,
                    'contract_date' => isset($object->contract_date) ? (new DateTime($object->contract_date))->format('d.m.Y') : null,
                    'planned_commissioning_directive_date' => isset($object->planned_commissioning_directive_date) ? (new DateTime($object->planned_commissioning_directive_date))->format('m.Y') : null,
                    'contract_commissioning_date' => isset($object->contract_commissioning_date) ? (new DateTime($object->contract_commissioning_date))->format('d.m.Y') : null,
                    'forecasted_commissioning_date' => isset($object->forecasted_commissioning_date) ? (new DateTime($object->forecasted_commissioning_date))->format('d.m.Y') : null,
                    'aip_flag' => (bool)($object->aip_flag ?? null),
                    'aip_sum' => $aip_sum,
                    'aip_prepay' => $aip_prepay,
                    'contract_sum' => $contract_sum,
                    'aip_prepay_percent' => $aip_prepay_percent,
                    'aip_year' => $object->aip_year ?? null,
                    'floors' => $object->floors ?? null,
                    'length' => $object->length ?? null,
                    'ano_smg_updated_date' => isset($object->ano_smg_updated_date) ? (new DateTime($object->ano_smg_updated_date))->format('d.m.Y') : null,
                    'ct_deadline_failure' => $object->ct_deadline_failure ?? null,
                    'ct_deadline_high_risk' => $object->ct_deadline_high_risk ?? null,
                    'planned_commissioning_directive_think_change_date' => isset($object->planned_commissioning_directive_think_change_date) ? (new DateTime($object->planned_commissioning_directive_think_change_date))->format('d.m.Y') : null,
                    'showButtonVseEtapi' => $this->showButtonVseEtapi($object),
                    'finance_planned' => $finance_planned,
                    'finance_advance' => $finance_advance,
                    'finance_readiness' => $finance_readiness,
                    'finance_planned_percent' => $finance_planned_percent,
                    'finance_advance_percent' => $finance_advance_percent,
                    'finance_readiness_percent' => $finance_readiness_percent,
                    'ksg_link' => $object->getKsgLink(),
                    'coordinates' => $object->coordinates,
                    'latitude_longitude' => $object->latitude_longitude,
                    'map' => $object->coords,
                    'project_manager' => $object->projectManager ?? null,
                    'video_links' => $object->videoLinks->isNotEmpty() ? $object->videoLinks : null,
                    'general_designer' => $object->generalDesigner ?? null,
                    'aip' => $this->showApiLimitBlock($object),
                    'updated_date' => $object?->updated_date ? (new DateTime($object->updated_date))->format('d.m.Y') : null,
                    'who_decided' => $who_decided ?? null,
                    'previous_deadline' => $previous_deadline ? (new DateTime($previous_deadline))->format('d.m.Y') : null,
                    'updated_at' => $updated_at ? (new DateTime($updated_at))->format('d.m.Y') : null,
                ],
                'etapiiRealizacii' => $object->getAllEtapiWithPivotData()->toArray(),

                'breakdowns' => $object->breakdowns ?? [],

                'constructionStagesOIV' => (new \App\Services\ObjectConstructionStagesViewService)->getDataForOIV($object),
                'constructionStagesSMG' => (new \App\Services\ObjectConstructionStagesViewService)->getDataForSMG($object),
                'constructionStagesAI' => (new \App\Services\ObjectConstructionStagesViewService)->getDataForAI($object),
                'photos' => $object->photos,
                'panelMonitoring' => [
                    'monitor_people_days' => MonitorPeople::getDailyData($object->id),
                    'monitor_people_weeks' => MonitorPeople::getWeeklyData($object->id),
                    'monitor_people_months' => MonitorPeople::getMonthlyData($object->id),
                    'monitor_technica_days' => MonitorTechnica::getDailyData($object->id),
                    'monitor_technica_weeks' => MonitorTechnica::getWeeklyData($object->id),
                    'monitor_technica_months' => MonitorTechnica::getMonthlyData($object->id),
                ],
                'basePhotoUrl' => $this->getBasePhotoUrl(),
            ];

            return response()->json($result);
        } catch (\Throwable $th) {
            report($th);

            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    /**
     * Вычисляет процентное соотношение суммы к договорной сумме.
     *
     * @param float|null $contract_sum Сумма договора
     * @param float|null $sum Рассчитываемая сумма
     * @return float|null Процент выполнения или null при ошибке
     */
    public function calculatePercent($contract_sum = null, $sum = null): float | null
    {
        try {
            if ($contract_sum !== null && $sum !== null && $contract_sum != 0) {
                $percent = round(($sum / $contract_sum) * 100, 2);
                if ($percent >= 100) {
                    return 100;
                }
                return $percent;
            } else {
                return null;
            }
        } catch (\Throwable $th) {
            Log::error('ошибка внутри DetailController/calculatePercent()', [
                'exception' => $th,
            ]);

            return null;
        }
    }

    /**
     * Определяет, нужно ли показывать кнопку "Все этапы".
     *
     * Кнопка показывается, если у объекта есть 9 или более контрольных точек с датой начала.
     *
     * @param ObjectModel $object Объект строительства
     * @return bool true - показывать кнопку, false - не показывать
     */
    private function showButtonVseEtapi($object):bool
    {
        try {
            $count = 0;
            foreach ($object->objectControlPoint as $oneCP)
            {
                if (!empty($oneCP['plan_start_date'])){
                    $count++;
                }
            }
            if ($count>=9){
                return true;
            }
            return false;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри DetailController/showButtonVseEtapi()', [
                'exception' => $th,
            ]);

            return false;
        }
    }

    /**
     * Формирует базовый URL для фото объектов.
     *
     * @return string Базовый URL для фото
     */
    private function getBasePhotoUrl(): string
    {
        try {
            $host = request()->getHost();
            $scheme = request()->getScheme();

//            if (str_ends_with($host, '.cifrolab.site')) {
//                return "$scheme://dashboard.cifrolab.site/objphotos";
//            }

//    todo: использовать storage_config
            return "$scheme://$host/objphotos";
        } catch (\Throwable $th) {
            Log::error('ошибка внутри DetailController/getBasePhotoUrl()', [
                'exception' => $th,
            ]);

            return '';
        }
    }
    /**
     * Формирует блок данных AIP, если есть хотя бы одно из свойств.
     * Если ни одного свойства нет - возвращает null.
     *
     * @param ObjectModel $object Объект строительства
     * @return array|null Массив данных AIP или null
     */
    private function showApiLimitBlock($object): ?array
    {
        try {
            // Проверяем, есть ли хотя бы одно из свойств
            $hasAipData = $object->aip_inclusion_date !== null
                || $object->aip_sum !== null
                || $object->aip_done_until_2025 !== null
                || $object->aip_years_sum_json !== null;

            // Если нет ни одного свойства - возвращаем null
            if (!$hasAipData) {
                return null;
            }

            // Подготавливаем данные по годам
            $baseYears = [
                2025 => null,
                2026 => null,
                2027 => null,
                2028 => null,
                2029 => null,
                2030 => null,
            ];

            $data = collect(json_decode($object->aip_years_sum_json, true))
                ->pluck('year_sum', 'year')
                ->toArray();

            $years = array_replace($baseYears, $data);

            // Формируем массив данных AIP
            return [
                'inclusion_date' => isset($object->aip_inclusion_date) ? (new DateTime($object->aip_inclusion_date))->format('d.m.Y') : null,
                'total_sum' => $object->aip_sum ?? null,
                'aip_done_until_2025' => $object->aip_done_until_2025 ?? null,
                'year' => $years
            ];
        } catch (\Throwable $th) {
            Log::error('Ошибка внутри DetailController/showApiLimitBlock()', [
                'exception' => $th,
            ]);

            return null;
        }
    }

}
