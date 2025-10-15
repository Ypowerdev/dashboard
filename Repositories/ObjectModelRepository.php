<?php

namespace App\Repositories;

use App\Http\Requests\CatalogRequest;
use App\Models\Library\OksStatusLibrary;
use App\Models\ObjectModel;
use App\Repositories\Interfaces\ObjectModelRepositoryInterface;
use DateTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Репозиторий для работы с моделью ObjectModel
 */
class ObjectModelRepository implements ObjectModelRepositoryInterface
{
    /** @var ObjectModel */
    protected $model;

    /**
     * Конструктор репозитория
     *
     * @param ObjectModel $model Экземпляр модели объекта
     */
    public function __construct(ObjectModel $model)
    {
        $this->model = $model;
    }

    /**
     * Получение отфильтрованного запроса к таблице objects
     *
     * @param array $filterOptionsData Массив параметров фильтрации, содержащий:
     *   - period_aip: массив дат для фильтрации по дате включения в АИП
     *   - aip_flag: флаг АИП
     *   - oivs: массив ID органов исполнительной власти
     *   - customer_ids: массив ID заказчиков
     *   - lvl1_id: ID уровня 1 ФНО
     *   - lvl2_id: ID уровня 2 ФНО
     *   - lvl3_id: ID уровня 3 ФНО
     *   - lvl4_ids: ID уровня 4 ФНО
     * @return Builder|Exception Построитель запросов или исключение в случае ошибки
     */
    public function getFilteredDataHomePage($filterOptionsData)
    {

        try {
            $aip_years = $filterOptionsData['aip_years'];
            $commissioning_years = $filterOptionsData['commissioning_years'];
            $aipFlag = ($filterOptionsData['aip_flag']===true)??true;
            $oivs = $filterOptionsData['oivs'];
            $podved_inns = $filterOptionsData['podved_inns'];

            $lvl1_id = $filterOptionsData['lvl1_id'];
            $lvl2_id = $filterOptionsData['lvl2_id'];
            $lvl3_id = $filterOptionsData['lvl3_id'];
            $lvl4_ids = $filterOptionsData['lvl4_ids'];
            $is_object_directive = $filterOptionsData['is_object_directive'];
            $fno_engineering = $filterOptionsData['fno_engineering'];

            $query = $this->model->whereIn('id', request()->input('allowedObjectIDS', []));

            if ($aipFlag) {
                $query->where('aip_flag', true);
            }

            if ($aip_years) {
                $query->where(function ($subquery) use ($aip_years) {
                    foreach ($aip_years as $date) {
                        $subquery->OrWhere('aip_year', $date);
                    }
                });
            }

            if ($commissioning_years) {
                $query->where(function ($subquery) use ($commissioning_years, $is_object_directive) {
                    foreach ($commissioning_years as $date) {
                        $year = is_numeric($date) ? $date : date('Y', strtotime($date));
                        if ($is_object_directive) {
                            $subquery->orWhereRaw('EXTRACT(YEAR FROM planned_commissioning_directive_date) = ?', [$year]);
                        } else {
                            $subquery->orWhereRaw('EXTRACT(YEAR FROM forecasted_commissioning_date) = ?', [$year]);
                        }
                    }
                });
            }

            if ($is_object_directive) {
                $query->where('is_object_directive', true);
            }

            if ($oivs && !$podved_inns) {
                $query->where(function ($subquery) use ($oivs) {
                    foreach ($oivs as $oiv) {
                        $subquery->OrWhere('oiv_id', $oiv);
                    }
                });
            }

            if ($podved_inns) {
                $query->where(function ($subquery) use ($podved_inns) {
                    // Проверка ИНН заказчика
                    $subquery->whereHas('customer', function ($customerQuery) use ($podved_inns) {
                        $customerQuery->whereIn('inn', $podved_inns);
                    });

                    // Проверка ИНН застройщика
                    $subquery->orWhereHas('developer', function ($developerQuery) use ($podved_inns) {
                        $developerQuery->whereIn('inn', $podved_inns);
                    });

                    // Проверка ИНН подрядчика
                    $subquery->orWhereHas('contractor', function ($contractorQuery) use ($podved_inns) {
                        $contractorQuery->whereIn('inn', $podved_inns);
                    });
                });
            }

            if ($lvl4_ids) {
                $query->whereHas('fno_level', function ($subquery) use ($lvl4_ids) {
                    $subquery->whereIn('lvl4_code', (array)$lvl4_ids); // Явное приведение к массиву, если нужно
                });
            }if ($lvl3_id){
                $query->whereHas('fno_level', function ($subquery) use ($lvl3_id) {
                    $subquery->where('lvl3_code', $lvl3_id);
                });
            }if ($lvl2_id){ // просто какой-то второй уровень

                if ($lvl2_id===1 && $lvl1_id===1){ //жилые и реновация
                    $query->whereHas('fno_level', function ($subquery) use ($lvl1_id) {
                        $subquery->where('lvl1_code', $lvl1_id);
                    });
                    $query->where('renovation', true);
                }elseif ($lvl2_id===2 && $lvl1_id===1){ //жилые и НЕ реновация
                    $query->whereHas('fno_level', function ($subquery) use ($lvl1_id) {
                        $subquery->where('lvl1_code', $lvl1_id);
                    });
                    $query->where('renovation', false);
                } else {
                    $query->whereHas('fno_level', function ($subquery) use ($lvl2_id) {
                        $subquery->where('lvl2_code', $lvl2_id);
                    });
                }
            }if ($lvl1_id) { // просто какой-то первый уровень
                if ($lvl1_id == 5) {
                    $query->whereHas('fno_level', function ($subquery) use ($lvl1_id) {
                        $subquery->whereIn('lvl1_code', [1,2]);
                    });
                    $query->where('renovation', false);
                } else {
                    $query->whereHas('fno_level', function ($subquery) use ($lvl1_id) {
                        $subquery->where('lvl1_code', $lvl1_id);
                    });
                }
            }

            if ($fno_engineering) {
                $query->where('fno_engineering', true);
            }

            return $query;
        } catch (\Throwable $th) {
            dd($th);
            return $th;
        }
    }

    /**
     * Получение изменений статусов ОКС за указанный период
     *
     * @param array $oksStatuses Массив ID статусов ОКС для фильтрации
     * @param Builder $query Базовый запрос для дальнейшей фильтрации
     * @param int $daysBefore Количество дней для выборки (по умолчанию 7)
     * @return Builder|Exception Построитель запросов или исключение в случае ошибки
     */
    public function getDelta($oksStatuses, $query, $daysBefore = 7) {
        try {

            Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/custom.log'),
            ])->info('данные внутри public function getDelta', [
                '$oksStatuses' => $oksStatuses,
                '$query' => $query,
                '$this' => $this,
            ]);

            $status_changes = DB::table('object_oks_status_changes')
                ->whereIn('oks_status_library_id', $oksStatuses)
                ->where(function ($query) use ($oksStatuses) {
                    $query->where(function ($q) use ($oksStatuses) {
                        // Основное условие: предыдущий статус не входит в разрешенные
                        $q->whereNotIn('oks_status_library_previous_id', $oksStatuses);
                    })
                        ->orWhere(function ($q) use ($oksStatuses) {
                            // Случай с null: считаем предыдущий статус = текущий статус - 1
                            $q->whereNull('oks_status_library_previous_id')
                                ->whereIn(DB::raw('oks_status_library_id - 1'), $oksStatuses);
                        })
                        ->orWhere(function ($q) use ($oksStatuses) {
                            // Случай когда предыдущий статус = текущий статус - 1
                            $q->whereRaw('oks_status_library_previous_id = oks_status_library_id - 1')
                                ->whereIn('oks_status_library_previous_id', $oksStatuses);
                        });
                })
                ->whereBetween('created_at', [
                    Carbon::now()->subDays($daysBefore)->startOfDay(),  // Начало периода (7 дней назад)
                    Carbon::now()->endOfDay()                       // Конец периода (текущий день)
                ])
                ->pluck('object_id')
            ;

            $query->whereIn('id', $status_changes);

            return $query;
        } catch (\Throwable $th) {
            Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/custom.log'),
            ])->error('ошибка внутри public function getDelta', [
                '$th' => $th,
            ]);

            return $th;
        }
    }

    private function applyObjectListOrdering($query, int $objectListId): void
    {
        // Сортируем по позиции в этом списке; NULLS LAST — чтобы не ломать порядок,
        // если вдруг объект вне списка (на всякий случай)
        $query->orderByRaw(
            '(SELECT position
            FROM object_list_object olo
            WHERE olo.object_list_id = ?
                AND olo.object_id = objects.id) ASC NULLS LAST',
            [$objectListId]
        );
    }

    /**
     * Get catalog data based on request parameters
     *
     * @param array $validatedData Request object containing filter parameters
     * @return array Array of filtered objects data
     */
    public function getCatalogData(array $validatedData): Collection
    {
        // Получаем все статусы ОКС и преобразуем их в массив, где ключ — ID статуса
        $oksStatusesArr = OksStatusLibrary::all()->keyBy('id')->toArray();

        // Получаем параметры фильтрации из массива
        $lvl1_id = $validatedData['lvl1_id'] ?? null;
        $lvl2_id = $validatedData['lvl2_id'] ?? null;
        $lvl3_id = $validatedData['lvl3_id'] ?? null;
        $lvl4_ids = $validatedData['lvl4_ids'] ?? null;
        $fno_engineering = $validatedData['fno_engineering'] ?? null;
        $oksStatusArrayName = $validatedData['oksStatusArrayName'] ?? null;
        $riskType = $validatedData['riskType'] ?? null;
        $violationType = $validatedData['violationType'] ?? 'default';
        $ct_deadline_failure = $validatedData['ct_deadline_failure'] ?? null;
        $ct_deadline_high_risk = $validatedData['ct_deadline_high_risk'] ?? null;
        $objectStatus = $validatedData['objectStatus'] ?? null;
        $searchTEXT = $validatedData['searchTEXT'] ?? null;
        $sortType = $validatedData['sortType'] ?? null;
        $oiv_id = $validatedData['oiv_id'] ?? null;
        $any_company_id = $validatedData['any_company_id'] ?? null;
        $contractor_id = $validatedData['contractor_id'] ?? null;
        $aip_flag = $validatedData['aip_flag'] ?? null;
        $aip_years = $validatedData['aip_years'] ?? null;
        $planned_commissioning_directive_date_years = $validatedData['planned_commissioning_directive_date_years'] ?? null;
        $contractSizes = $validatedData['contractSizes'] ?? null;
        $renovation = $validatedData['renovation'] ?? null;
        $is_object_directive = $validatedData['is_object_directive'] ?? null;
        $commissioning_years = $validatedData['commissioning_years'] ?? null;
        $culture_manufacture = $validatedData['culture_manufacture'] ?? null;

        $podved_inns = $validatedData['podved_inns'] ?? null;
        $objectListId = $validatedData['object_list_id'] ?? null;

        // Создаем базовый запрос с учетом связанных сущностей
        $query = ObjectModel::with([
            'oiv',          // Связь с OIV (орган исполнительной власти)
            'region',       // Связь с регионом
            'finSource',    // Связь с источником финансирования
            'customer',     // Связь с заказчиком
            'developer',    // Связь с застройщиком
            'contractor',   // Связь с генподрядчиком
            'oksStatusWithPivot',    // Связь со статусом ОКС
            'oksStatusLibrary',    // Связь со статусом ОКС
            'fno',           // Связь с ФНО (финансово-нормативная организация)
            'etapiRealizaciiLibraryWithPivot',
        ])->whereIn('id', request()->input('allowedObjectIDS', []));

        $this->applyOtherFilters(
            query: $query,
            any_company_id: $any_company_id,
            currentOksStatusArrayName: $oksStatusArrayName,
            currentRiskType: $riskType,
            currentViolationType: $violationType,
            aip_flag: $aip_flag,
            aip_years: $aip_years,
            objectStatus: $objectStatus,
            oiv_id: $oiv_id,
            contractor_id: $contractor_id,
            lvl4_ids: $lvl4_ids,
            lvl3_id: $lvl3_id,
            lvl2_id: $lvl2_id,
            lvl1_id: $lvl1_id,
            fno_engineering: $fno_engineering,
            searchTEXT: $searchTEXT,
            planned_commissioning_directive_date_years: $planned_commissioning_directive_date_years,
            contractSizes: $contractSizes,
            renovation: $renovation,
            is_object_directive: $is_object_directive,
            commissioning_years: $commissioning_years,
            ct_deadline_failure: $ct_deadline_failure,
            ct_deadline_high_risk: $ct_deadline_high_risk,
            culture_manufacture: $culture_manufacture,
            podved_inns: $podved_inns,
            objectListId: $objectListId,
        );

        $query->orderByDesc('ct_deadline_failure');

        if ($objectListId) {
            $this->applyObjectListOrdering($query, (int) $objectListId);
        } else {
            // Применение сортировки
            if ($sortType && $sortType !== 'date-desc') {
                $query->orderBy('updated_date', 'desc');
            } else {
                $query->orderBy('updated_date', 'asc');
            }
        }

        // Преобразуем коллекцию объектов в массив с нужной структурой
        $objects = $query->get();
        $objects->transform(function ($object) use ($oksStatusesArr) {
            return [
                'id' => $object->id,
                'uin' => $object->uin,
                'name' => $object->name,
                'address' => $object->address,
                'renovation' => $object->renovation,
                'area' => $object->area,
                'risks_flag' => $object->risks_flag,
                'breakdowns_flag' => $object->breakdowns_flag,
                'overdue' => $object->overdue,
                'expiration_possible' => $object->expiration_possible,
                'readiness_percentage_fact' => $object->readiness_percentage_fact,
                'link' => $object->link,
                'fno_type_code' => $object->fno_type_code,
                'oiv' => $object->oiv ? [ // Если OIV существует, добавляем его данные
                    'name' => $object->oiv->name
                ] : null,
                'region' => $object->region ? [ // Если регион существует, добавляем его данные
                    'name' => $object->region->name
                ] : null,
                'fin_source' => $object->finSource ? [ // Если источник финансирования существует, добавляем его данные
                    'name' => $object->finSource->name
                ] : null,
                'customer' => $object->customer ? [ // Если заказчик существует, добавляем его данные
                    'inn' => $object->customer->inn,
                    'name' => $object->customer->name
                ] : null,
                'developer' => $object->developer ? [ // Если застройщик существует, добавляем его данные
                    'inn' => $object->developer->inn,
                    'name' => $object->developer->name
                ] : null,
                'contractor' => $object->contractor ? [ // Если генподрядчик существует, добавляем его данные
                    'inn' => $object->contractor->inn,
                    'name' => $object->contractor->name
                ] : null,
                'oks_status' => $object->oks_status_id ? [ // Если статус ОКС существует, добавляем его данные
                    'id' => $oksStatusesArr[(int)$object->oks_status_id]['id'],
                    'name' => $oksStatusesArr[(int)$object->oks_status_id]['name']
                ] : null,
                'fno_library' => $object->fno ? [ // Если ФНО существует, добавляем его данные
                    'group_name' => $object->fno->group_name,
                    'subgroup_name' => $object->fno->subgroup_name,
                    'type_name' => $object->fno->type_name,
                    'type_code' => $object->fno->type_code
                ] : null,
                'aip_inclusion_date' => $object->aip_inclusion_date,
                'contract_date' => $object->contract_date,
                'planned_commissioning_directive_date' => isset($object->planned_commissioning_directive_date) ? (new DateTime($object->planned_commissioning_directive_date))->format('d.m.Y') : null,
                'contract_commissioning_date' => $object->contract_commissioning_date,
                'forecasted_commissioning_date' => $object->forecasted_commissioning_date,
                'ct_deadline_failure' => $object->ct_deadline_failure,
                // 'renovation' => $object->renovation,
                'date_of_introduction' => $object->getDateOfIntroduction(),
            ];
        });

        return $objects;
    }

    /**
     * Метод для получения количества объектов для каждого значения фильтра.
     *
     * Возвращает JSON-ответ с количеством объектов для каждого возможного значения фильтров:
     * - Типы объектов (Жилые, Нежилые, Дороги, Метро)
     * - Стадии (В строительстве, Введен, Приостановлено, Иное)
     * - Типы рисков (Есть риски, Нет рисков)
     * - Типы нарушений (Есть нарушения, Нет нарушения)
     * - Статусы сроков (Низкий риск, Высокий риск, Срыв срока)
     *
     * @param array $validatedData
     * @return JsonResponse
     */
    public function getFilterCountsForCatalog(array $validatedData): JsonResponse
    {
        try {
            // Получаем параметры текущих фильтров из запроса
            $lvl1_id = $validatedData['lvl1_id'] ?? null;
            $lvl2_id = $validatedData['lvl2_id'] ?? null;
            $lvl3_id = $validatedData['lvl3_id'] ?? null;
            $lvl4_ids = $validatedData['lvl4_ids'] ?? null;
            $fno_engineering = $validatedData['fno_engineering'] ?? null;
            $oksStatusArrayName = $validatedData['oksStatusArrayName'] ?? null;
            $riskType = $validatedData['riskType'] ?? null;
            $violationType = $validatedData['violationType'] ?? 'default';
            $ct_deadline_failure = $validatedData['ct_deadline_failure'] ?? null;
            $ct_deadline_high_risk = $validatedData['ct_deadline_high_risk'] ?? null;
            $objectStatus = $validatedData['objectStatus'] ?? null;
            $searchTEXT = $validatedData['searchTEXT'] ?? null;
            $sortType = $validatedData['sortType'] ?? null;
            $oiv_id = $validatedData['oiv_id'] ?? null;
            $any_company_id = $validatedData['any_company_id'] ?? null;
            $contractor_id = $validatedData['contractor_id'] ?? null;
            $aip_flag = $validatedData['aip_flag'] ?? null;
            $aip_years = $validatedData['aip_years'] ?? null;
            $planned_commissioning_directive_date_years = $validatedData['planned_commissioning_directive_date_years'] ?? null;
            $contractSizes = $validatedData['contractSizes'] ?? null;
            $renovation = $validatedData['renovation'] ?? null;
            $is_object_directive = $validatedData['is_object_directive'] ?? null;
            $commissioning_years = $validatedData['commissioning_years'] ?? null;
            $culture_manufacture = $validatedData['culture_manufacture'] ?? null;

            $currentOksStatusArrayName = $validatedData['currentOksStatusArrayName'] ?? null;
            $currentViolationType = $validatedData['currentViolationType'] ?? null;
            $currentDeadlineStatus = $validatedData['currentDeadlineStatus'] ?? null;
            $currentRiskType = $validatedData['currentRiskType'] ?? null;
            $currentObjectType = $validatedData['currentObjectType'] ?? null;

            $podved_inns = $validatedData['podved_inns'] ?? null;
            $objectListId = $validatedData['object_list_id'] ?? null;


            // Создаем базовый запрос с учетом текущих фильтров
            // $baseQuery = ObjectModel::query()->whereIn('id', request()->input('allowedObjectIDS', []));
            $baseQuery = ObjectModel::with([
                'oiv',          // Связь с OIV (орган исполнительной власти)
                'region',       // Связь с регионом
                'finSource',    // Связь с источником финансирования
                'customer',     // Связь с заказчиком
                'developer',    // Связь с застройщиком
                'contractor',   // Связь с генподрядчиком
                'oksStatusWithPivot',    // Связь со статусом ОКС
                'oksStatusLibrary',    // Связь со статусом ОКС
                'fno',           // Связь с ФНО (финансово-нормативная организация)
                'etapiRealizaciiLibraryWithPivot',
            ])->whereIn('id', request()->input('allowedObjectIDS', []));

            $this->applyOtherFilters(
                query: $baseQuery,
                any_company_id: $any_company_id,
                currentOksStatusArrayName: $oksStatusArrayName,
                currentRiskType: $riskType,
                currentViolationType: $violationType,
                aip_flag: $aip_flag,
                aip_years: $aip_years,
                objectStatus: $objectStatus,
                oiv_id: $oiv_id,
                contractor_id: $contractor_id,
                lvl4_ids: $lvl4_ids,
                lvl3_id: $lvl3_id,
                lvl2_id: $lvl2_id,
                lvl1_id: $lvl1_id,
                fno_engineering: $fno_engineering,
                searchTEXT: $searchTEXT,
                planned_commissioning_directive_date_years: $planned_commissioning_directive_date_years,
                contractSizes: $contractSizes,
                renovation: $renovation,
                is_object_directive: $is_object_directive,
                commissioning_years: $commissioning_years,
                ct_deadline_failure: $ct_deadline_failure,
                ct_deadline_high_risk: $ct_deadline_high_risk,
                culture_manufacture: $culture_manufacture,
                podved_inns: $podved_inns,
                objectListId: $objectListId,
            );

            $fnoEngineeringCounts = 0;
            if($fno_engineering){
                $fnoEngineeringCounts = $baseQuery->count();
            }

            // Считаем объекты по типам (Жилые, Нежилые, Дороги)
            $lvl1_idCounts = [];
            foreach (["Жилые", "Нежилые", "Дороги", "Метро", "Гражданские"] as $type) {
                $query = clone $baseQuery;

                $this->applyOtherFilters(
                    query: $query,
                    currentOksStatusArrayName: $currentOksStatusArrayName,
                    currentViolationType: $currentViolationType,
                    currentDeadlineStatus: $currentDeadlineStatus,
                    currentRiskType: $currentRiskType,
                    culture_manufacture: $culture_manufacture,
                );

                // Применяем фильтр по типу объекта
                $query->whereHas('fno_level', function($subquery) use ($type) {
                    if ($type == "Гражданские") {
                        $subquery->whereIn('lvl1_code', [1,2]);
                        $subquery->where('renovation', false);
                    } else {
                        $subquery->where('lvl1_name', $type);
                    }
                });

                $lvl1_idCounts[$type] = $query->count();
            }

            // Считаем объекты по стадиям (В строительстве, Введен, Приостановлено, Иное)
            $oksStatusArrayNameCounts = [];
            foreach (["Работы не начаты", "ПИР", "СМР", "Введен"] as $stage) {
                $query = clone $baseQuery;
                $this->applyOtherFilters(
                    query: $query,
                    lvl1_id: $lvl1_id,
                    currentRiskType: $currentRiskType,
                    currentViolationType: $currentViolationType,
                    currentDeadlineStatus: $currentDeadlineStatus,
                    currentOksStatusArrayName: $stage,
                    currentObjectType: $currentObjectType,
                    culture_manufacture: $culture_manufacture,
                );

                $oksStatusArrayNameCounts[$stage] = $query->count();
            }

            // Считаем объекты по рискам (Есть риски, Нет рисков)
            $riskTypeCounts = [];
            foreach (["Есть риски", "Нет рисков"] as $risk) {
                $query = clone $baseQuery;
                $this->applyOtherFilters(
                    query: $query,
                    currentObjectType: $currentObjectType,
                    lvl1_id: $lvl1_id,
                    currentOksStatusArrayName: $currentOksStatusArrayName,
                    currentViolationType: $currentViolationType,
                    currentDeadlineStatus: $currentDeadlineStatus,
                    currentRiskType: $risk,
                    culture_manufacture: $culture_manufacture,
                );

                $riskTypeCounts[$risk] = $query->count();
            }

            // Считаем объекты по нарушениям (Есть нарушения, Нет нарушений)
            $violationTypeCounts = [];
            foreach (["Есть нарушения", "Нет нарушений"] as $violation) {
                $query = clone $baseQuery;
                $this->applyOtherFilters(
                    query: $query,
                    lvl1_id: $lvl1_id,
                    currentOksStatusArrayName: $currentOksStatusArrayName,
                    // Применяем фильтр по нарушениям
                    currentViolationType: $violation,
                    currentDeadlineStatus: $currentDeadlineStatus,
                    currentRiskType: $risk,
                    culture_manufacture: $culture_manufacture,
                );
                $violationTypeCounts[$violation] = $query->count();
            }

            // Считаем объекты по статусам сроков (Низкий риск, Высокий риск, Срыв срока)
            $deadlineStatusCounts = [];
            foreach (["Низкий риск", "Высокий риск", "Срыв срока"] as $status) {
                $query = clone $baseQuery;
                switch ($status) {
                    case "Высокий риск":
                        $query->where('ct_deadline_high_risk', true);
                        break;
                    case "Срыв срока":
                        $query->where('ct_deadline_failure', true);
                        break;
                    case "Низкий риск":
                        $query->where('ct_deadline_failure', false);
                        $query->where('ct_deadline_high_risk', false);
                        break;
                    default:
                        break;
                }
                $this->applyOtherFilters(
                    query: $query,
                    lvl1_id: $lvl1_id,
                    currentOksStatusArrayName: $currentOksStatusArrayName,
                    currentViolationType: $currentViolationType,
                    currentRiskType: $currentRiskType,
                    culture_manufacture: $culture_manufacture,
                );
                $deadlineStatusCounts[$status] = $query->count();
            }

            return response()->json([
                'objectTypes' => $lvl1_idCounts,
                'fno_engineering' => $fnoEngineeringCounts,
                'oksStatusArrayNames' => $oksStatusArrayNameCounts,
                'riskTypes' => $riskTypeCounts,
                'violationTypes' => $violationTypeCounts,
                'deadlineStatuses' => $deadlineStatusCounts
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function applyOtherFilters(
        $query = null,
        $currentOksStatusArrayName = null,
        $currentViolationType = null,
        $currentDeadlineStatus = null,
        $currentRiskType = null,
        $currentObjectType = null,

        $aip_flag = null,
        $aip_years = null,
        $objectStatus = null,
        $oiv_id = null,
        $any_company_id = null,
        $contractor_id = null,
        $lvl4_ids = null,
        $lvl3_id = null,
        $lvl2_id = null,
        $lvl1_id = null,
        $fno_engineering = null,
        $searchTEXT = null,
        $planned_commissioning_directive_date_years = null,
        $contractSizes = null,
        $renovation = null,
        $culture_manufacture = null,
        $is_object_directive = null,
        $ct_deadline_failure = null,
        $ct_deadline_high_risk = null,
        $commissioning_years = null,
        $podved_inns = null,
        $objectListId = null,
    ): void {
        // Применяем все остальные фильтры
        if ($currentOksStatusArrayName) $this->applyoksStatusArrayNameFilter($query, $currentOksStatusArrayName);
        if ($currentViolationType) $this->applyViolationTypeFilter($query, $currentViolationType);
        // if ($currentDeadlineStatus && $currentDeadlineStatus !== "Все объекты") $this->applyDeadlineStatusFilter($query, $currentDeadlineStatus);

        if ($aip_flag) $this->applyAipFlagFilter($query, $aip_flag);
        if ($aip_years) $this->applyAipYearsFilter($query, $aip_years);
        if ($objectStatus) $this->applyObjectStatusFilter($query, $objectStatus);
        if ($oiv_id) $this->applyOIVFilter($query, $oiv_id);
        if ($any_company_id) $this->applyAnyCompanyInnFilter($query, $any_company_id);
        if ($currentObjectType) {
            $query->whereHas('fno_level', function($subquery) use ($currentObjectType) {
                $subquery->where('lvl1_name', $currentObjectType);
            });
        }
        if ($contractor_id) $this->applyContractorIdFilter($query, $contractor_id);
        if ($lvl4_ids || $lvl3_id || $lvl2_id || $lvl1_id) $this->applyObjectTypeFilter($query, $lvl4_ids, $lvl3_id, $lvl2_id, $lvl1_id);
        if ($fno_engineering) $this->applyFnoEngineeringFilter($query, $fno_engineering);

        if ($searchTEXT) $this->applysearchTEXTFilter($query, $searchTEXT);
        if ($planned_commissioning_directive_date_years) $this->applyPlannedCommissioningDirectiveDateYearsFilter($query, $planned_commissioning_directive_date_years);
        if ($contractSizes) $this->applyContractSizesFilter($query, $contractSizes);

        if ($renovation) $this->applyRenovationFilter($query, $renovation);
        if ($is_object_directive) $this->applyIsObjectDirective($query, $is_object_directive);
        if (isset($ct_deadline_failure)) $this->applyHasDeadlineFailure($query, $ct_deadline_failure);
        if (isset($ct_deadline_high_risk)) $this->applyHasDeadlineHighRisk($query, $ct_deadline_high_risk);
        if ($commissioning_years) $this->applyCommissioningYears($query, $commissioning_years, $is_object_directive);

        if ($culture_manufacture) $this->applyCultureManufacture($query, $culture_manufacture);
        if ($podved_inns) $this->applyPodvedInnsFilter($query, $podved_inns);
        if ($objectListId) $this->applyObjectListFilter($query, $objectListId);
    }

    /**
     * Вспомогательный метод для применения фильтра по флагу АИП
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param number $oksStatusArrayName
     * @return void
     */
    public function applyAipFlagFilter($query, $aip_flag): void
    {
        if ($aip_flag) {
            $query->where('aip_flag', true);
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по флагу FNO_ENGINEERING
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param boolean $fno_engineering
     * @return void
     */
    public function applyFnoEngineeringFilter($query, bool $fno_engineering): void
    {
        if ($fno_engineering) {
            $query->where('fno_engineering', true);
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по флагу АИП
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $aip_years
     * @return void
     */
    public function applyAipYearsFilter($query, $aip_years): void
    {
        if ($aip_years) {
            $query->where(function ($subquery) use ($aip_years) {
                foreach ($aip_years as $date) {
                    $subquery->OrWhere('aip_year', $date);
                }
            });
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по статусу объекта
     *
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param number $objectStatus
     * @return void
     */
    public function applyObjectStatusFilter($query, $objectStatus): void
    {
        switch ($objectStatus) {
            case 'Введено':
                $query->whereHas('etapiRealizaciiLibraryWithPivot', function($subquery) {
                    $subquery->where('name', 'РВ')
                        ->whereNotNull('fact_finish_date');
                });
                break;
            case 'Реализуются без нарушения сроков':
                // $query->where('ct_deadline_status', 'deadline_low_risk');
                $query->whereDoesntHave('etapiRealizaciiLibraryWithPivot', function($subquery) {
                    $subquery->where('name', 'РВ')
                        ->whereNotNull('fact_finish_date');
                });
                $query->whereNotNull('forecasted_commissioning_date')
                    ->whereNotNull('contract_commissioning_date')
                    ->whereColumn('forecasted_commissioning_date', '<=', 'contract_commissioning_date');
                break;
            case 'С нарушением сроков':
                // $query->whereIn('ct_deadline_status', ['deadline_failure', 'deadline_high_risk']);
                $query->whereDoesntHave('etapiRealizaciiLibraryWithPivot', function($subquery) {
                    $subquery->where('name', 'РВ')
                        ->whereNotNull('fact_finish_date');
                });
                $query->whereNotNull('forecasted_commissioning_date')
                    ->whereNotNull('contract_commissioning_date')
                    ->whereColumn('forecasted_commissioning_date', '>', 'contract_commissioning_date');
                break;
            default:
                break;
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по стадии объекта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $oksStatusArrayName
     * @return void
     */
    public function applyoksStatusArrayNameFilter($query, $oksStatusArrayName): void
    {
        switch ($oksStatusArrayName) {
            case 'Работы не начаты':
                $query->whereIn('oks_status_id', [1]);
                break;
            case 'ПИР':
                $query->whereIn('oks_status_id', [2, 3]);
                break;
            case 'СМР':
                $query->whereIn('oks_status_id', [4, 5]);
                break;
            case 'Введен':
                $query->whereIn('oks_status_id', [6, 7]);
                break;
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по нарушениям
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $violationType
     * @return void
     */
    public function applyViolationTypeFilter($query, $violationType): void
    {
        if ($violationType === 'Есть нарушения') {
            $query->whereHas('breakdowns');
        } else {
            $query->whereDoesntHave('breakdowns');
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по ОИВам
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $violationType
     * @return void
     */
    public function applyOIVFilter($query, $oiv_id): void
    {
        $query->where('oiv_id', $oiv_id);
    }

    /**
     * Вспомогательный метод для применения фильтра по подведам
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $anyCompanyInn
     * @return void
     */
    public function applyAnyCompanyInnFilter($query, $anyCompanyInn): void
    {
        if ($anyCompanyInn) {
            $query->when($anyCompanyInn, function ($query) use ($anyCompanyInn) {
                return $query->where(function ($subquery) use ($anyCompanyInn) {
                    // Проверка ИНН заказчика
                    $subquery->whereHas('customer', function ($customerQuery) use ($anyCompanyInn) {
                        $customerQuery->whereIn('inn', $anyCompanyInn);
                    });

                    // Проверка ИНН застройщика
                    $subquery->orWhereHas('developer', function ($developerQuery) use ($anyCompanyInn) {
                        $developerQuery->whereIn('inn', $anyCompanyInn);
                    });

                    // Проверка ИНН подрядчика
                    $subquery->orWhereHas('contractor', function ($contractorQuery) use ($anyCompanyInn) {
                        $contractorQuery->whereIn('inn', $anyCompanyInn);
                    });
                });
            });
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по подведам через массив ИНН
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $podved_inns
     * @return void
     */
    public function applyPodvedInnsFilter($query, $podved_inns): void
    {
        if (!empty($podved_inns)) {
            $query->when($podved_inns, function ($query) use ($podved_inns) {
                return $query->where(function ($subquery) use ($podved_inns) {
                    // Проверка ИНН заказчика
                    $subquery->whereHas('customer', function ($customerQuery) use ($podved_inns) {
                        $customerQuery->whereIn('inn', $podved_inns);
                    });

                    // Проверка ИНН застройщика
                    $subquery->orWhereHas('developer', function ($developerQuery) use ($podved_inns) {
                        $developerQuery->whereIn('inn', $podved_inns);
                    });

                    // Проверка ИНН подрядчика
                    $subquery->orWhereHas('contractor', function ($contractorQuery) use ($podved_inns) {
                        $contractorQuery->whereIn('inn', $podved_inns);
                    });
                });
            });
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по наличию в пользовательских списках объектов
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $objectListId
     * @return void
     */
    public function applyObjectListFilter($query, string $objectListId): void
    {
        $query->whereHas('objectLists', function ($objectListsQuery) use ($objectListId) {
            $objectListsQuery->where('object_lists.id', $objectListId);
        });
    }

    /**
     * Вспомогательный метод для применения фильтра по подведам
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $violationType
     * @return void
     */
    public function applysearchTEXTFilter($query, $searchTEXT): void
    {
        if ($searchTEXT) {
            $query->where(function ($q) use ($searchTEXT) {
                $q->whereRaw('name ILIKE ?', ["%{$searchTEXT}%"])
                    ->orWhereRaw('address ILIKE ?', ["%{$searchTEXT}%"])
                    ->orWhereRaw('uin ILIKE ?', ["%{$searchTEXT}%"])
                    ->orWhereRaw('master_kod_dc ILIKE ?', ["%{$searchTEXT}%"])
                    ->orWhereHas('contractor', function($subQuery) use ($searchTEXT) {
                        $subQuery->whereRaw('name ILIKE ?', ["%{$searchTEXT}%"]);
                    })
                    ->orWhereHas('customer', function($subQuery) use ($searchTEXT) {
                        $subQuery->whereRaw('name ILIKE ?', ["%{$searchTEXT}%"]);
                    })
                    ->orWhereHas('developer', function($subQuery) use ($searchTEXT) {
                        $subQuery->whereRaw('name ILIKE ?', ["%{$searchTEXT}%"]);
                    });
            });
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по подрядчику
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $contractor_id
     * @return void
     */
    public function applyContractorIdFilter($query, $contractor_id): void
    {
        if ($contractor_id) {
            if (is_array($contractor_id)) {
                $query->when($contractor_id, function ($query) use ($contractor_id) {
                    return $query->where(function ($subquery) use ($contractor_id) {
                        // Проверка ИНН подрядчика
                        $subquery->orWhereHas('contractor', function ($contractorQuery) use ($contractor_id) {
                            $contractorQuery->whereIn('id', $contractor_id);
                        });
                    });
                });
            } else {
                $query->when($contractor_id, function ($query) use ($contractor_id) {
                    return $query->where(function ($subquery) use ($contractor_id) {
                        // Проверка ИНН подрядчика
                        $subquery->orWhereHas('contractor', function ($contractorQuery) use ($contractor_id) {
                            $contractorQuery->where('id', $contractor_id);
                        });
                    });
                });
            }
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по типам объекта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $violationType
     * @return void
     */
    public function applyObjectTypeFilter($query, $lvl4_ids, $lvl3_id, $lvl2_id, $lvl1_id): void
    {
        if ($lvl4_ids) {
            $query->whereHas('fno_level', function ($subquery) use ($lvl4_ids) {
                $subquery->whereIn('lvl4_code', (array)$lvl4_ids); // Явное приведение к массиву, если нужно
            });
        }if ($lvl3_id){
            $query->whereHas('fno_level', function ($subquery) use ($lvl3_id) {
                $subquery->where('lvl3_code', $lvl3_id);
            });
        }if ($lvl2_id){ // просто какой-то второй уровень

            if ($lvl2_id===1 && $lvl1_id===1){ //жилые и реновация
                $query->whereHas('fno_level', function ($subquery) use ($lvl1_id) {
                    $subquery->where('lvl1_code', $lvl1_id);
                });
                $query->where('renovation', true);
            }elseif ($lvl2_id===2 && $lvl1_id===1){ //жилые и НЕ реновация
                $query->whereHas('fno_level', function ($subquery) use ($lvl1_id) {
                    $subquery->where('lvl1_code', $lvl1_id);
                });
                $query->where('renovation', false);
            } else {
                $query->whereHas('fno_level', function ($subquery) use ($lvl2_id) {
                    $subquery->where('lvl2_code', $lvl2_id);
                });
            }
        }if ($lvl1_id) { // просто какой-то первый уровень
            if ($lvl1_id == 5) {
                $query->whereHas('fno_level', function ($subquery) use ($lvl1_id) {
                    $subquery->whereIn('lvl1_code', [1,2]);
                });
                $query->where('renovation', false);
            } else {
                $query->whereHas('fno_level', function ($subquery) use ($lvl1_id) {
                    $subquery->where('lvl1_code', $lvl1_id);
                });
            }
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по нескольким годам
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $contractor_id
     * @return void
     */
    public function applyPlannedCommissioningDirectiveDateYearsFilter($query, $planned_commissioning_directive_date_years): void
    {
        if($planned_commissioning_directive_date_years && count($planned_commissioning_directive_date_years) > 0){
            $query->where(function ($query) use ($planned_commissioning_directive_date_years) {
                foreach ($planned_commissioning_directive_date_years as $year) {
                    $query->orWhereYear('planned_commissioning_directive_date', $year);
                }
            });
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по размерам контракта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $contractSizes
     * @return void
     */
    public function applyContractSizesFilter($query, $contractSizes): void
    {
        if($contractSizes && count($contractSizes) > 0){
            $query->whereHas('contractor', function ($contractQuery) use ($contractSizes) {
                $contractQuery->where(function ($subQuery) use ($contractSizes) {
                    foreach ($contractSizes as $size) {
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
            });
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по принадлежности объекта к программе реновации
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param boolean $renovation
     * @return void
     */
    public function applyRenovationFilter($query, $renovation): void
    {
        if ($renovation === "true") { $renovation = true; }
        if ($renovation === "false") { $renovation = false; }

        if (isset($renovation) && is_bool($renovation)) {
            $query->where('renovation', $renovation);
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по наличию проставленной даты планового ввода по директивному графику у объекта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param boolean $is_object_directive
     * @return void
     */
    public function applyIsObjectDirective($query, $is_object_directive): void
    {
        if ($is_object_directive) {
            $query->where('is_object_directive', true);
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по наличию срыва сроков у объекта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param boolean $ct_deadline_failure
     * @return void
     */
    public function applyHasDeadlineFailure($query, $ct_deadline_failure): void
    {
        if (isset($ct_deadline_failure)) {
            $query->where('ct_deadline_failure', $ct_deadline_failure);
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по наличию высокого риска у объекта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param boolean $ct_deadline_high_risk
     * @return void
     */
    public function applyHasDeadlineHighRisk($query, $ct_deadline_high_risk): void
    {
        if (isset($ct_deadline_high_risk)) {
            $query->where('ct_deadline_high_risk', $ct_deadline_high_risk);
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по наличию высокого риска у объекта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $commissioning_years
     * @return void
     */
    public function applyCommissioningYears($query, $commissioning_years, $is_object_directive): void
    {
        if ($commissioning_years) {
            $query->where(function ($q) use ($commissioning_years, $is_object_directive) {
                foreach ($commissioning_years as $year) {
                    // Добавляем условие LIKE для каждого элемента массива
                    if ($is_object_directive) {
                        $q->orWhere('planned_commissioning_directive_date', 'like', "%$year%");
                    } else {
                        $q->orWhere('forecasted_commissioning_date', 'like', "%$year%");
                    }
                }
            });
        }
    }

    /**
     * Вспомогательный метод для применения фильтра по высокому риску
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $contractor_id
     * @return void
     */
    private function applyCultureManufacture($query, $culture_manufacture): void
    {
        if ($culture_manufacture) {
            $query->whereHas('cultureManufacture');
        }
    }


    /**
     * Применить фильтр по факты срыва сроков
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Базовый запрос
     * @param string|null $status Значение статуса для фильтрации
     * @return \Illuminate\Database\Eloquent\Builder Модифицированный запрос
     */
    public function applyDeadlineFailureFilter($query)
    {
        return $query->where('ct_deadline_failure', true);
    }


    /**
     * Применить фильтр по высокому риску
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Базовый запрос
     * @param string|null $status Значение статуса для фильтрации
     * @return \Illuminate\Database\Eloquent\Builder Модифицированный запрос
     */
    public function applyDeadlineHighRiskFilter($query)
    {
        return $query->where('ct_deadline_high_risk', true);
    }

    /**
     * Применить фильтр по низкому риску
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Базовый запрос
     * @param string|null $status Значение статуса для фильтрации
     * @return \Illuminate\Database\Eloquent\Builder Модифицированный запрос
     */
    public function applyDeadlineOnlyLowRiskFilter($query)
    {
        return $query->where([
            ['ct_deadline_failure', '=', false],
            ['ct_deadline_high_risk', '=', false],
        ]);
    }

}
