<?php

namespace App\Http\Controllers\Api;

use App\Helpers\JsonHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\FiltersDataHomePageRequest;
use App\Models\Library\FnoLevelLibrary;
use App\Models\ObjectModel;
use App\Repositories\Interfaces\ObjectModelRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер HomeController отвечает за предоставление сводной информации об объектах.
 *
 * Этот контроллер предоставляет API для получения общей статистики по объектам, включая количество объектов
 * и общую площадь в различных категориях (все объекты, работы не начаты, ПИР, СМР, введено в эксплуатацию).
 */
class HomeController extends Controller
{
    /** @var ObjectModelRepositoryInterface */
    private $objectModelRepository;

    /**
     * Конструктор контроллера
     *
     * @param ObjectModelRepositoryInterface $objectModelRepository Репозиторий для работы с объектами
     */
    public function __construct(ObjectModelRepositoryInterface $objectModelRepository)
    {
        $this->objectModelRepository = $objectModelRepository;
    }

    /**
     * Реализует API: /api/main/getJsonFilter
     * Возвращает Json Объект-Библиотеку для Дополнительного фильтра на главной странице
     * @return JsonResponse
     */
    public function getJsonFilter(): JsonResponse
    {
        try {
            $levels = FnoLevelLibrary::all();
            $tree = [];

            foreach ($levels as $level) {
                $lvl1 = [
                    'id' => $level->lvl1_code,
                    'name' => $level->lvl1_name,
                    'lvl2' => []
                ];

                $lvl2 = [
                    'id' => $level->lvl2_code,
                    'name' => $level->lvl2_name,
                    'lvl3' => []
                ];

                $lvl3 = [
                    'id' => $level->lvl3_code,
                    'name' => $level->lvl3_name,
                    'lvl4' => []
                ];

                $lvl4 = [
                    'id' => $level->lvl4_code,
                    'name' => $level->lvl4_name
                ];

                if ($lvl2['lvl3']) {
                    $lvl3Index = $this->findLevelIndex($lvl2['lvl3'], $lvl3['id'], 'lvl3');
                    if ($lvl3Index === false) {
                        $lvl3['lvl4'][] = $lvl4;
                        $lvl2['lvl3'][] = $lvl3;
                    } else {
                        // Проверяем, есть ли уже такой lvl4 в lvl3
                        $existingLvl4Ids = array_column($lvl2['lvl3'][$lvl3Index]['lvl4'], 'id');
                        if (!in_array($lvl4['id'], $existingLvl4Ids)) {
                            $lvl2['lvl3'][$lvl3Index]['lvl4'][] = $lvl4;
                        }
                    }
                }

                $lvl2Index = $this->findLevelIndex($lvl1['lvl2'], $lvl2['id'], 'lvl2');
                if ($lvl2Index === false) {
                    $lvl1['lvl2'][] = $lvl2;
                } else {
                    // Если lvl2 уже существует, добавляем lvl3 в существующий lvl2
                    $lvl3Index = $this->findLevelIndex($lvl1['lvl2'][$lvl2Index]['lvl3'], $lvl3['id'], 'lvl3');
                    if ($lvl3Index === false) {
                        $lvl1['lvl2'][$lvl2Index]['lvl3'][] = $lvl3;
                    } else {
                        // Проверяем, есть ли уже такой lvl4 в lvl3
                        $existingLvl4Ids = array_column($lvl1['lvl2'][$lvl2Index]['lvl3'][$lvl3Index]['lvl4'], 'id');
                        if (!in_array($lvl4['id'], $existingLvl4Ids)) {
                            $lvl1['lvl2'][$lvl2Index]['lvl3'][$lvl3Index]['lvl4'][] = $lvl4;
                        }
                    }
                }

                $lvl1Index = $this->findLevelIndex($tree, $lvl1['id'], 'lvl1');
                if ($lvl1Index === false) {
                    $tree[] = $lvl1;
                } else {
                    $lvl2Index = $this->findLevelIndex($tree[$lvl1Index]['lvl2'], $lvl2['id'], 'lvl2');
                    if ($lvl2Index === false) {
                        $tree[$lvl1Index]['lvl2'][] = $lvl2;
                    } else {
                        if ($lvl3['id']) {
                            $lvl3Index = $this->findLevelIndex($tree[$lvl1Index]['lvl2'][$lvl2Index]['lvl3'], $lvl3['id'], 'lvl3');
                            if ($lvl3Index === false) {
                                $tree[$lvl1Index]['lvl2'][$lvl2Index]['lvl3'][] = $lvl3;
                            } else {
                                $existingLvl4Ids = array_column($tree[$lvl1Index]['lvl2'][$lvl2Index]['lvl3'][$lvl3Index]['lvl4'], 'id');
                                if (!in_array($lvl4['id'], $existingLvl4Ids)) {
                                    $tree[$lvl1Index]['lvl2'][$lvl2Index]['lvl3'][$lvl3Index]['lvl4'][] = $lvl4;
                                }
                            }
                        }
                    }
                }
            }

            $result = ['lvl1' => $tree];
            $result['lvl1'][0]['lvl2'] = [
                [
                    'id' => 1,
                    'name' => "Реновация",
                    'lvl3' => []
                ],
                [
                    'id' => 2,
                    'name' => "Не реновация",
                    'lvl3' => []
                ]
            ];

            return response()->json($result, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Вспомогательная функция для поиска индекса уровня в массиве
     * @param array $levels Массив уровней для поиска
     * @param int $id Идентификатор искомого уровня
     * @param string $levelType Тип уровня (lvl1, lvl2, lvl3, lvl4)
     * @return int|false Индекс найденного элемента или false если не найден
     */
    private function findLevelIndex(array $levels, int $id, string $levelType): int|false
    {
        try {
            foreach ($levels as $index => $level) {
                if ($level['id'] === $id) {
                    return $index;
                }
            }
            return false;
        } catch (\Throwable $th) {
            Log::error('ошибка внутри HomeController/findLevelIndex()', [
                'exception' => $th,
            ]);

            return false;
        }
    }

    /**
     * Возвращает Json Объект-Данные для дашборда мониторинга с учётом настроек фильтров
     *
     * Метод обрабатывает параметры фильтрации и возвращает статистику по объектам:
     * - Общее количество объектов и их площадь
     * - Количество и площадь объектов по категориям (не начаты, ПИР, СМР, введены)
     * - Дельту изменений за указанный период по каждой категории
     *
     * @param FiltersDataHomePageRequest $request Валидированный запрос с параметрами фильтрации
     * @return JsonResponse Отфильтрованные данные в формате JSON
     * @throws \Throwable При ошибках выполнения запроса
     */
    public function getFilteredDataHomePage(FiltersDataHomePageRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $filterOptionsData = [
                'days_before' => $validated['filter_data']['days_before'] ?? 7,
                'aip_years' => $validated['filter_data']['aip_years'] ?? null,
                'commissioning_years' => $validated['filter_data']['commissioning_years'] ?? null,

                'aip' => $validated['filter_data']['aip_id'] ?? null,
                'podved_inns' => $validated['filter_data']['podved_inns'] ?? null,

                'aip_flag' => $validated['filter_data']['aip_flag'] ?? null,
                'oivs' => $validated['filter_data']['oiv_id'] ?? null,
                'lvl1_id' => $validated['filter_data']['lvl1_id'] ?? null,
                'lvl2_id' => $validated['filter_data']['lvl2_id'] ?? null,
                'lvl3_id' => $validated['filter_data']['lvl3_id'] ?? null,
                'lvl4_ids' => $validated['filter_data']['lvl4_ids'] ?? null,
                'is_object_directive' => $validated['filter_data']['is_object_directive'] ?? null,
                'fno_engineering' => $validated['filter_data']['fno_engineering'] ?? null,
            ];

            $daysBefore = $filterOptionsData['days_before'] ?? 7;

            // Получаем все объекты
            $allObjects = $this->objectModelRepository->getFilteredDataHomePage($filterOptionsData);

            // Получаем объекты, у которых работы не начаты (статусы 1, 8, 15)
            $objectsWorkNotStarted = clone $allObjects;
            $objectsWorkNotStarted->whereIn('oks_status_id', [1]);
            $objectsWorkNotStartedNew = clone $objectsWorkNotStarted;
            $objectsWorkNotStartedNew = $this->objectModelRepository->getDelta([1], $objectsWorkNotStartedNew, $daysBefore);

            $objectsWorkNotStartedViolation = clone $objectsWorkNotStarted;
            $objectsWorkNotStartedViolation = $this->objectModelRepository->applyDeadlineHighRiskFilter($objectsWorkNotStartedViolation);
            $objectsWorkNotStartedViolationNew = clone $objectsWorkNotStartedViolation;
            $objectsWorkNotStartedViolationNew = $this->objectModelRepository->getDelta([1], $objectsWorkNotStartedViolationNew, $daysBefore);

            $objectsWorkNotStartedMissedDeadlines = clone $objectsWorkNotStarted;
            $objectsWorkNotStartedMissedDeadlines = $this->objectModelRepository->applyDeadlineFailureFilter($objectsWorkNotStartedMissedDeadlines);
            $objectsWorkNotStartedMissedDeadlinesNew = clone $objectsWorkNotStartedMissedDeadlines;
            $objectsWorkNotStartedMissedDeadlinesNew = $this->objectModelRepository->getDelta([1], $objectsWorkNotStartedMissedDeadlinesNew, $daysBefore);

            // Получаем объекты, у которых работы ПИР (статусы 2, 3)
            $objectsPIR = clone $allObjects;
            $objectsPIR->whereIn('oks_status_id', [2, 3]);
            $objectsPIRNew = clone $objectsPIR;
            $objectsPIRNew = $this->objectModelRepository->getDelta([2, 3], $objectsPIRNew, $daysBefore);

            $objectsPIRViolation = clone $objectsPIR;
            $objectsPIRViolation = $this->objectModelRepository->applyDeadlineHighRiskFilter($objectsPIRViolation);
            $objectsPIRViolationNew = clone $objectsPIRViolation;
            $objectsPIRViolationNew = $this->objectModelRepository->getDelta([2, 3], $objectsPIRViolationNew, $daysBefore);

            $objectsPIRMissedDeadlines = clone $objectsPIR;
            $objectsPIRMissedDeadlines = $this->objectModelRepository->applyDeadlineFailureFilter($objectsPIRMissedDeadlines);
            $objectsPIRMissedDeadlinesNew = clone $objectsPIRMissedDeadlines;
            $objectsPIRMissedDeadlinesNew = $this->objectModelRepository->getDelta([2, 3], $objectsPIRMissedDeadlinesNew, $daysBefore);


            // Получаем объекты, у которых работы СМР (статусы 4, 5)
            $objectsSMR = clone $allObjects;
            $objectsSMR->whereIn('oks_status_id', [4, 5]);
            $objectsSMRNew = clone $objectsSMR;
            $objectsSMRNew = $this->objectModelRepository->getDelta([4, 5], $objectsSMRNew, $daysBefore);

            $objectsSMRAllFine = clone $objectsSMR;
            $objectsSMRAllFine = $this->objectModelRepository->applyDeadlineOnlyLowRiskFilter($objectsSMRAllFine);
            $objectsSMRAllFineNew = clone $objectsSMRAllFine;
            $objectsSMRAllFineNew = $this->objectModelRepository->getDelta([4, 5],  $objectsSMRAllFineNew, $daysBefore);

            $objectsSMRViolation = clone $objectsSMR;
            $objectsSMRViolation = $this->objectModelRepository->applyDeadlineHighRiskFilter($objectsSMRViolation);
            $objectsSMRViolationNew = clone $objectsSMRViolation;
            $objectsSMRViolationNew = $this->objectModelRepository->getDelta([4, 5], $objectsSMRViolationNew, $daysBefore);

            $objectsSMRMissedDeadlines = clone $objectsSMR;
            $objectsSMRMissedDeadlines = $this->objectModelRepository->applyDeadlineFailureFilter($objectsSMRMissedDeadlines);
            $objectsSMRMissedDeadlinesNew = clone $objectsSMRMissedDeadlines;
            $objectsSMRMissedDeadlinesNew = $this->objectModelRepository->getDelta([4, 5], $objectsSMRMissedDeadlinesNew, $daysBefore);


            // Получаем объекты, которые введены в эксплуатацию (статусы 6, 7)
            $objectsDONE = clone $allObjects;
            $objectsDONE->whereIn('oks_status_id', [6, 7]);
            $objectsDONENew = clone $objectsDONE;
            $objectsDONENew = $this->objectModelRepository->getDelta([6, 7], $objectsDONENew, $daysBefore);

            $objectsDONEViolation = clone $objectsDONE;
            $objectsDONEViolation = $this->objectModelRepository->applyDeadlineHighRiskFilter($objectsDONEViolation);
            $objectsDONEViolationNew = clone $objectsDONEViolation;
            $objectsDONEViolationNew = $this->objectModelRepository->getDelta([6, 7], $objectsDONEViolationNew, $daysBefore);

            $objectsDONEMissedDeadlines = clone $objectsDONE;
            $objectsDONEMissedDeadlines = $this->objectModelRepository->applyDeadlineFailureFilter($objectsDONEMissedDeadlines);
            $objectsDONEMissedDeadlinesNew = clone $objectsDONEMissedDeadlines;
            $objectsDONEMissedDeadlinesNew = $this->objectModelRepository->getDelta([6, 7], $objectsDONEMissedDeadlinesNew, $daysBefore);

            return response()->json([
                'panel_all' => [
                    'name' => 'Всего',
                    'objects' => [
                        [
                            'count' => number_format($allObjects->count(), decimals: 0, thousands_separator: ' '), // Количество всех объектов
                            'area' => number_format($allObjects->sum('area') / 1000000, decimals: 2, decimal_separator: ','), // Общая площадь всех объектов
                            'length' => number_format($allObjects->sum('length') / 1000, decimals: 2, decimal_separator: ',') // Общая протяжённость всех объектов, у которых есть протяжённость
                        ]
                    ],
                ],
                'panel_work_not_started' => [
                    'name' => 'Работы не начаты',
                    'objects' => [
                        'count' => number_format($objectsWorkNotStarted->count(), decimals: 0, thousands_separator: ' '), // Количество объектов, у которых работы не начаты
                        'area' => number_format($objectsWorkNotStarted->sum('area') / 1000000, decimals: 2, decimal_separator: ','), // Общая площадь таких объектов
                        'delta' => [
                            'count' => $objectsWorkNotStartedNew->count() - $objectsPIRNew->count(), // Дельта количества объектов, у которых работы не начаты
                            'area' => number_format((($objectsWorkNotStartedNew->sum('area') - $objectsPIRNew->sum('area'))) / 1000000, 2, decimal_separator: ','), // Общая площадь дельты объектов, у которых работы не начаты
                        ],
                        'length' => number_format($objectsWorkNotStarted->sum('length') / 1000, decimals: 2, decimal_separator: ',')
                    ],
                    'violation_of_deadlines' => [
                        'count' => number_format(($objectsWorkNotStartedViolation->count() ?? 0), decimals: 0, thousands_separator: ' '),
                        'area' => number_format(($objectsWorkNotStartedViolation->sum('area') ?? 0) / 1000000, decimals: 2, decimal_separator: ','),
                        'delta' => [
                            'count' => number_format(($objectsWorkNotStartedViolationNew->count() ?? 0) - ($objectsPIRViolationNew->count() ?? 0), decimals: 0, decimal_separator: ' '),
                            'area' => number_format((($objectsWorkNotStartedViolationNew->sum('area') ?? 0) - ($objectsPIRViolationNew->sum('area') ?? 0)) / 1000000, decimals: 2, decimal_separator: ','),
                        ],
                        'length' => number_format($objectsWorkNotStartedViolation->sum('length') / 1000, decimals: 2, decimal_separator: ',')
                    ],
                    'missed_deadlines' => [
                        'count' => number_format($objectsWorkNotStartedMissedDeadlines->count() ?? 0, decimals: 0, thousands_separator: ' '),
                        'area' => number_format(($objectsWorkNotStartedMissedDeadlines->sum('area') ?? 0) / 1000000, decimals: 2, decimal_separator: ','),
                        'delta' => [
                            'count' => number_format(($objectsWorkNotStartedMissedDeadlinesNew->count() ?? 0) - ($objectsPIRMissedDeadlinesNew->count() ?? 0), decimals: 0, decimal_separator: ','),
                            'area' => number_format((($objectsWorkNotStartedMissedDeadlinesNew->sum('area') ?? 0) - ($objectsPIRMissedDeadlinesNew->sum('area') ?? 0)) / 1000000, decimals: 2, decimal_separator: ','),
                        ],
                        'length' => number_format($objectsWorkNotStartedMissedDeadlines->sum('length') / 1000, decimals: 2, decimal_separator: ',')
                    ]
                ],
                'panel_pir' => [
                    'name' => 'ПИР',
                    'objects' => [
                        'count' => number_format($objectsPIR->count(), decimals: 0, thousands_separator: ' '), // Количество объектов, у которых работы не начаты
                        'area' => number_format($objectsPIR->sum('area') / 1000000, decimals: 2, decimal_separator: ','), // Общая площадь таких объектов
                        'delta' => [
                            'count' => number_format($objectsPIRNew->count() - $objectsSMRNew->count(), decimals: 0, decimal_separator: ','), // Дельта количества объектов, у которых работы не начаты
                            'area' => number_format(
                                ($objectsPIRNew->sum('area') - $objectsSMRNew->sum('area') / 1000000)
                                , decimals: 2, decimal_separator: ',') // Общая площадь дельты объектов, у которых работы не начаты
                        ],
                        'length' => number_format($objectsPIR->sum('length') / 1000, decimals: 2, decimal_separator: ',')
                    ],
                    'violation_of_deadlines' => [
                        'count' => number_format($objectsPIRViolation->count() ?? 0, decimals: 0, thousands_separator: ','),
                        'area' => number_format(($objectsPIRViolation->sum('area') ?? 0) / 1000000, decimals: 2, decimal_separator: ','),
                        'delta' => [
                            'count' => number_format(($objectsPIRViolationNew->count() ?? 0) - ($objectsSMRViolationNew->count() ?? 0), decimals: 0, decimal_separator: ','),
                            'area' => number_format((($objectsPIRViolationNew->sum('area') ?? 0) - ($objectsSMRViolationNew->sum('area') ?? 0)) / 1000000, decimals: 2, decimal_separator: ','),
                        ],
                        'length' => number_format($objectsPIRViolation->sum('length') / 1000, decimals: 2, decimal_separator: ',')
                    ],
                    'missed_deadlines' => [
                        'count' => number_format($objectsPIRMissedDeadlines->count() ?? 0, decimals: 0, thousands_separator: ','),
                        'area' => number_format(($objectsPIRMissedDeadlines->sum('area') ?? 0) / 1000000, decimals: 2, decimal_separator: ','),
                        'delta' => [
                            'count' => number_format(($objectsPIRMissedDeadlinesNew->count() ?? 0) - ($objectsSMRMissedDeadlinesNew->count() ?? 0), decimals: 0, decimal_separator: ','),
                            'area' => number_format((($objectsPIRMissedDeadlinesNew->sum('area') ?? 0) - ($objectsSMRMissedDeadlinesNew->sum('area') ?? 0)) / 1000000, decimals: 2, decimal_separator: ','),
                        ],
                        'length' => number_format($objectsPIRMissedDeadlines->sum('length') / 1000, decimals: 2, decimal_separator: ',')
                    ]
                ],
                'panel_smr' => [
                    'name' => 'СМР',
                    'objects' => [
                        'count' => number_format($objectsSMR->count(), decimals: 0, thousands_separator: ' '), // Количество объектов, у которых работы не начаты
                        'area' => number_format($objectsSMR->sum('area') / 1000000, 2, decimal_separator: ','), // Общая площадь таких объектов
                        'delta' => [
                            'count' => number_format($objectsSMRNew->count() - $objectsDONENew->count(), decimals: 0, decimal_separator: ','), // Дельта количества объектов, у которых работы не начаты
                            'area' => number_format(
                                ($objectsSMRNew->sum('area') - $objectsDONENew->sum('area') / 1000000)
                                , decimals: 2, decimal_separator: ',') // Общая площадь дельты объектов, у которых работы не начаты
                        ],
                        'length' => number_format($objectsSMR->sum('length') / 1000, decimals: 2, decimal_separator: ',')
                    ],
                    'schedule_input' => [
                        'count' => number_format($objectsSMRAllFine->count() ?? 0, decimals: 0, decimal_separator: ','),
                        'area' => number_format(($objectsSMRAllFine->sum('area') ?? 0) / 1000000, decimals: 2, decimal_separator: ','),
                        'delta' => [
                            'count' => number_format($objectsSMRAllFineNew->count() ?? 0, decimals: 0, decimal_separator: ','),
                            'area' => number_format(($objectsSMRAllFineNew->sum('area') ?? 0) / 1000000, decimals: 2, decimal_separator: ','),
                        ],
                        'length' => number_format($objectsSMRAllFine->sum('length') / 1000, decimals: 2, decimal_separator: ',') ?? 0
                    ],
                    'violation_of_deadlines' => [
                        'count' => number_format($objectsSMRViolation->count() ?? 0, decimals: 0, decimal_separator: ','),
                        'area' => number_format(($objectsSMRViolation->sum('area') ?? 0) / 1000000, decimals: 2, decimal_separator: ','),
                        'delta' => [
                            'count' => number_format(($objectsSMRViolationNew->count() ?? 0) - ($objectsDONEViolationNew->count() ?? 0), decimals: 0, decimal_separator: ','),
                            'area' => number_format((($objectsSMRViolationNew->sum('area') ?? 0) - ($objectsDONEViolationNew->sum('area') ?? 0)) / 1000000, decimals: 2, decimal_separator: ','),
                        ],
                        'length' => number_format($objectsSMRViolation->sum('length') / 1000, decimals: 2, decimal_separator: ',') ?? 0
                    ],
                    'missed_deadlines' => [
                        'count' => number_format($objectsSMRMissedDeadlines->count() ?? 0, decimals: 0, decimal_separator: ','),
                        'area' => number_format(($objectsSMRMissedDeadlines->sum('area') ?? 0) / 1000000, decimals: 2, decimal_separator: ','),
                        'delta' => [
                            'count' => number_format(($objectsSMRMissedDeadlinesNew->count() ?? 0) - ($objectsDONEMissedDeadlines->count() ?? 0), decimals: 0, decimal_separator: ','),
                            'area' => number_format((($objectsSMRMissedDeadlinesNew->sum('area') ?? 0) - ($objectsDONEMissedDeadlines->sum('area') ?? 0)) / 1000000, decimals: 2, decimal_separator: ','),
                        ],
                        'length' => number_format($objectsSMRMissedDeadlines->sum('length') / 1000, decimals: 2, decimal_separator: ',') ?? 0
                    ]
                ],
                'panel_done' => [
                    'name' => 'Введено',
                    //  'objects' => [
                    'count' => number_format($objectsDONE->count(), decimals: 0, thousands_separator: ','), // Количество объектов, введенных в эксплуатацию
                    'area' => number_format($objectsDONE->sum('area') / 1000000, decimals: 2, decimal_separator: ','), // Общая площадь таких объектов
                    'delta' => [
                        'count' => number_format($objectsDONENew->count(), decimals: 0, decimal_separator: ','), // Дельта количества объектов, у которых работы не начаты
                        'area' => number_format(
                            ($objectsDONENew->sum('area') / 1000000)
                            , decimals: 2, decimal_separator: ',') // Общая площадь дельты объектов, у которых работы не начаты
                        // ]
                    ],
                    'length' => number_format($objectsDONE->sum('length') / 1000, decimals: 2, decimal_separator: ',') ?? 0,
                    'violation_of_deadlines' => [
                        'count' => number_format($objectsDONEViolation->count() ?? 0, decimals: 0, decimal_separator: ','),
                        'area' => number_format(($objectsDONEViolation->sum('area') ?? 0) / 1000000, decimals: 2, decimal_separator: ','),
                        'delta' => [
                            'count' => number_format(($objectsDONEViolationNew->count() ?? 0) - ($objectsDONEViolation->count() ?? 0), decimals: 0, decimal_separator: ','),
                            'area' => number_format((($objectsDONEViolationNew->sum('area') ?? 0) - ($objectsDONEViolation->sum('area') ?? 0)) / 1000000, decimals: 2, decimal_separator: ','),
                        ],
                        'length' => number_format($objectsDONEViolation->sum('length') / 1000, decimals: 2, decimal_separator: ',') ?? 0,
                    ],
                    'missed_deadlines' => [
                        'count' => number_format($objectsDONEMissedDeadlines->count() ?? 0, decimals: 0, decimal_separator: ','),
                        'area' => number_format(($objectsDONEMissedDeadlines->sum('area') ?? 0) / 1000000, decimals: 2, decimal_separator: ','),
                        'delta' => [
                            'count' => number_format(($objectsDONEMissedDeadlinesNew->count() ?? 0) - ($objectsDONEMissedDeadlines->count() ?? 0), decimals: 0, decimal_separator: ','),
                            'area' => number_format((($objectsDONEMissedDeadlinesNew->sum('area') ?? 0) - ($objectsDONEMissedDeadlines->sum('area') ?? 0)) / 1000000, decimals: 2, decimal_separator: ','),
                        ],
                        'length' => number_format($objectsDONEMissedDeadlines->sum('length') / 1000, decimals: 2, decimal_separator: ',') ?? 0,
                    ]
                ]
            ]);
        } catch (\Throwable $th) {
            report ($th);

            Log::error('ошибка внутри public function getFilteredDataHomePage', [
                'error' => $th,
            ]);
            return response()->json($th, 500, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Передаем на фронт объект с фильтрами ОИВ и ЗАКАЗЧИКОВ на первом экране
     * @return JsonResponse
     */
    public function getFiltersOptions()
    {
        try {
    //        Шаг 1: Соберите ИНН, связанные с разрешенными объектами.
            $allowedObjectIDs = request()->input('allowedObjectIDS', []);

            $inns = collect(ObjectModel::with(['contractor', 'customer', 'developer', 'oiv'])
                ->whereIn('id', $allowedObjectIDs)
                ->get())
                ->flatMap(function ($object) {
                    $objectInns = [];
                    if ($object->contractor && $object->contractor->inn) {
                        $objectInns[] = $object->contractor->inn;
                    }
                    if ($object->customer && $object->customer->inn) {
                        $objectInns[] = $object->customer->inn;
                    }
                    if ($object->developer && $object->developer->inn) {
                        $objectInns[] = $object->developer->inn;
                    }
                    if ($object->oiv && $object->oiv->inn) {
                        $objectInns[] = $object->oiv->inn;
                    }
                    return $objectInns;
                })
                ->unique();

    //        Шаг 2: Отфильтруйте меню на основе собранных ИНН.
            $filteredMenuOptions = JsonHelper::getJson_OIV_ZAKAZCHIC_FIRST_SCREEN($inns);

    //        Шаг 3: Верните новые параметры меню.
            return response()->json($filteredMenuOptions);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }


    /**
     * Передаем на фронт объект с фильтрами ОИВ и ЗАКАЗЧИКОВ на первом экране
     * @return JsonResponse
     */
    public function getArrayOfOivAndPodveds()
    {
        try {
            return response()->json(JsonHelper::getPodvedArrayWithOIV());
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }


}
