<?php

namespace App\Http\Controllers\InsertApi;

use App\Models\MonitorPeople;
use App\Models\MonitorTechnica;
use App\Models\ObjectModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Контроллер для управления мониторингом ресурсов через API
 *
 * Этот контроллер обрабатывает запросы API, связанные с мониторингом ресурсов на объектах,
 * включая управление данными о людях и технике на строительных площадках.
 */
class ResourceMonitoringController extends BaseApiController
{
    /**
     * Создает новую запись о людях на строительной площадке или обновляет существующую
     *
     * Метод принимает данные о людских ресурсах через API запрос, проверяет их валидность,
     * и сохраняет в базу данных. Если для указанного объекта и даты уже существует запись,
     * то она будет обновлена новыми данными.
     *
     * @param Request $request HTTP запрос с данными о людях
     * @return JsonResponse Ответ API с результатом операции
     */
    public function storePeople(Request $request): JsonResponse
    {
        try {
            // Проверка прав доступа и валидация входных данных
            $user = Auth::user();

            $validator = Validator::make($request->all(), [
                'object_id' => 'required|exists:object,id',
                'date' => 'required|date',
                'plan_people' => 'required|integer|min:0',
                'fact_people' => 'required|integer|min:0',
                'comments' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            // Check if user has permission
            if (!$this->checkUserPermission(Auth::user(), $request->object_id)) {
                return $this->jsonResponse(false, 'You do not have permission to update this object', null, 403);
            }

            // Find object
            // Получение объекта для проверки прав доступа
            $object = ObjectModel::find($request->input('object_id'));
            if (!$object) {
                return response()->json(['error' => 'Объект не найден'], 404);
            }

            // Поиск существующей записи о людях или создание новой
            $monitorPeople = MonitorPeople::firstOrNew([
                'object_id' => $request->input('object_id'),
                'date' => $request->input('date'),
            ]);

            // Store original values for logging
            $oldValues = $monitorPeople->exists ? $monitorPeople->toArray() : null;

            // Update or set values
            $monitorPeople->count_plan = $request->count_plan;
            $monitorPeople->count_fact = $request->count_fact;
            $monitorPeople->master_code_dc = $request->master_code_dc;
            $monitorPeople->user_id = Auth::id();

            // Сохранение данных
            $monitorPeople->save();

            // Log the change
            $this->logApiChange(
                $request,
                'MonitorPeople',
                $monitorPeople->id,
                $monitorPeople->wasRecentlyCreated ? 'созданы' : 'обновлены',
                $oldValues,
                $monitorPeople->toArray()
            );

            return $this->jsonResponse(
                true,
                $monitorPeople->wasRecentlyCreated ? 'Данные о людях созданы' : 'Данные о людях обновлены',
                ['monitor_people' => $monitorPeople]
            );
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Создает новую запись о технике на строительной площадке или обновляет существующую
     *
     * Метод принимает данные о технических ресурсах через API запрос, проверяет их валидность,
     * и сохраняет в базу данных. Если для указанного объекта, типа техники и даты уже существует запись,
     * то она будет обновлена новыми данными.
     *
     * @param Request $request HTTP запрос с данными о технике
     * @return JsonResponse Ответ API с результатом операции
     */
    public function storeTechnica(Request $request): JsonResponse
    {
        try {
            // Проверка прав доступа и валидация входных данных
            $user = Auth::user();

            $validator = Validator::make($request->all(), [
                'object_id' => 'required|integer|exists:objects,id',
                'count_plan' => 'required|integer|min:0',
                'count_fact' => 'required|integer|min:0',
                'master_code_dc' => 'nullable|string|max:100',
                'date' => 'required|date'
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }


            // Find object
            $object = ObjectModel::find($request->object_id);
            if (!$object) {
                return $this->jsonResponse(false, 'Object not found', null, 404);
            }

            // Check if user has permission
            if (!$this->checkUserPermission(Auth::user(), $request->object_id)) {
                return $this->jsonResponse(false, 'You do not have permission to update this object', null, 403);
            }

            // Поиск существующей записи о технике или создание новой
            $monitorTechnica = MonitorTechnica::firstOrNew([
                'object_id' => $request->object_id,
                'date' => $request->date,
            ]);

            // Store original values for logging
            $oldValues = $monitorTechnica->exists ? $monitorTechnica->toArray() : null;

            // Update or set values
            $monitorTechnica->count_plan = $request->count_plan;
            $monitorTechnica->count_fact = $request->count_fact;
            $monitorTechnica->master_code_dc = $request->master_code_dc;
            $monitorTechnica->user_id = Auth::id();

            // Save changes
            $monitorTechnica->save();

            // Log the change
            $this->logApiChange(
                $request,
                'MonitorTechnica',
                $monitorTechnica->id,
                $monitorTechnica->wasRecentlyCreated ? 'созданы' : 'обновлены',
                $oldValues,
                $monitorTechnica->toArray()
            );

            return $this->jsonResponse(
                true,
                $monitorTechnica->wasRecentlyCreated ? 'Данные о технике созданы' : 'Данные о технике обновлены',
                ['monitor_technica' => $monitorTechnica]
            );
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Revert resource monitoring change
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

            // Process based on entity type
            switch ($log->entity_type) {
                case 'MonitorPeople':
                    return $this->revertMonitorPeopleChange($request, $log);
                case 'MonitorTechnica':
                    return $this->revertMonitorTechnicaChange($request, $log);
                default:
                    return $this->jsonResponse(false, 'Unsupported entity type', null, 400);
            }
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Revert monitor people change
     *
     * @param Request $request
     * @param ApiChangeLog $log
     * @return JsonResponse
     */
    private function revertMonitorPeopleChange(Request $request, ApiChangeLog $log): JsonResponse
    {
        try {
            // Find the record
            $monitorPeople = MonitorPeople::find($log->entity_id);
            if (!$monitorPeople) {
                return $this->jsonResponse(false, 'Monitor people record not found', null, 404);
            }

            // If this was a creation action, delete the record
            if ($log->action === 'created' && $log->old_values === null) {
                $monitorPeople->delete();
                $this->logApiChange($request, 'MonitorPeople', $log->entity_id, 'deleted', $monitorPeople->toArray(), null);
                return $this->jsonResponse(true, 'Monitor people record deleted');
            }

            // If this was an update, revert to old values
            if ($log->action === 'updated' && $log->old_values !== null) {
                $oldValues = $monitorPeople->toArray();

                $monitorPeople->count_plan = $log->old_values['count_plan'];
                $monitorPeople->count_fact = $log->old_values['count_fact'];
                $monitorPeople->master_code_dc = $log->old_values['master_code_dc'];

                $monitorPeople->save();

                $this->logApiChange($request, 'MonitorPeople', $log->entity_id, 'reverted', $oldValues, $monitorPeople->toArray());
                return $this->jsonResponse(true, 'Monitor people record reverted to previous state');
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
     * Revert monitor technica change
     *
     * @param Request $request
     * @param ApiChangeLog $log
     * @return JsonResponse
     */
    private function revertMonitorTechnicaChange(Request $request, ApiChangeLog $log): JsonResponse
    {
        try {
            // Find the record
            $monitorTechnica = MonitorTechnica::find($log->entity_id);
            if (!$monitorTechnica) {
                return $this->jsonResponse(false, 'Monitor technica record not found', null, 404);
            }

            // If this was a creation action, delete the record
            if ($log->action === 'created' && $log->old_values === null) {
                $monitorTechnica->delete();
                $this->logApiChange($request, 'MonitorTechnica', $log->entity_id, 'deleted', $monitorTechnica->toArray(), null);
                return $this->jsonResponse(true, 'Monitor technica record deleted');
            }

            // If this was an update, revert to old values
            if ($log->action === 'updated' && $log->old_values !== null) {
                $oldValues = $monitorTechnica->toArray();

                $monitorTechnica->count_plan = $log->old_values['count_plan'];
                $monitorTechnica->count_fact = $log->old_values['count_fact'];
                $monitorTechnica->master_code_dc = $log->old_values['master_code_dc'];

                $monitorTechnica->save();

                $this->logApiChange($request, 'MonitorTechnica', $log->entity_id, 'reverted', $oldValues, $monitorTechnica->toArray());
                return $this->jsonResponse(true, 'Monitor technica record reverted to previous state');
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
     * Получает данные о людях на объекте за указанный период
     *
     * Метод возвращает информацию о людских ресурсах на объекте за указанный
     * диапазон дат или за конкретную дату, если период не указан.
     *
     * @param int $objectId Идентификатор объекта
     * @param Request $request HTTP запрос с параметрами выборки
     * @return JsonResponse Ответ API с данными о людях
     */
    public function getPeopleByObject(int $objectId, Request $request): JsonResponse
    {
        try {
            // Проверка существования объекта
            $object = ObjectModel::find($objectId);
            if (!$object) {
                return response()->json(['error' => 'Объект не найден'], 404);
            }

            // Получение параметров запроса
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date', $startDate);

            // Формирование запроса
            $query = MonitorPeople::where('object_id', $objectId);

            if ($startDate) {
                $query->where('date', '>=', $startDate);
            }

            if ($endDate) {
                $query->where('date', '<=', $endDate);
            }

            // Получение данных
            $peopleData = $query->orderBy('date')->get();

            return response()->json(['data' => $peopleData]);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Получает данные о технике на объекте за указанный период
     *
     * Метод возвращает информацию о технических ресурсах на объекте за указанный
     * диапазон дат или за конкретную дату, если период не указан.
     *
     * @param int $objectId Идентификатор объекта
     * @param Request $request HTTP запрос с параметрами выборки
     * @return JsonResponse Ответ API с данными о технике
     */
    public function getTechnicaByObject(int $objectId, Request $request): JsonResponse
    {
        try {
            // Проверка существования объекта
            $object = ObjectModel::find($objectId);
            if (!$object) {
                return response()->json(['error' => 'Объект не найден'], 404);
            }

            // Получение параметров запроса
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date', $startDate);
            $technicaTypeId = $request->input('technica_type_id');

            // Формирование запроса
            $query = MonitorTechnica::where('object_id', $objectId);

            if ($startDate) {
                $query->where('date', '>=', $startDate);
            }

            if ($endDate) {
                $query->where('date', '<=', $endDate);
            }

            if ($technicaTypeId) {
                $query->where('technica_type_id', $technicaTypeId);
            }

            // Получение данных с информацией о типах техники
            $technicaData = $query->with('technicaType')
                ->orderBy('date')
                ->orderBy('technica_type_id')
                ->get();

            return response()->json(['data' => $technicaData]);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }
}
