<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ObjectControlPoint;
use DateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * API для отображения РЕЕСТРА ЗАДАЧ - ОНИ ЖЕ КОНТРОЛЬНЫЕ ТОЧКИ в нашей парадигме
 */
class ReestrZadach extends Controller
{
    public function index()
    {
        try {
            // Эдакое кэшовое проксирование $user->access_to_raiting
            $raitingAccess = cache()->get('cacheRaitingAccess_' . request()->bearerToken());

            if ($raitingAccess !== true) {
                return response()->json([
                    'access' => false,
                    'message' => 'Вам закрыт доступ на эту страницу',
                ], 401);
            }
            return $this->getDataByFilterControlPoint(request());
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }


    /**
     * Параметры фильтрации для выборки:
     * - строка поиска (УИН, Адрес, название, ответственный, подрядчик (имя) - по объекту)
     * - календарь: дата
     * - сортировка: по ID объекта
     * - по статусу: все, просроченные, выполненные
     *
     * ПОДРЯДЧИКИ: Генподрядчик объекта; object => general_contractors.name
     * ОТВЕТСТВЕННЫЕ: Исполнитель по контрольной точке объекта - он же PERFORMER => control_points_library.performer

     * Параметры объектов, отправляемые на ФРОНТЕНД:
     * 1. Объект = Наименование объекта
     * 2. Ответственный = Исполнитель по контрольной точке объекта
     * 3. Адрес = Адрес объекта
     * 4. Подрядчик = Генподрядчик объекта
     * 5. Текущая задача = Наименование контрольной точки объекта
     * 6. Плановый срок выполнения = Плановая дата завершения по контрольной точке объекта
     * 7. Значение просрочки рассчитывается как:
     *   a. Разница между текущей датой и плановой датой завершения по контрольной точке объекта, если фактическая дата завершения отсутствует И текущая дата > плановая дата завершения контрольной точки
     *   b. Разница между фактической датой завершения и плановой датой завершения по контрольной точке объекта, если фактическая дата завершения > плановой даты завершения
 */

    protected function getDataByFilterControlPoint(Request $request): JsonResponse
    {
        try {
            $query = ObjectControlPoint::with(['object', 'object.contractor', 'controlPointLibrary'])
                ->select('object_control_point.*');

            // Фильтр поиска (УИН, Адрес, название, ответственный, подрядчик)
            if ($search = $request->input('search')) {
                $query->where(function($q) use ($search) {
                    $q->whereHas('object', function($objectQ) use ($search) {
                        $objectQ->whereRaw('uin ILIKE ?', ["%{$search}%"])
                            ->orWhereRaw('name ILIKE ?', ["%{$search}%"])
                            ->orWhereRaw('address ILIKE ?', ["%{$search}%"])
                            ->orWhereHas('contractor', function($contractorQ) use ($search) {
                                $contractorQ->whereRaw('name ILIKE ?', ["%{$search}%"]);
                            });
                    })
                    ->orWhere(function($q) use ($search) {
                        $q->whereHas('controlPointLibrary', function($libraryQ) use ($search) {
                            $libraryQ->where('performer', 'NOT LIKE', 'Генподрядчик')
                                ->whereRaw('performer ILIKE ?', ["%{$search}%"]);
                        });
                    })
                    ->orWhereHas('controlPointLibrary', function($libraryQ) use ($search) {
                        $libraryQ->where('performer', 'Генподрядчик');
                    })->whereHas('object.contractor', function($contractorQ) use ($search) {
                        $contractorQ->whereRaw('name ILIKE ?', ["%{$search}%"]);
                    });
                });
            }

            // Фильтр по дате
            if ($date = $request->input('date')) {
                if ($date === 'this_week') {
                    $startOfWeek = Carbon::now()->startOfWeek();
                    $endOfWeek = Carbon::now()->endOfWeek();
                    $query->whereBetween('plan_finish_date', [$startOfWeek, $endOfWeek]);
                } else {
                    $query->whereDate('plan_finish_date', $date);
                }
            }

            // Фильтр по реновации
            $renovation = $request->boolean('renovation', true);
            $query->whereHas('object', function ($query) use ($renovation) {
                $query->where('renovation', $renovation);
            });

            // Фильтр по статусу (все, просроченные, выполненные)
            if ($status = $request->input('status')) {
                switch($status) {
                    case 'overdue':
                        $query->whereNull('fact_finish_date')
                              ->where('plan_finish_date', '<', now());
                        break;
                    case 'completed':
                        $query->whereNotNull('fact_finish_date');
                        break;
                    case 'all':
                        break;
                }
            }

//            $controlPoints = $query->limit(200)->get();
            $controlPoints = $query->get();

            // Преобразование данных для фронтенда
            $data = $controlPoints->map(function($controlPoint) {
                $object = $controlPoint->object;

                // Расчет значения просрочки
                $readyStatus = !is_null($controlPoint->fact_finish_date);

                // Рассчитываем статус задержки
                $delayStatus = null;
                if ($readyStatus) {
                    // Если задача выполнена, проверяем просрочку
                    if (\Carbon\Carbon::parse($controlPoint->fact_finish_date)->gt(Carbon::parse($controlPoint->plan_finish_date))) {
                        $delayStatus = abs(Carbon::parse($controlPoint->fact_finish_date)
                            ->diffInDays(Carbon::parse($controlPoint->plan_finish_date)));
                    }
                } else {
                    // Если задача не выполнена и просрочена
                    if (Carbon::parse($controlPoint->plan_finish_date)->lt(Carbon::today())) {
                        $delayStatus = abs(Carbon::today()
                            ->diffInDays(Carbon::parse($controlPoint->plan_finish_date)));
                    }
                }

                $performer = $controlPoint->controlPointLibrary?->performer;
                if ($performer === 'Генподрядчик') {
                    $performer = $object->contractor?->name;
                }

                return [
                    'obj_id' => $object->id,
                    'obj_name' => $object->name,
                    'obj_address' => $object->address,
                    'cp_date' => isset($controlPoint->plan_finish_date) ? (new DateTime($controlPoint->plan_finish_date))->format('d.m.Y') : null,
                    'cp_name' => $controlPoint->controlPointLibrary?->name,
                    'cp_view_name' => $controlPoint->controlPointLibrary?->view_name,
                    'cp_performer' => $performer,
                    'contractor' => $object->contractor?->name,
                    'delay_status' => $delayStatus,
                    'ready_status' => $readyStatus,
                ];
            });

            return response()->json([
                'data' => $data
            ]);

        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Failed to fetch tasks',
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
