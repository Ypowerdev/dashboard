<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ObjectControlPoint;
use App\Repositories\ObjectModelRepository;
use App\Services\ObjectControlPointViewService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DBTaskController extends Controller
{
    /**
     * Получение списка задач с группировкой по датам, включая все даты в диапазоне
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function groupedByDate(Request $request)
    {
        try {
            // Проверка доступа
            $raitingAccess = cache()->get('cacheRaitingAccess_' . request()->bearerToken());
            if ($raitingAccess !== true) {
                return response()->json([
                    'access' => false,
                    'message' => 'Вам закрыт доступ на эту страницу',
                ], 401);
            }

            // Получаем параметры фильтрации
            $date = $request->input('date', Carbon::today()->format('Y-m-d'));
            $renovation = $request->boolean('renovation', true);
            $period = $request->input('period', 'day');

            $oiv_id = $request->input('oiv_id', null);
            $podved_inns = $request->input('podved_inns', null);
            $contractor_ids = $request->input('contractor_id', null);

            // Создаем диапазон дат (-60 дней до +60 дней от выбранной даты)
            // Определяем диапазон дат (-60 дней до +60 дней от выбранной даты)
            $dateStart = Carbon::parse($date)->subDays(60);
            $dateEnd = Carbon::parse($date)->addDays(60);

            // Запрос данных
            $objectControlPoints = ObjectControlPoint::query()
                ->select(['fact_finish_date', 'plan_finish_date', 'object_id', 'stage_id'])
                ->with([
                    'object:id,name,address,contractor_id',
                    'object.contractor',
                    'controlPointLibrary:id,name,performer'
                ])
                ->whereBetween('plan_finish_date', [$dateStart, $dateEnd])
                ->whereHas('object', function ($query) use ($renovation, $oiv_id, $podved_inns, $contractor_ids) {
                    $query->where('renovation', $renovation);

                    if (!empty($oiv_id)) {
                        $query->where('oiv_id', $oiv_id);
                    }
                    // Применяем фильтр по ИНН через репозиторий
                    if (!empty($podved_inns)) {
                        $objectModelRepository = app(ObjectModelRepository::class);
                        $objectModelRepository->applyPodvedInnsFilter($query, $podved_inns);
                    }
                    if (!empty($contractor_ids)) {
                        $query->whereIn('contractor_id', $contractor_ids);
                    }
                })
                ->get();

            // Создаем массив всех периодов в диапазоне
            $allPeriods = [];
            $currentDate = $dateStart->copy();

            switch ($period) {
                case 'day':
                    // Группировка по дням
                    while ($currentDate <= $dateEnd) {
                        $periodKey = $currentDate->format('Y-m-d');
                        $allPeriods[$periodKey] = [];
                        $currentDate->addDay();
                    }
                    break;

                case 'week':
                    // Группировка по неделям (неделя начинается с понедельника)
                    // Находим начало первой недели (понедельник)
                    $startOfWeek = $dateStart->copy()->startOfWeek(Carbon::MONDAY);
                    $endOfWeek = $startOfWeek->copy()->endOfWeek(Carbon::SUNDAY);

                    while ($startOfWeek <= $dateEnd) {
                        // Формируем ключ в формате "01.09-08.09"
                        $periodKey = $startOfWeek->format('d.m') . '-' . $endOfWeek->format('d.m');
                        $allPeriods[$periodKey] = [];
                        $startOfWeek->addWeek();
                        $endOfWeek = $startOfWeek->copy()->endOfWeek(Carbon::SUNDAY);
                    }
                    break;

                case 'month':
                    // Группировка по месяцам
                    $startOfMonth = $dateStart->copy()->startOfMonth();

                    while ($startOfMonth <= $dateEnd) {
                        $periodKey = $startOfMonth->format('Y-m'); // Год-месяц
                        $allPeriods[$periodKey] = [];
                        $startOfMonth->addMonth();
                    }
                    break;
            }

            // Массив для хранения объектов
            $objects = [];

            // Заполняем данные по задачам и объектам
            foreach ($objectControlPoints as $objectControlPoint) {
                $planDate = Carbon::parse($objectControlPoint->plan_finish_date);

                // Определяем ключ периода в зависимости от типа группировки
                switch ($period) {
                    case 'day':
                        $periodKey = $planDate->format('Y-m-d');
                        break;
                    case 'week':
                        // Для недели находим начало недели задачи и формируем ключ
                        $taskWeekStart = $planDate->copy()->startOfWeek(Carbon::MONDAY);
                        $taskWeekEnd = $taskWeekStart->copy()->endOfWeek(Carbon::SUNDAY);
                        $periodKey = $taskWeekStart->format('d.m') . '-' . $taskWeekEnd->format('d.m');
                        break;
                    case 'month':
                        $periodKey = $planDate->format('Y-m'); // Год-месяц
                        break;
                    default:
                        $periodKey = $planDate->format('Y-m-d');
                }

                // Проверяем, что период существует в нашем массиве
                if (!isset($allPeriods[$periodKey])) {
                    continue;
                }

                // Формируем данные задачи
                $taskData = $this->formatTaskData($objectControlPoint);
                $objectId = $objectControlPoint->object->id;

                // Добавляем задачу в соответствующий период
                $allPeriods[$periodKey][] = [
                    'object_id' => $objectId,
                    'cp_name' => $taskData['cp_name'],
                    'cp_date' => $taskData['cp_date'],
                    'cp_ft_date' => $taskData['cp_ft_date'],
                    'cp_performer' => $taskData['cp_performer'],
                    'ready_status' => $taskData['ready_status'],
                    'delay_status' => $taskData['delay_status'],
                ];

                // Добавляем объект в массив объектов, если его еще нет
                if (!isset($objects[$objectId])) {
                    $objects[$objectId] = [
                        'object_id' => $objectId,
                        'object_name' => $objectControlPoint->object->name,
                        'object_address' => $objectControlPoint->object->address,
                    ];
                }
            }

            return response()->json([
                'tasks' => $allPeriods,
                'objects' => array_values($objects), // Преобразуем ассоциативный массив в индексированный
                'period_type' => $period,
            ]);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Форматирование данных задачи
     *
     * @param ObjectControlPoint $objectControlPoint
     * @return array
     */
    private function formatTaskData(ObjectControlPoint $objectControlPoint): array
    {
        try {
            $service = new ObjectControlPointViewService($objectControlPoint);
            $statuses = $service->getStatuses();

            return [
                'cp_date' => $objectControlPoint->plan_finish_date,
                'cp_ft_date' => $objectControlPoint->fact_finish_date,
                'cp_name' => $objectControlPoint->controlPointLibrary->name ?? null,
                'cp_performer' => $service->getPerformer(),
                'ready_status' => $statuses['ready_status'],
                'delay_status' => $statuses['delay_status'],
            ];
        } catch (\Throwable $th) {
            report($th);

            return [];
        }
    }
}

