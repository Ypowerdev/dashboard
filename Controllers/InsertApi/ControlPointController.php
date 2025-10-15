<?php

namespace App\Http\Controllers\InsertApi;

use App\Models\ApiChangeLog;
use App\Models\ObjectControlPoint;
use App\Models\ObjectModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Контроллер для управления контрольными точками объектов через API
 *
 * Этот контроллер обрабатывает запросы API, связанные с контрольными точками объектов,
 * включая создание, обновление и получение данных контрольных точек.
 */
class ControlPointController extends BaseApiController
{
    /**
     * Создает новую контрольную точку для объекта или обновляет существующую
     *
     * Метод принимает данные о контрольной точке через API запрос, проверяет их валидность,
     * и сохраняет в базу данных. Если для указанного объекта и этапа уже существует контрольная
     * точка, то она будет обновлена новыми данными.
     *
     * @param Request $request HTTP запрос с данными контрольной точки
     * @return JsonResponse Ответ API с результатом операции
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Проверка прав доступа и валидация входных данных
            $user = Auth::user();

            $validator = Validator::make($request->all(), [
                'object_id' => 'required|exists:object,id',
                'stage_id' => 'required|exists:control_points_library,id',
                'plan_start_date' => 'nullable|date',
                'fact_start_date' => 'nullable|date',
                'plan_finish_date' => 'nullable|date',
                'fact_finish_date' => 'nullable|date',
                'status' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }


            // Check if user has permission
            if (!$this->checkUserPermission(Auth::user(), $request->object_id)) {
                return $this->jsonResponse(false, 'You do not have permission to update this object', null, 403);
            }

            // Find object
            $object = ObjectModel::find($request->object_id);
            if (!$object) {
                return $this->jsonResponse(false, 'Объект не найден', null, 404);
            }

            // Create new control point record with timestamp
            $controlPoint = new ObjectControlPoint();
            $controlPoint->object_id = $request->object_id;
            $controlPoint->stage_id = $request->stage_id;
            $controlPoint->status = $request->status;
            $controlPoint->plan_start_date = $request->plan_start_date;
            $controlPoint->plan_finish_date = $request->plan_finish_date;
            $controlPoint->fact_start_date = $request->fact_start_date;
            $controlPoint->fact_finish_date = $request->fact_finish_date;
            $controlPoint->user_id = Auth::id();
            $controlPoint->created_at = now();

            // Save the new record
            $controlPoint->save();

            // Log the change
            $this->logApiChange(
                $request,
                'ObjectControlPoint',
                $controlPoint->id ?? 0,
                'created',
                null,
                $controlPoint->toArray()
            );

            return $this->jsonResponse(
                true,
                'Control point status updated',
                ['control_point' => $controlPoint]
            );
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Отмена внесенных изменений
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function revertChange(Request $request): JsonResponse
    {
        try {
            // Validate the request
            $validator = Validator::make($request->all(), [
                'log_id' => 'required|integer|exists:api_change_logs,id',
            ]);

            if ($validator->fails()) {
                return $this->jsonResponse(false, 'Validation error', ['errors' => $validator->errors()], 422);
            }

            // Get the log
            $log = ApiChangeLog::find($request->log_id);
            if (!$log) {
                return $this->jsonResponse(false, 'Log not found', null, 404);
            }

            // Check if user has admin permission (only admins can revert changes)
            if (!Auth::user()->is_admin) {
                return $this->jsonResponse(false, 'You do not have permission to revert changes', null, 403);
            }

            // Check if this is an ObjectControlPoint log
            if ($log->entity_type !== 'ObjectControlPoint') {
                return $this->jsonResponse(false, 'This log is not for a control point change', null, 400);
            }

            // For control points, we add a new record with old values from the previous record
            if ($log->action === 'created' && $log->new_values !== null) {
                // Create a new control point with the previous values
                $previousControlPoint = ObjectControlPoint::where('object_id', $log->new_values['object_id'])
                    ->where('stage_id', $log->new_values['stage_id'])
                    ->where('created_at', '<', $log->created_at)
                    ->orderBy('created_at', 'desc')
                    ->first();

                if (!$previousControlPoint) {
                    return $this->jsonResponse(false, 'No previous control point found to revert to', null, 404);
                }

                $newControlPoint = new ObjectControlPoint();
                $newControlPoint->object_id = $previousControlPoint->object_id;
                $newControlPoint->stage_id = $previousControlPoint->stage_id;
                $newControlPoint->status = $previousControlPoint->status;
                $newControlPoint->plan_start_date = $previousControlPoint->plan_start_date;
                $newControlPoint->plan_finish_date = $previousControlPoint->plan_finish_date;
                $newControlPoint->fact_start_date = $previousControlPoint->fact_start_date;
                $newControlPoint->fact_finish_date = $previousControlPoint->fact_finish_date;
                $newControlPoint->user_id = Auth::id();
                $newControlPoint->created_at = now();

                $newControlPoint->save();

                $this->logApiChange(
                    $request,
                    'ObjectControlPoint',
                    $newControlPoint->id ?? 0,
                    'reverted',
                    $log->new_values,
                    $newControlPoint->toArray()
                );

                return $this->jsonResponse(true, 'Control point reverted to previous state');
            }

            return $this->jsonResponse(false, 'Cannot revert this change', null, 400);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }


    /**
     * Получает детальную информацию о конкретной контрольной точке
     *
     * Метод возвращает подробную информацию о запрашиваемой контрольной точке,
     * включая связанные данные из библиотеки контрольных точек.
     *
     * @param int $id Идентификатор контрольной точки
     * @return JsonResponse Ответ API с информацией о контрольной точке
     */
    public function show(int $id): JsonResponse
    {
        try {
            $controlPoint = ObjectControlPoint::with('controlPointLibrary')
                ->find($id);

            if (!$controlPoint) {
                return response()->json(['error' => 'Контрольная точка не найдена'], 404);
            }

            return response()->json(['data' => $controlPoint]);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    public function getByUIN($uin): JsonResponse
    {
        try {
            // Находим объект по UIN
            $object = ObjectModel::where('uin', $uin)->first();

            if (!$object) {
                return response()->json(['error' => 'Объект не найден'], 404);
            }

            // Получаем все контрольные точки объекта с данными из библиотеки
            $controlPoints = ObjectControlPoint::where('object_id', $object->id)
                ->whereNull('deleted_at')
                ->with('controlPointLibrary')
                ->get();

            // Форматируем ответ
            $data = $controlPoints->map(function ($point) {
                return [
                    'lib_id' => $point->controlPointLibrary->id ?? 'Неизвестная точка',
                    'name' => $point->controlPointLibrary->name ?? 'Неизвестная точка',
                    'view_name' => $point->controlPointLibrary->view_name ?? 'Неизвестная точка',
                    'plan_finish_date' => $point->plan_finish_date,
                    'fact_finish_date' => $point->fact_finish_date,
                    'plan_start_date' => $point->plan_start_date,
                    'fact_start_date' => $point->fact_start_date,
                    'status' => $point->status,
                ];
            })->toArray();

            return $this->jsonResponse(true, 'Контрольные точки по объекту: '.$object->name, $data);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }
}
