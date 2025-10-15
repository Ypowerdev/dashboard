<?php

namespace App\Services;
use App\Models\Library\ConstructionStagesLibrary;
use App\Models\ObjectConstructionStage;
use App\Models\ObjectModel;
use App\Services\ExcelServices\ExcelDataService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для подготовки данных о строительных этапах перед отправкой на Фронт
 */
class ObjectConstructionStagesViewService {

    /**
     * Получает данные о строительных этапах для OIV (Органа исполнительной власти).
     *
     * Метод собирает данные о выполнении этапов строительства, включая фактические и плановые значения,
     * а также вычисляет дельты (разницы) за неделю и месяц. Особое внимание уделяется этапу "Разработка ПД",
     * где применяется специальная логика расчета фактического выполнения на основе дочерних этапов.
     *
     * @param ObjectModel $object Объект строительства
     * @return array Массив данных о строительных этапах для OIV
     */
    public function getDataForOIV($object)
    {
        try {
            $constructionStageNames = ConstructionStagesLibrary::$constructionStageNames;
            $stagesStructureArr = ConstructionStagesLibrary::getConstructionStageEmptyFactureArray($object);
            $constructionStageLibIDS = ConstructionStagesLibrary::getRequiredConstructionLibraryIDSbyObjectType($object);
            // Получаем этапы строительства для объекта
            $constructionStagesOIV = ObjectConstructionStage::with([
                'constructionStagesLibrary',
                'constructionStagesLibrary.childrens',
            ])
                ->where('object_id', $object->id)
//                ->whereNotNull('oiv_fact')
//                ->whereNotNull('oiv_plan')
                ->whereHas('constructionStagesLibrary', function ($query) use ($constructionStageLibIDS) {
                    $query->whereIn('id',$constructionStageLibIDS);
                })
                ->get();

            // Убираем все элементы без oiv_fact кроме `РАЗРАБОТКА ПД`
            $constructionStagesOIV = $constructionStagesOIV
                ->filter(function ($item) use ($constructionStageNames) {
                    if ($item->constructionStagesLibrary->name === $constructionStageNames['DEVELOP_PD']) {
                        return true;
                    }
                    return $item->oiv_fact !== null;
                })
                ->values();

            $constructionStagesArr = $this->getConstructionStagesArr($object,$constructionStagesOIV);

            // Тут далее творится магия создания целостного датаСета для отрисовки Мы работаем с неизвестным состоянием по записям в БД, поэтому используются "неожиданные проверки"
            foreach ($stagesStructureArr as $key => &$oneStage){
                // Выбираем дата сеты, которые будем использовать, исходя из наличия записей в БД
                [$dataSet,$prevDataSet,$prevMonthDataSet] = $this->selectDataset($constructionStagesArr,$key);

                // Если не нашли ДАННЫХ по этому этапу за последние 5 недель методом selectDataset, совсем не нашли, тогда берем то, что есть, уже не важно когда
                if(!isset($dataSet) && !isset($prevDataSet) && !isset($prevMonthDataSet)){
                    $filteredStages = $constructionStagesOIV
                        ->when($oneStage['name'] !== $constructionStageNames['DEVELOP_PD'], function ($query) {
                            return $query->whereNotNull('oiv_fact');
                        })
                        ->where('construction_stages_library_id', $key)
                        ->sortByDesc('created_date')
                        ->values(); // Сбрасываем ключи для надежного доступа по индексу

                    $dataSet = $prevDataSet = $prevMonthDataSet = null;

                    if ($filteredStages->isNotEmpty()) {
                        $dataSet = $filteredStages->get(0);
                        $prevDataSet = $filteredStages->get(1) ?? $dataSet;
                        $prevMonthDataSet = $filteredStages->get(2) ?? $prevDataSet ?? $dataSet;
                    }

                    if (!isset($dataSet) || !isset($prevDataSet) || !isset($prevMonthDataSet)) {
                        unset($stagesStructureArr[$key]);
                        continue;
                    }

                    $dataSet = $dataSet->toArray();
                    $prevDataSet = $prevDataSet->toArray();
                    $prevMonthDataSet = $prevMonthDataSet->toArray();
                }

                if (!isset($dataSet) && !isset($prevDataSet) && !isset($prevMonthDataSet)) {
                    unset($stagesStructureArr[$key]);
                    continue;
                }

                $oneStage['oiv']['fact'] = !empty($dataSet['oiv_fact']) ? $dataSet['oiv_fact'] : 0;
                $oneStage['oiv']['plan'] = !empty($dataSet['oiv_plan']) ? $dataSet['oiv_plan'] : (!empty($prevDataSet['oiv_plan']) ? $prevDataSet['oiv_plan'] : 0);
                $oneStage['oiv']['delta_plan_week'] = (isset($dataSet['oiv_plan']) && isset($prevDataSet['oiv_plan'])) ? $this->countDelta($dataSet['oiv_plan'], $prevDataSet['oiv_plan']) : 0;
                $oneStage['oiv']['delta_plan_month'] = (isset($dataSet['oiv_plan']) && isset($prevMonthDataSet['oiv_plan'])) ? $this->countDelta($dataSet['oiv_plan'], $prevMonthDataSet['oiv_plan']) : 0;
                $oneStage['oiv']['delta_fact_week'] = (isset($dataSet['oiv_fact']) && isset($prevDataSet['oiv_fact'])) ? $this->countDelta($dataSet['oiv_fact'], $prevDataSet['oiv_fact']) : 0;
                $oneStage['oiv']['delta_fact_month'] = (isset($dataSet['oiv_fact']) && isset($prevMonthDataSet['oiv_fact'])) ? $this->countDelta($dataSet['oiv_fact'], $prevMonthDataSet['oiv_fact']) : 0;

                if(isset($oneStage['childrens']) && count($oneStage['childrens'])>0){
                    foreach ($oneStage['childrens'] as &$value){
                        //dd($value);
                        $keyChild = $value['id'];
                        [$dataSetChild,$prevDataSetChild,$prevMonthDataSetChild] = $this->selectDataset($constructionStagesArr,$keyChild);
                        if(empty($dataSetChild) && empty($prevDataSetChild) && empty($prevMonthDataSetChild)){
                            $filteredStages = $constructionStagesOIV
                                ->whereNotNull('oiv_fact')
//                                ->whereNotNull('oiv_plan')
                                ->where('construction_stages_library_id', $keyChild)
                                ->sortByDesc('created_date')
                                ->first();
                            $dataSetChild = $prevDataSetChild = $prevMonthDataSetChild = $filteredStages;
                        }
                        if (empty($dataSetChild) && empty($prevDataSetChild) && empty($prevMonthDataSetChild)) {
                           // unset($oneStage['childrens'][$keyChild]);
                        } else {
                            $value['fact_oiv'] = !empty($dataSetChild['oiv_fact']) ? $dataSetChild['oiv_fact'] : 0;

                            if ($oneStage['name'] === $constructionStageNames['DEVELOP_PD'] &&
                                in_array($value['name'], [$constructionStageNames['SUBMISSION'],$constructionStageNames['COMMENTS'],$constructionStageNames['FIXING'],$constructionStageNames['CONCLUSION']]))
                            {
                                $value['icon'] = $this->assignIcon($value['fact_oiv'], $oneStage['oiv']['plan']);
                            }

                        }
                        unset($value);
                    }
                    if ($oneStage['name'] === $constructionStageNames['DEVELOP_PD'] && $object['ssr_cost'])
                    {
                        $oneStage['childrens']['ssr_cost'] = [
                            'parent_id' => 1,
                            'name' => 'Стоимость по CCP',
                            'view_name' => 'Стоимость по CCP',
                            'value' => $object['ssr_cost'] ?? 0,
                        ];
                    }
                }
                unset($oneStage);
            }
            $stagesStructureArr = $this->mutateFactDevelopmentPD($stagesStructureArr, 'oiv');

            return array_values($stagesStructureArr);
        } catch (\Throwable $th) {
            Log::error('ошибка внутри ObjectConstructionStagesViewService/getDataForOIV()', [
                'exception' => $th,
            ]);

            return [];
        }
    }

    /**
     * Получает данные о строительных этапах для SMG (Строительно-монтажных работ).
     *
     * Аналогично getDataForOIV, но для данных SMG. Использует плановые значения из OIV для согласованности.
     * Также применяет специальную логику для этапа "Разработка ПД".
     *
     * @param ObjectModel $object Объект строительства
     * @return array Массив данных о строительных этапах для SMG
     */
    public function getDataForSMG($object)
    {
        try {
            $constructionStageNames = ConstructionStagesLibrary::$constructionStageNames;
            $stagesStructureArr = ConstructionStagesLibrary::getConstructionStageEmptyFactureArray($object);
            $constructionStageLibIDS = ConstructionStagesLibrary::getRequiredConstructionLibraryIDSbyObjectType($object);

            // Сначала получаем OIV данные для согласованности ПЛАНА
            $oivData = $this->getDataForOIV($object);
            $oivDataMap = [];
            foreach ($oivData as $stage) {
                $oivDataMap[$stage['id']] = [
                    'plan' => $stage['oiv']['plan'] ?? 0,
                    'delta_plan_week' => $stage['oiv']['delta_plan_week'] ?? 0,
                    'delta_plan_month' => $stage['oiv']['delta_plan_month'] ?? 0
                ];
            }

            // Получаем этапы строительства для SMG
            $constructionStagesSMG = ObjectConstructionStage::with([
                'constructionStagesLibrary',
                'constructionStagesLibrary.childrens',
            ])
                ->where('object_id', $object->id)
                ->whereNotNull('smg_fact')
                ->whereHas('constructionStagesLibrary', function ($query) use ($constructionStageLibIDS) {
                    $query->whereIn('id',$constructionStageLibIDS);
                })
                ->get();


            // Убираем все элементы, которые совпадают с любым из constructionStageNames - всё что связано в ПОДГОТОВКОЙ ПД
            $constructionStagesSMG = $constructionStagesSMG
                ->filter(function ($item) use ($constructionStageNames) {
                    // Получаем название текущего элемента
                    $currentName = $item->constructionStagesLibrary->name;

                    // Оставляем элемент только если его название НЕ входит в запрещенный список
                    return !in_array($currentName, $constructionStageNames, true);
                })
                ->values();

            // Преобразуем в массив (если нужно)
            $constructionStagesArr = $this->getConstructionStagesArr($object,$constructionStagesSMG);

            // Тут далее творится магия создания целостного датаСета для отрисовки Мы работаем с неизвестным состоянием по записям в БД, поэтому используются "неожиданные проверки"
            foreach ($stagesStructureArr as $key => &$oneStage){

                // Выбираем дата сеты, которые будем использовать, исходя из наличия записей в БД
                [$dataSet,$prevDataSet,$prevMonthDataSet] = $this->selectDataset($constructionStagesArr,$key);

                // Если не нашли ДАННЫХ по этому этапу за последние 5 недель методом selectDataset, совсем не нашли, тогда берем то, что есть, уже не важно когда
                if(!isset($dataSet) && !isset($prevDataSet) && !isset($prevMonthDataSet)){
                    $filteredStages = $constructionStagesSMG
                        ->when($oneStage['name'] !== $constructionStageNames['DEVELOP_PD'], function ($query) {
                            return $query->whereNotNull('smg_fact');
                        })
                        ->where('construction_stages_library_id', $key)
                        ->sortByDesc('created_date')
                        ->values(); // Сбрасываем ключи для надежного доступа по индексу

                    $dataSet = $prevDataSet = $prevMonthDataSet = null;

                    if ($filteredStages->isNotEmpty()) {
                        $dataSet = $filteredStages->get(0);
                        $prevDataSet = $filteredStages->get(1) ?? $dataSet;
                        $prevMonthDataSet = $filteredStages->get(2) ?? $prevDataSet ?? $dataSet;
                    }

                    if (!isset($dataSet) || !isset($prevDataSet) || !isset($prevMonthDataSet)) {
                        unset($stagesStructureArr[$key]);
                        continue;
                    }

                    $dataSet = $dataSet->toArray();
                    $prevDataSet = $prevDataSet->toArray();
                    $prevMonthDataSet = $prevMonthDataSet->toArray();
                }

                if (!isset($dataSet) && !isset($prevDataSet) && !isset($prevMonthDataSet)) {
                    unset($stagesStructureArr[$key]);
                    continue;
                }

                $oneStage['ano']['fact'] = !empty($dataSet['smg_fact']) ? $dataSet['smg_fact'] : 0;
                $oneStage['ano']['plan'] = !empty($dataSet['smg_plan']) ? $dataSet['smg_plan'] : (!empty($prevDataSet['smg_plan']) ? $prevDataSet['smg_plan'] : 0);
                $oneStage['ano']['delta_plan_week'] = (isset($dataSet['smg_plan']) && isset($prevDataSet['smg_plan'])) ? $this->countDelta($dataSet['smg_plan'], $prevDataSet['smg_plan']) : 0;
                $oneStage['ano']['delta_plan_month'] = (isset($dataSet['smg_plan']) && isset($prevMonthDataSet['smg_plan'])) ? $this->countDelta($dataSet['smg_plan'], $prevMonthDataSet['smg_plan']) : 0;
                $oneStage['ano']['delta_fact_week'] = (isset($dataSet['smg_fact']) && isset($prevDataSet['smg_fact'])) ? $this->countDelta($dataSet['smg_fact'], $prevDataSet['smg_fact']) : 0;
                $oneStage['ano']['delta_fact_month'] = (isset($dataSet['smg_fact']) && isset($prevMonthDataSet['smg_fact'])) ? $this->countDelta($dataSet['smg_fact'], $prevMonthDataSet['smg_fact']) : 0;

                // Используем заранее подготовленные OIV данные для согласованности
                if (isset($oivDataMap[$key])) {
                    $oneStage['oiv'] = [
                        'plan' => $oivDataMap[$key]['plan'],
                        'delta_plan_week' => $oivDataMap[$key]['delta_plan_week'],
                        'delta_plan_month' => $oivDataMap[$key]['delta_plan_month']
                    ];
                } else {
                    $oneStage['oiv'] = [
                        'plan' => 0,
                        'delta_plan_week' => 0,
                        'delta_plan_month' => 0
                    ];
                }

                if(isset($oneStage['childrens']) && count($oneStage['childrens'])>0){
                    foreach ($oneStage['childrens'] as &$value){
                        $keyChild = $value['id'];
                        [$dataSetChild,$prevDataSetChild,$prevMonthDataSetChild] = $this->selectDataset($constructionStagesArr,$keyChild);
                        if(empty($dataSetChild) && empty($prevDataSetChild) && empty($prevMonthDataSetChild)){
                            $filteredStages = $constructionStagesSMG
                                ->whereNotNull('smg_fact')
//                                ->whereNotNull('smg_plan')
                                ->where('construction_stages_library_id', $keyChild)
                                ->sortByDesc('created_date')
                                ->first();
                            $dataSetChild = $prevDataSetChild = $prevMonthDataSetChild = $filteredStages;
                        }
                        if(empty($dataSetChild) && empty($prevDataSetChild) && empty($prevMonthDataSetChild)){
                            unset($oneStage['childrens'][$keyChild]);
                        } else {
                            $value['fact_ano'] = !empty($dataSetChild['smg_fact']) ? $dataSetChild['smg_fact'] : 0;

                            if ($oneStage['name'] === $constructionStageNames['DEVELOP_PD'] &&
                                in_array($value['name'], [$constructionStageNames['SUBMISSION'],$constructionStageNames['COMMENTS'],$constructionStageNames['FIXING'],$constructionStageNames['CONCLUSION']]))
                            {
                                $value['icon'] = $this->assignIcon($value['fact_ano'], $oneStage['ano']['plan']);
                            }

                        }
                        unset($value);
                    }
                }
                unset($oneStage);
            }
            $stagesStructureArrSMG = $this->mutateFactDevelopmentPD($stagesStructureArr, 'ano');

            return array_values($stagesStructureArrSMG);
        } catch (\Throwable $th) {
            Log::error('ошибка внутри ObjectConstructionStagesViewService/getDataForSMG()', [
                'exception' => $th,
            ]);

            return [];
        }
    }

    /**
     * Получает данные о строительных этапах для AI (Строительно-монтажных работ).
     *
     * Аналогично getDataForOIV, но для данных AI. Использует плановые значения из OIV для согласованности.
     *
     * @param ObjectModel $object Объект строительства
     * @return array Массив данных о строительных этапах для AI
     */
    public function getDataForAI($object)
    {
        try {
//            $constructionStageLibIDS = ConstructionStagesLibrary::getRequiredConstructionLibraryIDSbyObjectType($object);

            // Сначала получаем OIV данные для согласованности ПЛАНА
            $oivData = $this->getDataForOIV($object);
            $oivDataMap = [];
            foreach ($oivData as $stage) {
                $oivDataMap[$stage['id']] = [
                    'plan' => $stage['oiv']['plan'] ?? 0,
                    'delta_plan_week' => $stage['oiv']['delta_plan_week'] ?? 0,
                    'delta_plan_month' => $stage['oiv']['delta_plan_month'] ?? 0
                ];
            }

            // Получаем этапы строительства для AI
            $constructionStagesAI = ObjectConstructionStage::with([
                'constructionStagesLibrary',
                'constructionStagesLibrary.childrens',
            ])
                ->where('object_id', $object->id)
                ->whereNotNull('ai_fact')
//                ->whereHas('constructionStagesLibrary', function ($query) use ($constructionStageLibIDS) {
//                    $query->whereIn('id',$constructionStageLibIDS);
//                })
                ->get()
                ->toArray();

            $outData = [];
            // Тут далее творится магия создания целостного датаСета для отрисовки Мы работаем с неизвестным состоянием по записям в БД, поэтому используются "неожиданные проверки"
            foreach ($constructionStagesAI as $key => &$oneStage){
                $outData[$key]['ai']['fact'] = $oneStage['ai_fact'];
                $outData[$key]['ai']['delta_plan_week'] = 0;
                $outData[$key]['ai']['delta_plan_month'] = 0;
                $outData[$key]['ai']['delta_fact_week'] = 0;
                $outData[$key]['ai']['delta_fact_month'] = 0;

                $outData[$key]['id'] = $oneStage['construction_stages_library']['id'];
                $outData[$key]['name'] = $oneStage['construction_stages_library']['name'];
                $outData[$key]['view_name'] = $oneStage['construction_stages_library']['view_name'];
                $outData[$key]['childrens'] = [];

                // Используем заранее подготовленные OIV данные для согласованности
                if (isset($oivDataMap[$key])) {
                    $outData[$key]['oiv'] = [
                        'plan' => $oivDataMap[$key]['plan'],
                        'delta_plan_week' => $oivDataMap[$key]['delta_plan_week'],
                        'delta_plan_month' => $oivDataMap[$key]['delta_plan_month']
                    ];
                } else {
                    $outData[$key]['oiv'] = [
                        'plan' => 0,
                        'delta_plan_week' => 0,
                        'delta_plan_month' => 0
                    ];
                }

                unset($oneStage);
            }

            return array_values($outData);
        } catch (\Throwable $th) {
            Log::error('ошибка внутри ObjectConstructionStagesViewService/getDataForAI()', [
                'exception' => $th,
            ]);

            return [];
        }
    }

    /**
     * Модифицирует фактические значения для этапа "Разработка ПД" по специальным правилам.
     *
     * Применяет правила расчета:
     * 1. Если НЕТ замечаний, и нет "Сдача документов в МГЭ"(факт) и "Получение заключение МГЭ (факт)" = 100%,
     *    ТОГДА "Разработка ПД (факт)" => 100% и "Сдача документов в МГЭ"(факт) => 100%
     * 2. Если есть замечания - Разработка ПД (факт) = 50% от СДАЧА ПД В МГЭ(факт) + 50% от СНЯТИЕ ЗАМЕЧАНИЙ(факт)
     * 3. В остальных случаях - Разработка ПД (факт) = 50% от СДАЧА ПД В МГЭ(факт)
     *
     * @param array $stagesStructureArr Массив этапов
     * @param string $postfix Суффикс для поля (oiv/ano)
     * @return array Модифицированный массив этапов
     */
    private function mutateFactDevelopmentPD($stagesStructureArr, $postfix) {
        try {
            $constructionStageNames = ConstructionStagesLibrary::$constructionStageNames;
            $factField = "fact_{$postfix}";

            // 1. Находим только этап "Разработка ПД"
            foreach ($stagesStructureArr as $key => &$oneStage) {
                if ($oneStage['name'] !== $constructionStageNames['DEVELOP_PD']) {
                    continue; // Пропускаем все другие этапы
                }

                if (empty($oneStage['childrens'])) {
                    continue; // Нет дочерних этапов - ничего не делаем
                }

                // 2. Собираем данные только по дочерним этапам "Разработки ПД"
                $submission = 0;
                $comments = null;
                $fixing = 0;
                $conclusion = 0;

                // Сохраняем ссылки на дочерние этапы для возможного изменения
                $submissionStage = null;
                $commentsStage = null;
                $fixingStage = null;
                $conclusionStage = null;

                foreach ($oneStage['childrens'] as &$child) {
                    switch ($child['name']) {
                        case $constructionStageNames['SUBMISSION']:
                            $submission = $child[$factField] ?? 0;
                            $submissionStage = &$child;
                            break;
                        case $constructionStageNames['COMMENTS']:
                            $comments = $child[$factField] ?? null;
                            $commentsStage = &$child;
                            break;
                        case $constructionStageNames['FIXING']:
                            $fixing = $child[$factField] ?? 0;
                            $fixingStage = &$child;
                            break;
                        case $constructionStageNames['CONCLUSION']:
                            $conclusion = $child[$factField] ?? 0;
                            $conclusionStage = &$child;
                            break;
                    }
                }
                unset($child); // Важно для предотвращения побочных эффектов

                // 3. Применяем новую логику ТОЛЬКО к этапу "Разработка ПД"

                // ПРАВИЛО 1: Если НЕТ замечаний, и нет "Сдача документов в МГЭ"(факт)
                // и "Получение заключение МГЭ (факт)" = 100%
                if (($comments === null || $comments == 0) && $submission == 0 && $conclusion == 100) {
                    // Устанавливаем факт "Разработка ПД" в 100%
                    $oneStage[$postfix]['fact'] = 100;

                    // Устанавливаем факт "Сдача документов в МГЭ" в 100%
                    if ($submissionStage !== null) {
                        $submissionStage[$factField] = 100;
                    }
                }

                // ПРАВИЛО 2: Если есть замечания
                elseif ($comments !== null && $comments > 0) {
                    $baseFact = $submission * 0.5;
                    $additionalFact = $fixing * 0.5;
                    $total = $baseFact + $additionalFact;

                    $oneStage[$postfix]['fact'] = min(100, $total);
                }

                // ПРАВИЛО 3: Все остальные случаи
                else {
                    $oneStage[$postfix]['fact'] = min(100, $submission * 0.5);
                }
            }
            unset($oneStage); // Важно для предотвращения побочных эффектов

        } catch (\Throwable $th) {
            Log::error('Ошибка в mutateFactDevelopmentPD()', [
                'error' => $th->getMessage(),
                'stage' => $oneStage['name'] ?? 'unknown',
                'trace' => $th->getTraceAsString()
            ]);
        }

        return $stagesStructureArr;
    }

    /**
     * Вычисляет разницу между текущим и предыдущим значением.
     *
     * @param mixed $now_fact Текущее значение
     * @param mixed $before_fact Предыдущее значение
     * @return string Разница в виде строки
     */
    private function countDelta($now_fact, $before_fact)
    {
        try {
            // Проверяем, что оба значения не пустые и могут быть преобразованы в числа
            if (!is_numeric($now_fact) || !is_numeric($before_fact)) {
                return '0';
            }

            // Вычисляем разницу
            return (int)$now_fact - (int)$before_fact;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри ObjectConstructionStagesViewService/countDelta()', [
                'exception' => $th,
            ]);

            return null;
        }
    }

    /**
     * Выбирает набор данных для конкретного этапа строительства.
     *
     * Ищет данные за последние 5 недель, отдавая приоритет более свежим данным.
     * Если свежих данных нет, использует более старые.
     *
     * @param array $constructionStagesArr Массив данных по этапам
     * @param int $key ID этапа строительства
     * @return array Массив с тремя наборами данных: текущий, предыдущий и месячной давности
     */
    private function selectDataset($constructionStagesArr,$key)
    {
        try {
            if (isset($constructionStagesArr['thisWeekMonday'][$key]) && !empty($constructionStagesArr['thisWeekMonday'][$key]) &&
                isset($constructionStagesArr['oneWeekBefore'][$key]) && !empty($constructionStagesArr['oneWeekBefore'][$key])) {
                $dataSet = $constructionStagesArr['thisWeekMonday'][$key];
                $prevDataSet = $constructionStagesArr['oneWeekBefore'][$key];
                $prevMonthDataSet = $constructionStagesArr['fourWeeksBefore'][$key] ?? $constructionStagesArr['fiveWeeksBefore'][$key] ?? [];
            }elseif (isset($constructionStagesArr['thisWeekMonday'][$key]) && !empty($constructionStagesArr['thisWeekMonday'][$key]) &&
                isset($constructionStagesArr['twoWeeksBefore'][$key]) && !empty($constructionStagesArr['twoWeeksBefore'][$key])) {
                $dataSet = $constructionStagesArr['thisWeekMonday'][$key];
                $prevDataSet = $constructionStagesArr['twoWeeksBefore'][$key];
                $prevMonthDataSet = $constructionStagesArr['fourWeeksBefore'][$key] ?? $constructionStagesArr['fiveWeeksBefore'][$key] ?? [];
            }elseif (isset($constructionStagesArr['thisWeekMonday'][$key]) && !empty($constructionStagesArr['thisWeekMonday'][$key]) &&
                isset($constructionStagesArr['threeWeeksBefore'][$key]) && !empty($constructionStagesArr['threeWeeksBefore'][$key])) {
                $dataSet = $constructionStagesArr['thisWeekMonday'][$key];
                $prevDataSet = $constructionStagesArr['threeWeeksBefore'][$key];
                $prevMonthDataSet = $constructionStagesArr['fiveWeeksBefore'][$key] ?? [];
            }elseif (isset($constructionStagesArr['oneWeekBefore'][$key]) && !empty($constructionStagesArr['oneWeekBefore'][$key]) &&
                isset($constructionStagesArr['twoWeeksBefore'][$key]) && !empty($constructionStagesArr['twoWeeksBefore'][$key])) {
                $dataSet = $constructionStagesArr['oneWeekBefore'][$key];
                $prevDataSet = $constructionStagesArr['twoWeeksBefore'][$key];
                $prevMonthDataSet = $constructionStagesArr['fourWeeksBefore'][$key] ?? $constructionStagesArr['fiveWeeksBefore'][$key] ?? [];
            }elseif (isset($constructionStagesArr['twoWeeksBefore'][$key]) && !empty($constructionStagesArr['twoWeeksBefore'][$key]) &&
                isset($constructionStagesArr['threeWeeksBefore'][$key]) && !empty($constructionStagesArr['threeWeeksBefore'][$key])) {
                $dataSet = $constructionStagesArr['twoWeeksBefore'][$key];
                $prevDataSet = $constructionStagesArr['threeWeeksBefore'][$key];
                $prevMonthDataSet = $constructionStagesArr['fiveWeeksBefore'][$key] ?? [];
            }elseif (isset($constructionStagesArr['threeWeeksBefore'][$key]) && !empty($constructionStagesArr['threeWeeksBefore'][$key]) &&
                isset($constructionStagesArr['fourWeeksBefore'][$key]) && !empty($constructionStagesArr['fourWeeksBefore'][$key])) {
                $dataSet = $constructionStagesArr['threeWeeksBefore'][$key];
                $prevDataSet = $constructionStagesArr['fourWeeksBefore'][$key];
                $prevMonthDataSet = $constructionStagesArr['fiveWeeksBefore'][$key] ?? [];
            } else {
                $dataSet = null;
                $prevDataSet = null;
                $prevMonthDataSet = null;
            }

            return [$dataSet,$prevDataSet,$prevMonthDataSet];
        } catch (\Throwable $th) {
            Log::error('ошибка внутри ObjectConstructionStagesViewService/selectDataset()', [
                'exception' => $th,
            ]);

            return null;
        }
    }

    /**
     * Формирует массив данных о строительных этапах, сгруппированных по неделям.
     *
     * Создает 6 наборов данных за последние 5 недель + текущую неделю.
     * Для сданных объектов игнорирует даты и использует все доступные данные.
     *
     * @param ObjectModel $object Объект строительства
     * @param $constructionStagesOIV_or_SMG
     * @return array Массив сгруппированных данных
     */
    private function getConstructionStagesArr($object, $constructionStagesOIV_or_SMG)
    {
        try {
            // Вычисление дат
            $thisWeekMonday = Carbon::now()->startOfWeek();
            $oneWeekBefore = $thisWeekMonday->copy()->subWeek();
            $twoWeeksBefore = $thisWeekMonday->copy()->subWeek(2);
            $threeWeeksBefore = $thisWeekMonday->copy()->subWeek(3);
            $fourWeeksBefore = $thisWeekMonday->copy()->subWeeks(4);
            $fiveWeeksBefore = $thisWeekMonday->copy()->subWeeks(5);


            // Группируем данные по нужным датам и construction_stages_library_id, Теперь у нас 5 датасетов за последние недели
            $groupedStages = [
                'thisWeekMonday' => $constructionStagesOIV_or_SMG
                    ->where('created_date', $thisWeekMonday)
                    ->keyBy('construction_stages_library_id') // Используем keyBy вместо groupBy
                    ->map(function ($item) {
                        return $item->toArray();
                    }),
                'oneWeekBefore' => $constructionStagesOIV_or_SMG
                    ->where('created_date', $oneWeekBefore)
                    ->keyBy('construction_stages_library_id') // Используем keyBy вместо groupBy
                    ->map(function ($item) {
                        return $item->toArray();
                    }),
                'twoWeeksBefore' => $constructionStagesOIV_or_SMG
                    ->where('created_date', $twoWeeksBefore)
                    ->keyBy('construction_stages_library_id') // Используем keyBy вместо groupBy
                    ->map(function ($item) {
                        return $item->toArray();
                    }),
                'threeWeeksBefore' => $constructionStagesOIV_or_SMG
                    ->where('created_date', $threeWeeksBefore)
                    ->keyBy('construction_stages_library_id') // Используем keyBy вместо groupBy
                    ->map(function ($item) {
                        return $item->toArray();
                    }),
                'fourWeeksBefore' => $constructionStagesOIV_or_SMG
                    ->where('created_date', $fourWeeksBefore)
                    ->keyBy('construction_stages_library_id') // Используем keyBy вместо groupBy
                    ->map(function ($item) {
                        return $item->toArray();
                    }),
                'fiveWeeksBefore' => $constructionStagesOIV_or_SMG
                    ->where('created_date', $fiveWeeksBefore)
                    ->keyBy('construction_stages_library_id') // Используем keyBy вместо groupBy
                    ->map(function ($item) {
                        return $item->toArray();
                    }),
            ];
            // Преобразуем в массив (если нужно)
            $constructionStagesArr = collect($groupedStages)->toArray();

            // признак того, что 4 недели данных по объекту нет, скорее всего объект уже СДАН, проверяем это и выводим старые данные
            if (empty($constructionStagesArr['thisWeekMonday']) &&
                empty($constructionStagesArr['oneWeekBefore']) &&
                empty($constructionStagesArr['twoWeeksBefore']) &&
                empty($constructionStagesArr['threeWeeksBefore'])) {
                $libraryID = ConstructionStagesLibrary::where('name', ConstructionStagesLibrary::NAME_STROYGOTOVNOST)->pluck('id')->first();
                $recordsAboutREADINESS = ObjectConstructionStage::where('construction_stages_library_id', $libraryID)
                    ->where('object_id', $object->id)
                    ->where('oiv_fact', 100)
                    ->get()
                    ->toArray();
                // Есть записи, что объект уже СДАН - переписываем $groupedStages, игонорируя даты записей
                if (count($recordsAboutREADINESS) > 0) {

                    $groupedStages = [
                        'thisWeekMonday' => $constructionStagesOIV_or_SMG
                            ->keyBy('construction_stages_library_id') // Используем keyBy вместо groupBy
                            ->map(function ($item) {
                                return $item->toArray();
                            }),
                        'oneWeekBefore' => $constructionStagesOIV_or_SMG
                            ->keyBy('construction_stages_library_id') // Используем keyBy вместо groupBy
                            ->map(function ($item) {
                                return $item->toArray();
                            }),
                        'twoWeeksBefore' => $constructionStagesOIV_or_SMG
                            ->keyBy('construction_stages_library_id') // Используем keyBy вместо groupBy
                            ->map(function ($item) {
                                return $item->toArray();
                            }),
                        'threeWeeksBefore' => $constructionStagesOIV_or_SMG
                            ->keyBy('construction_stages_library_id') // Используем keyBy вместо groupBy
                            ->map(function ($item) {
                                return $item->toArray();
                            }),
                        'fourWeeksBefore' => $constructionStagesOIV_or_SMG
                            ->keyBy('construction_stages_library_id') // Используем keyBy вместо groupBy
                            ->map(function ($item) {
                                return $item->toArray();
                            }),
                        'fiveWeeksBefore' => $constructionStagesOIV_or_SMG
                            ->keyBy('construction_stages_library_id') // Используем keyBy вместо groupBy
                            ->map(function ($item) {
                                return $item->toArray();
                            }),
                    ];

                    $constructionStagesArr = collect($groupedStages)->toArray();
                }
            }

            return $constructionStagesArr;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри ObjectConstructionStagesViewService/getConstructionStagesArr()', [
                'exception' => $th,
            ]);

            return null;
        }
    }

    /**
     * Определяет иконку для дочерних этапов "Разработки ПД" на основе выполнения.
     *
     * @param int $fact Фактическое выполнение
     * @param int $plan Плановое выполнение
     * @return string Название иконки (green/red/grey)
     */
    private function assignIcon(int $fact, int $plan): string
    {
        try {
            if ($fact == 100) {
                return 'green';
            } else {
                return ($plan == 100) ? 'red' : 'grey';
            }
        } catch (\Throwable $th) {
            Log::error('ошибка внутри ObjectConstructionStagesViewService/assignIcon()', [
                'exception' => $th,
            ]);

            return '';
        }
    }

    public function setObjectConstructionStagePlanStartDateByName(ObjectModel $object, string $constructionStageName, string $planStartDate): bool
    {
        $constructionStage = $this->findConstructionStageByName($constructionStageName);

        if (!$constructionStage) {
            return false;
        }

        // Обновляем или создаем запись в pivot таблице
        $object->constructionStagesWithPivot()->syncWithoutDetaching([
            $constructionStage->id => ['oiv_plan_start' => ExcelDataService::convertDateToDbFormat($planStartDate) !== '' ? ExcelDataService::convertDateToDbFormat($planStartDate) : null]
        ]);

        return true;
    }

    public function setObjectConstructionStagePlanFinishDateByName(ObjectModel $object, string $constructionStageName, string $planFinishDate): bool
    {
        $constructionStage = $this->findConstructionStageByName($constructionStageName);

        if (!$constructionStage) {
            return false;
        }

        // Обновляем или создаем запись в pivot таблице
        $object->constructionStagesWithPivot()->syncWithoutDetaching([
            $constructionStage->id => ['oiv_plan_finish' => ExcelDataService::convertDateToDbFormat($planFinishDate) !== '' ? ExcelDataService::convertDateToDbFormat($planFinishDate) : null]
        ]);

        return true;
    }

    public function setObjectConstructionStagePlanByName(ObjectModel $object, string $constructionStageName, string $plan): bool
    {
        $constructionStage = $this->findConstructionStageByName($constructionStageName);

        if (!$constructionStage) {
            return false;
        }

        // Обновляем или создаем запись в pivot таблице
        $object->constructionStagesWithPivot()->syncWithoutDetaching([
            $constructionStage->id => ['oiv_plan' => $plan ?? null]
        ]);

        return true;
    }

    public function setObjectConstructionStageFactByName(ObjectModel $object, string $constructionStageName, string $fact): bool
    {
        $constructionStage = $this->findConstructionStageByName($constructionStageName);

        if (!$constructionStage) {
            return false;
        }

        // Обновляем или создаем запись в pivot таблице
        $object->constructionStagesWithPivot()->syncWithoutDetaching([
            $constructionStage->id => ['oiv_fact' => $fact ?? null]
        ]);

        return true;
    }

    /**
     * Найти этап по имени (с очисткой от скобок и модификаторов)
     *
     * @param string $constructionStageName
     * @return ConstructionStagesLibrary|null
     */
    private function findConstructionStageByName(string $constructionStageName): ?ConstructionStagesLibrary
    {
        $cleanName = $this->cleanConstructionStageName($constructionStageName);

        return ConstructionStagesLibrary::where('name', $cleanName)->first();
    }

    /**
     * Очистить название этапа строительства от модификаторов (плановая дата завершения/плановая дата начала) и скобок
     *
     * @param string $constructionStageName
     * @return string
     */
    private function cleanConstructionStageName(string $constructionStageName): string
    {
        // Удаляем "(плановая дата начала)", "(плановая дата завершения)" и лишние пробелы
        return trim(str_replace(['(плановая дата начала)', '(плановая дата завершения)'], '', $constructionStageName));
    }

}
