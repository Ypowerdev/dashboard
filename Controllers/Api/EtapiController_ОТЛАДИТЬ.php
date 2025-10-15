<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Config;
use App\Models\Library\ControlPointsLibrary;
use App\Models\ObjectModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class EtapiController_ОТЛАДИТЬ extends Controller
{

    /**
     * Сокращенные названия для ровного отображения в слайдере
     * @var array|string[]
     */
    private array $correctEtapNameArray = [
        'Получение ТУ от ресурсоснабжающих организаций' => 'Получение ТУ от РСО',
        'Разработка и получение положительного заключения экспертизы ПСД' => 'Разработка экспертиза ПСД'
    ];


    /**
     * Точность округления процентов завершённых этапов.
     */
    private int $roundCompletePercent = 0;

    /**
     * Получение информации об этапах реализации объекта по его ID.
     *
     * @param int|string $idOrUin ID или УИН объекта, информацию о котором нужно получить.
     * @return JsonResponse JSON с информацией об этапах и контрольных точках.
     */
    public function show(int|string $idOrUin): JsonResponse
    {
        try {
            $object = ObjectModel::findByIdOrUin($idOrUin);

            if (!$object) {
                return response()->json(['error' => 'Объект не найден'], 404);
            }

            // Получаем данные объекта
            $object = ObjectModel::with([
                'etapiRealizaciiLibraryWithPivot',
                'oiv',
                'contractor',
                'objectControlPoint',
                'objectControlPoint.controlPointLibrary',
            ])->whereIn('id', request()->input('allowedObjectIDS', []))
                ->findOrFail($object->id);

            $data = $object->toArray();

            //  Обработка этапов реализации
            $etapiiRealizacii = $object->getAllEtapiWithPivotData()->toArray();

            $data['etapi_realizacii'] = $this->createAnswerEtapi(
                $this->organizePointsETAPI($etapiiRealizacii),
                $data['contractor']
            );

            //  Обработка контрольных точек с объединённым массивом
            $data['control_point'] = $this->controlPoints($object);
            $data['stroyGotovnost'] = $this->stroyGotovnost($object);

            //  Расчет процента готовности
            $contract_sum = $object->contract_sum ?? null;
            $finance_planned = $object->finance_planned ?? null;
            $finance_planned_percent = $this->calculatePercent($contract_sum, $finance_planned);
            $data['finance_planned_percent'] = $finance_planned_percent;

            unset($data['control_points_library']);
            $cuttedData = [
                'control_points' => $data['control_point'],
                'stroyGotovnost' => $data['stroyGotovnost'],
                'etapi_realizacii' => $data['etapi_realizacii'],
                'finance_planned_percent' => $data['finance_planned_percent']
            ];

            return response()->json($cuttedData);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Приведение элементов массива к нужному виду, вычисление статуса, цвета и прочих параметров.
     *
     * @param array $points Массив элементов с данными из pivot.
     * @return array Обработанный массив элементов.
     */
    private function organizePointsETAPI(array $points): array
    {
        try {
//            $now = time();/
            $now = Carbon::today();

            foreach ($points as &$point) {
                $planDate = isset($point['pivot']['plan_finish_date'])
                    ? strtotime($point['pivot']['plan_finish_date'])
                    : null;
                $factDate = !empty($point['pivot']['fact_finish_date'])
                    ? strtotime($point['pivot']['fact_finish_date'])
                    : null;

                $point['plan'] = $point['pivot']['plan_finish_date'] ?? null;
                $point['fact'] = $point['pivot']['fact_finish_date'] ?? null;

                // Если отсутствует фактическая дата исполнения
                // элементы попадают в блок "В работе"
                // в зависимости от наличия даты планового исполнения проставляются цвета блоков
                // при наличии фактической даты выполнения процент готовности устанавливается 100% или 0% при отсутствии
//                if (!$factDate) {
//                    $point['status'] = 'inproccess';
//                    $point['readiness'] = 0;
//                    if ($planDate) {
//                        // если до даты планового исполнения остались XX дней
//                        if ($planDate - $now <= Config::getDeadLineDays() * 86400 && $planDate >= $now) {
//                            $point['color'] = 'yellow';
//                        } elseif ($now > $planDate) {
//                            $point['color'] = 'red';
//                        } else {
//                            $point['color'] = 'white';
//                        }
//                    } else {
//                        $point['color'] = 'white';
//                    }

                $planDateCarbon = isset($point['pivot']['plan_finish_date'])
                    ? Carbon::parse($point['pivot']['plan_finish_date'])
                    : null;

                if (!$factDate) {
                    $point['status'] = 'inproccess';
                    $point['readiness'] = 0;
                    if ($planDateCarbon) {
                        // если до даты планового исполнения остались XX дней
                        if ($planDateCarbon->isFuture() && $planDateCarbon->diffInDays($now) <= Config::getDeadLineDays()) {
                            $point['color'] = 'yellow';
                        } elseif ($planDateCarbon->isPast()) {
                            $point['color'] = 'red';
                        } else {
                            $point['color'] = 'white';
                        }
                    } else {
                        $point['color'] = 'white';
                    }

                } else {
                    $point['status'] = 'complete';
                    $point['readiness'] = 100;
                    if (!$planDate) {
                        $point['color'] = 'green';
                    } else {
                        $point['color'] = ($factDate <= $planDate) ? 'green' : 'red';
                    }
                }

                unset($point['pivot']);
            }
            unset($point);

            return $points;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри EtapiController/organizePointsETAPI()', [
                'exception' => $th,
            ]);

            return [];
        }
    }

    /**
     * Группирует элементы по их id с формированием вложенной структуры по parent_id.
     *
     * @param array $points Массив элементов.
     * @return array Индексированный массив с вложенными элементами.
     */
    private function groupPoints(array $points): array
    {
        try {
            $indexed = [];
            foreach ($points as $point) {
                if(isset($point['id'])){
                    $point['child'] = [];
                    $indexed[$point['id']] = $point;
                }
            }

            foreach ($indexed as $id => $point) {
                if (!empty($point['parent_id']) && isset($indexed[$point['parent_id']])) {
                    $indexed[$point['parent_id']]['child'][] = $point;
                }
            }

            return $indexed;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри EtapiController/groupPoints()', [
                'exception' => $th,
            ]);

            return [];
        }
    }

    /**
     * Рассчитывает параметр "готовность" для каждой точки.
     *
     * Если у точки есть дочерние элементы, готовность рассчитывается как процент завершённых дочерних этапов.
     * Если дочерних элементов нет, готовность равна 100 для завершённого этапа или 0 для незавершённого.
     *
     * @param array $points Массив точек.
     * @return array Массив точек с рассчитанным параметром readiness.
     */
    private function assignReadinessETAPI(array $points): array
    {
        try {
            foreach ($points as &$point) {
                if (!empty($point['child'])) {
                    $completeCount = count(array_filter($point['child'], function ($child) {
                        return isset($child['status']) && $child['status'] === 'complete';
                    }));
                    $childrenCount = count($point['child']);
                    $point['readiness'] = ($childrenCount > 0)
                        ? round(($completeCount / $childrenCount) * 100, $this->roundCompletePercent)
                        : 0;
                    if($point['readiness'] == 100){
                        $point['status'] = 'complete';
                        $point['color'] = 'green';
                    }



//                    foreach ($point['child'] as $child) {
//                        // Инициализируем переменные для хранения самых поздних дат
//                        $latestPlan = $point['plan'] ?? null;
//                        $latestFact = $point['fact'] ?? null;
//
//                        // Преобразуем даты в объекты Carbon для удобства сравнения
//                        $childPlan = Carbon::parse($child['plan']);
//                        $childFact = Carbon::parse($child['fact']);
//
//                        // Сравниваем и обновляем самые поздние даты
//                        if (!$latestPlan || $childPlan->gt(Carbon::parse($latestPlan))) {
//                            $point['plan'] = $child['plan'];
//                        }
//
//                        if (!$latestFact || $childFact->gt(Carbon::parse($latestFact))) {
//                            $point['fact'] = $child['fact'];
//                        }
//
//                        // Если цвет дочернего элемента красный, устанавливаем цвет родительского элемента в красный
//                        if ($child['color'] === 'red') {
//                            $point['color'] = 'red';
//                        }
//                    }

                    $latestPlanCarbon = $point['plan'] ? Carbon::parse($point['plan']) : null;
                    $latestFactCarbon = $point['fact'] ? Carbon::parse($point['fact']) : null;

                    foreach ($point['child'] as $child) {
                        $childPlan = Carbon::parse($child['plan']);
                        $childFact = Carbon::parse($child['fact']);

                        // Сравниваем и обновляем самые поздние даты
                        if (!$latestPlanCarbon || $childPlan->greaterThan($latestPlanCarbon)) {
                            $point['plan'] = $child['plan'];
                            $latestPlanCarbon = $childPlan;
                        }

                        if (!$latestFactCarbon || $childFact->greaterThan($latestFactCarbon)) {
                            $point['fact'] = $child['fact'];
                            $latestFactCarbon = $childFact;
                        }

                        // Если цвет дочернего элемента красный, устанавливаем цвет родительского элемента в красный
                        if ($child['color'] === 'red') {
                            $point['color'] = 'red';
                        }
                    }

                } else {
                    $point['readiness'] = ($point['status'] === 'complete') ? 100 : 0;
                    unset($point['child']);
                }
            }
            unset($point);

            return $points;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри EtapiController/assignReadinessETAPI()', [
                'exception' => $th,
            ]);

            return [];
        }
    }

    /**
     * Формирует ответ для этапов реализации объекта для слайдера.
     *
     * Выбирает из всех верхнеуровневых элементов те, чьи названия совпадают с Библиотекой etapi_realizacii_library,
     * рассчитывает готовность и возвращает итоговый список.
     *
     * @param array $etapiRealizacii Массив этапов реализации.
     * @return array Список этапов для слайдера.
     */
    private function createAnswerEtapi(array $etapiRealizacii, $contractor): array
    {
        try {
            $indexed = $this->groupPoints($etapiRealizacii);
            $slider = [];
            // Берём только верхнеуровневые элементы
            foreach ($indexed as &$point) {
                // На случай, если в бд при парсинге записалось длинное название этапа реализации
                $point['name'] = $this->getCorrectEtapName($point['name']);

                // Проверяем:
                // Если в графе Исполнитель в бд записалос слово "генподрядчик", прикрепляем название подрядчика объекта
                // Если Исполнитель пустой, смотрим массив Этап=>Исполнитель
                // иначе выводим значение, записанное в бд под Исполнитель

                if (mb_strtolower($point['performer']) === 'генподрядчик') {
                    $point['performer'] = $contractor['name'] ?? '-';
                }

                $slider[] = $point;
                unset($point);
            }
            ksort($slider);

            return $this->assignReadinessETAPI(array_values($slider));
        } catch (\Throwable $th) {
            Log::error('ошибка внутри EtapiController/createAnswerEtapi()', [
                'exception' => $th,
            ]);

            return [];
        }
    }

    /**
     * На случай, если в бд записалось длинное название
     * @param $etapName
     * @return string
     */
    private function getCorrectEtapName($etapName): string
    {
        try {
            return $this->correctEtapNameArray[$etapName] ?? $etapName;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри EtapiController/getCorrectEtapName()', [
                'exception' => $th,
            ]);

            return '';
        }
    }

    private function controlPoints($object)
    {
        try {
            $controlPointsArray = $object->objectControlPoint->keyBy('stage_id')->toArray();
            $CP_IDS = $object->objectControlPoint->pluck('stage_id')->toArray();

            $controlPointsFACTURA = ControlPointsLibrary::getControlPointsEmptyFactureArray($CP_IDS);

            //      Проводим обогащение обогащение подготовленной структуры РЕАЛЬНЫМИ данными из БД
            $controlPointsFACTURA = $this->enrichTreeWithDataCP($controlPointsFACTURA, $controlPointsArray, $object?->contractor?->name, $object?->developer?->name, $object?->oiv?->short_name);

            //      Делаем так, чтобы каждый элемент дерева (в том силе и родители) имели все даты (от детей)
            $controlPointsFACTURA = $this->enrichParentsWithChildrenDataCP($controlPointsFACTURA);

            //        Удаляем элементы, где нет ни факта ни плана
            $controlPointsFACTURA = $this->removeTreeWithoutFactPlanData($controlPointsFACTURA);

            //      Раскрашиваем все элементы ставим статусы готовности
            $controlPointsFACTURA = $this->applyStatusAndColorToTreeCP($controlPointsFACTURA);

            // Сортируем все элементы (включая вложенные) по plan
            $sortByPlan = function (&$items) use (&$sortByPlan) {
                usort($items, function ($a, $b) {
                    return strtotime($a['plan']) <=> strtotime($b['plan']);
                });

                // Рекурсивно сортируем дочерние элементы
                foreach ($items as &$item) {
                    if (!empty($item['child'])) {
                        $sortByPlan($item['child']);
                    }
                }
            };
            $inProgress = [];
            $complete = [];

            // Сортируем детей внутри каждого родителя
            $this->sortChildrenByPlanDate($controlPointsFACTURA);

            foreach ($controlPointsFACTURA as $item) {
                // Определяем группу (inProgress или complete)
                if (!empty($item['fact_finish_date'])) {
                    $complete[] = $item;
                } else {
                    $inProgress[] = $item;
                }
            }

            // Сортируем родительские элементы в каждой группе
            usort($inProgress, [$this, 'sortByPlanDate']);
            usort($complete, [$this, 'sortByPlanDate']);

            $total = count($inProgress) + count($complete);
            $completePercent = $total > 0
                ? round((count($complete) / $total) * 100, $this->roundCompletePercent)
                : 0;

            return [
                'in_progress' => [
                    'count' => count($inProgress),
                    'data' => $inProgress
                ],
                'complete' => [
                    'count' => count($complete),
                    'data' => $complete
                ],
                'all' => $total,
                'complete_percent' => $completePercent,
            ];
        } catch (\Throwable $th) {
            Log::error('ошибка внутри EtapiController/controlPoints()', [
                'exception' => $th,
            ]);

            return null;
        }
    }

    /**
     * Сортирует детей внутри каждого родителя по plan_finish_date
     */
    private function sortChildrenByPlanDate(array &$tree): void
    {
        try {
            foreach ($tree as &$item) {
                if (!empty($item['children'])) {
                    usort($item['children'], [$this, 'sortByPlanDate']);
                }
            }
        } catch (\Throwable $th) {
            Log::error('ошибка внутри EtapiController/sortChildrenByPlanDate()', [
                'exception' => $th,
            ]);
        }
    }

    /**
     * Callback-функция для сортировки по plan_finish_date
     */
    private function sortByPlanDate(array $a, array $b): int
    {
        try {
            $aDate = strtotime($a['plan_finish_date'] ?? '');
            $bDate = strtotime($b['plan_finish_date'] ?? '');

            if ($aDate == $bDate) {
                return 0;
            }
            return ($aDate < $bDate) ? -1 : 1;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри EtapiController/sortByPlanDate()', [
                'exception' => $th,
            ]);

            return 0;
        }
    }


    /**
     * Обогащает древовидную структуру данными из плоского массива.
     *
     * @param array $tree Дерево элементов (ключи = id)
     * @param array $additionalData Данные для добавления (ключи = id)
     * @return array Обновлённое дерево
     */
    private function enrichTreeWithDataCP(array $tree, array $additionalData, $contactorName = null, $developerName = null, $oivShortName = null): array
    {
        try {
            foreach ($tree as $id => &$item) {
                // Если есть дополнительные данные для этого id — мержим их
                if (isset($additionalData[$id])) {
                    $item = array_merge($item, $additionalData[$id]);
                }

                if (mb_strtolower($item['performer']) === 'генподрядчик') {
                    $item['performer'] = $contactorName ?? '-';
                }

                if (mb_strtolower($item['performer']) === 'застройщик') {
                    $item['performer'] = $developerName ?? '-';
                }

                if (mb_strtolower($item['performer']) === 'грбс') {
                    $item['performer'] = $oivShortName ?? '-';
                }

                if (mb_strtolower($item['performer']) === 'фонд реновации') {
                    $item['performer'] = 'МФР';
                }

                // Рекурсивно обрабатываем детей
                if (!empty($item['children'])) {
                    $item['children'] = $this->enrichTreeWithDataCP($item['children'], $additionalData, $contactorName, $developerName, $oivShortName);
                }
            }
            return $tree;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри EtapiController/enrichTreeWithDataCP()', [
                'exception' => $th,
            ]);

            return [];
        }
    }

    /**
     * Обогащает родителей данными ПЛАНА И ФАКТА ОТ детей + чистит ненужное поле control_points_library
     */
    function enrichParentsWithChildrenDataCP(array &$tree): array
    {
        try {
            foreach ($tree as &$item) {
                // Удаляем ненужное поле
                unset($item['control_points_library']);

                // Если есть дети - обрабатываем их
                if (!empty($item['children'])) {
    //                 Рекурсивно обогащаем детей
                    $this->enrichParentsWithChildrenDataCP($item['children']);

                    // Собираем ВСЕ возможные даты детей
                    $planStartDates = [];
                    $factStartDates = [];
                    $planFinishDates = [];
                    $factFinishDates = [];

                    foreach ($item['children'] as $child) {
                        if (!empty($child['plan_start_date'])) $planStartDates[] = $child['plan_start_date'];
                        if (!empty($child['fact_start_date'])) $factStartDates[] = $child['fact_start_date'];
                        if (!empty($child['plan_finish_date'])) $planFinishDates[] = $child['plan_finish_date'];
                        if (!empty($child['fact_finish_date'])) $factFinishDates[] = $child['fact_finish_date'];
                    }

                    // Устанавливаем даты родителя ТОЛЬКО если есть данные у детей
                    if (!empty($planStartDates)) {
                        $item['plan_start_date'] = min($planStartDates);
                    }
                    if (!empty($factStartDates)) {
                        $item['fact_start_date'] = min($factStartDates);
                    }
                    if (!empty($planFinishDates)) {
                        $item['plan_finish_date'] = max($planFinishDates);
                    }
                    if (!empty($factFinishDates)) {
                        $item['fact_finish_date'] = max($factFinishDates);
                    }
                }
            }

            return $tree;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри EtapiController/enrichParentsWithChildrenDataCP()', [
                'exception' => $th,
            ]);

            return [];
        }
    }

    /**
     * Применяет статусы и цвета ко всем элементам дерева.
     *
     * @param array $tree Дерево контрольных точек (родители + дети)
     * @return array Дерево с добавленными status, color, readiness
     */
    function applyStatusAndColorToTreeCP(array &$tree): array
    {
        try {
//            $now = time(); // Текущая метка времени Unix
//            $deadlineDays = Config::getDeadLineDays() * 86400; // Дней в секундах

            $now = Carbon::today();
            $deadlineDays = Config::getDeadLineDays();

//            foreach ($tree as &$point) {
//                // Парсим даты (если они есть)
//                $planDate = !empty($point['plan_finish_date'])
//                    ? strtotime($point['plan_finish_date'])
//                    : null;
//                $factDate = !empty($point['fact_finish_date'])
//                    ? strtotime($point['fact_finish_date'])
//                    : null;
//
//                // Сохраняем исходные даты (если нужны в выводе)
//                $point['plan'] = $point['plan_finish_date'] ?? null;
//                $point['fact'] = $point['fact_finish_date'] ?? null;
//
//                // Рекурсивно обрабатываем детей (если есть)
//                if (!empty($point['children'])) {
//                    $this->applyStatusAndColorToTreeCP($point['children']);
//
//                    // Проверяем статусы и цвета детей
//                    $allChildrenComplete = true;
//                    $childrenReadinessSum = 0;
//                    $childrenCount = count($point['children']);
//
//                    foreach ($point['children'] as $child) {
//                        if ($child['status'] != 'complete') {
//                            $allChildrenComplete = false;
//                        }
//
//                        $childrenReadinessSum += $child['readiness'];
//                    }
//
//                    // Рассчитываем среднюю готовность детей
//                    $averageReadiness = $childrenCount > 0 ? $childrenReadinessSum / $childrenCount : 0;
//                    $averageReadiness = (int)$averageReadiness;
//                }
//
//                // Если есть дети, статус и готовность определяются по детям
//                if (!empty($point['children'])) {
//                    if ($allChildrenComplete) {
//                        $point['status'] = 'complete';
//                        $point['readiness'] = 100;
//                    } else {
//                        $point['status'] = 'inprogress';
//                        $point['readiness'] = $averageReadiness;
//                        // Удаляем факт дату у родителя, если он не complete
//                        unset($point['fact_finish_date']);
//                        $point['fact'] = null;
//                    }
//
//
//                    // Красный: если срок просрочен
//                    if ($planDate && $now > $planDate) {
//                        $point['color'] = 'red';
//                    } // Жёлтый: если срок близко (дедлайн в пределах Config::getDeadLineDays())
//                    elseif ($planDate && ($planDate - $now <= $deadlineDays) && $planDate >= $now) {
//                        $point['color'] = 'yellow';
//                    } // Белый: если срок ещё не близок
//                    else {
//                        $point['color'] = 'white';
//                    }
//
//                } // Если нет детей - логика как раньше
//                else {
//                    // Если нет фактической даты -> задача в работе
//                    if (!$factDate) {
//                        $point['status'] = 'inprogress';
//                        $point['readiness'] = 0;
//
//                        // Красный: если срок просрочен
//                        if ($planDate && $now > $planDate) {
//                            $point['color'] = 'red';
//                        } // Жёлтый: если срок близко (дедлайн в пределах Config::getDeadLineDays())
//                        elseif ($planDate && ($planDate - $now <= $deadlineDays) && $planDate >= $now) {
//                            $point['color'] = 'yellow';
//                        } // Белый: если срок ещё не близок
//                        else {
//                            $point['color'] = 'white';
//                        }
//                    } // Если есть факт. дата -> задача завершена
//                    else {
//                        $point['status'] = 'complete';
//                        $point['readiness'] = 100;
//                    }
//                }
//
//                // Цвет для родительских элементов (если они complete)
//                if ($point['status'] == 'complete') {
//                    // Зелёный: если плана не было или выполнили вовремя
//                    if (!$planDate) {
//                        $point['color'] = 'green';
//                    } // Красный: если выполнили позже плана
//                    elseif ($factDate > $planDate) {
//                        $point['color'] = 'red';
//                    } // Зелёный: если выполнили в срок
//                    else {
//                        $point['color'] = 'green';
//                    }
//                }
//            }


            foreach ($tree as &$point) {
                // Парсим даты через Carbon
                $planDateCarbon = !empty($point['plan_finish_date'])
                    ? Carbon::parse($point['plan_finish_date'])
                    : null;
                $factDateCarbon = !empty($point['fact_finish_date'])
                    ? Carbon::parse($point['fact_finish_date'])
                    : null;

                // Для родительских и дочерних элементов с детьми:
                if (!empty($point['children'])) {
                    // Красный: если срок просрочен (плановая дата в прошлом)
                    if ($planDateCarbon && $planDateCarbon->isPast()) {
                        $point['color'] = 'red';
                    } // Жёлтый: если срок близко (дедлайн в пределах Config::getDeadLineDays())
                    elseif ($planDateCarbon && $planDateCarbon->isFuture() && $planDateCarbon->diffInDays($now) <= $deadlineDays) {
                        $point['color'] = 'yellow';
                    } // Белый: если срок ещё не близок
                    else {
                        $point['color'] = 'white';
                    }
                }
                // Для элементов без детей:
                else {
                    if (!$factDateCarbon) {
                        $point['status'] = 'inprogress';
                        $point['readiness'] = 0;
                        // Красный: если срок просрочен (плановая дата в прошлом)
                        if ($planDateCarbon && $planDateCarbon->isPast()) {
                            $point['color'] = 'red';
                        } // Жёлтый: если срок близко (дедлайн в пределах Config::getDeadLineDays())
                        elseif ($planDateCarbon && $planDateCarbon->isFuture() && $planDateCarbon->diffInDays($now) <= $deadlineDays) {
                            $point['color'] = 'yellow';
                        } // Белый: если срок ещё не близок
                        else {
                            $point['color'] = 'white';
                        }
                    }
                }

                // Для завершенных элементов с опозданием:
                if ($point['status'] == 'complete') {
                    // Зелёный: если плана не было или выполнили вовремя (факт раньше или равен плану)
                    if (!$planDateCarbon) {
                        $point['color'] = 'green';
                    } // Красный: если выполнили позже плана (факт позже плана)
                    elseif ($factDateCarbon && $planDateCarbon && $factDateCarbon->greaterThan($planDateCarbon)) {
                        $point['color'] = 'red';
                    } // Зелёный: если выполнили в срок
                    else {
                        $point['color'] = 'green';
                    }
                }
            }


            return $tree;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри EtapiController/applyStatusAndColorToTreeCP()', [
                'exception' => $th,
            ]);

            return [];
        }
    }


    private function stroyGotovnost($object)
    {
        try {
            $fact = $object->readiness_percentage_fact ?? 0;
            $plan = $object->readiness_percentage_plan ?? 0;

            if ($fact < $plan) {
                $color = 'red';
            } elseif ($fact == 100) {
                $color = 'green';
            } else {
                $color = 'white';
            }

            return [
                'color' => $color,
                'fact' => $fact
            ];
        } catch (\Throwable $th) {
            Log::error('ошибка внутри EtapiController/stroyGotovnost()', [
                'exception' => $th,
            ]);

            return [];
        }
    }

    /**
     * Удаляет элементы дерева, у которых одновременно отсутствуют plan_finish_date и fact_finish_date,
     * а также нет дочерних элементов с такими датами.
     *
     * @param array $tree Дерево контрольных точек
     * @return array Отфильтрованное дерево
     */
    private function removeTreeWithoutFactPlanData(array $tree): array
    {
        try {
            $result = [];

            foreach ($tree as $item) {
                // Рекурсивно обрабатываем детей
                if (!empty($item['children'])) {
                    $item['children'] = $this->removeTreeWithoutFactPlanData($item['children']);
                }

                // Проверяем, нужно ли сохранять этот элемент
                $hasPlanOrFact = !empty($item['plan_finish_date']) || !empty($item['fact_finish_date']);
                $hasChildrenWithData = !empty($item['children']);

                // Сохраняем элемент, если у него есть даты или есть дети с датами
                if ($hasPlanOrFact || $hasChildrenWithData) {
                    $result[] = $item;
                }
            }

            return $result;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри EtapiController/removeTreeWithoutFactPlanData()', [
                'exception' => $th,
            ]);

            return [];
        }
    }

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
            Log::error('ошибка внутри EtapiController/calculatePercent()', [
                'exception' => $th,
            ]);

            return null;
        }
    }
}
