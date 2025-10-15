<?php

namespace App\Http\Controllers\InsertApi;

use App\Models\ApiChangeLog;
use App\Models\ObjectConstructionStage;
use App\Models\ObjectModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Контроллер для управления этапами строительства объектов через API
 *
 * Этот контроллер обрабатывает запросы API, связанные с этапами строительства объектов,
 * включая создание, обновление и получение данных о этапах строительства.
 */
class ConstructionStageController extends BaseApiController
{
    /**
     * Создает новый этап строительства для объекта или обновляет существующий
     *
     * Метод принимает данные о этапе строительства через API запрос, проверяет их валидность,
     * и сохраняет в базу данных. Если для указанного объекта и этапа из библиотеки уже существует
     * запись, то она будет обновлена новыми данными.
     *
     * @param Request $request HTTP запрос с данными этапа строительства
     * @return JsonResponse Ответ API с результатом операции
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Проверка прав доступа и валидация входных данных
            $user = Auth::user();

            $validator = Validator::make($request->all(), [
                'object_id' => 'required|exists:object,id',
                'construction_stages_library_id' => 'required|exists:construction_stages_library,id',
                'smg_fact_start' => 'nullable|date',
                'smg_fact_finish' => 'nullable|date',
                'oiv_plan_start' => 'nullable|date',
                'oiv_plan_finish' => 'nullable|date',
                'smg_plan' => 'nullable|integer',
                'smg_fact' => 'nullable|integer',
                'oiv_plan' => 'nullable|integer',
                'oiv_fact' => 'nullable|integer',
                'created_date' => 'nullable|date'
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            // Получение объекта для проверки прав доступа
            $object = ObjectModel::find($request->input('object_id'));
            if (!$object) {
                return response()->json(['error' => 'Объект не найден'], 404);
            }

            // Поиск существующего этапа строительства или создание нового
            $constructionStage = ObjectConstructionStage::firstOrNew([
                'object_id' => $request->input('object_id'),
                'construction_stages_library_id' => $request->input('construction_stages_library_id')
            ]);

            $constructionStage->smg_fact_start = $request->input('smg_fact_start');
            $constructionStage->smg_fact_finish = $request->input('smg_fact_finish');
            $constructionStage->oiv_plan_start = $request->input('oiv_plan_start');
            $constructionStage->oiv_plan_finish = $request->input('oiv_plan_finish');
            $constructionStage->smg_plan = $request->input('smg_plan');
            $constructionStage->smg_fact = $request->input('smg_fact');
            $constructionStage->oiv_plan = $request->input('oiv_plan');
            $constructionStage->oiv_fact = $request->input('oiv_fact');
            $constructionStage->created_date = $request->input('created_date');
            $constructionStage->user_id = $user->id;

            // Логирование изменений и сохранение
            $isNew = !$constructionStage->exists;
            $oldData = $isNew ? [] : $constructionStage->getOriginal();

            $constructionStage->save();

            // Создание записи в логе изменений API
            if (!$isNew) {
                ApiChangeLog::create([
                    'user_id' => $user->id,
                    'table_name' => $constructionStage->getTable(),
                    'record_id' => $constructionStage->id,
                    'old_data' => json_encode($oldData),
                    'new_data' => json_encode($constructionStage->toArray()),
                    'action' => 'update'
                ]);
            }

            return response()->json([
                'message' => $isNew ? 'Этап строительства создан' : 'Этап строительства обновлен',
                'data' => $constructionStage
            ], $isNew ? 201 : 200);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Получает список этапов строительства для указанного объекта
     *
     * Метод возвращает все этапы строительства, связанные с определенным объектом,
     * включая информацию о этапах из библиотеки этапов строительства.
     *
     * @param int $objectId Идентификатор объекта
     * @return JsonResponse Ответ API со списком этапов строительства
     */
    public function getByObject(int $objectId): JsonResponse
    {
        try {
            // Проверка существования объекта
            $object = ObjectModel::find($objectId);
            if (!$object) {
                return response()->json(['error' => 'Объект не найден'], 404);
            }

            // Получение всех этапов строительства объекта с информацией о этапах из библиотеки
            $constructionStages = ObjectConstructionStage::where('object_id', $objectId)
                ->with('constructionStageLibrary')
                ->get();

            return response()->json(['data' => $constructionStages]);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Revert construction stage change
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

            // Check if this is an ObjectConstructionStage log
            if ($log->entity_type !== 'ObjectConstructionStage') {
                return $this->jsonResponse(false, 'This log is not for a construction stage change', null, 400);
            }

            // For construction stages, we add a new record with old values from the previous record
            if ($log->action === 'created' && $log->new_values !== null) {
                // Create a new construction stage with the previous values
                $previousStage = ObjectConstructionStage::where('object_id', $log->new_values['object_id'])
                    ->where('construction_stages_library_id', $log->new_values['construction_stages_library_id'])
                    ->where('created_at', '<', $log->created_at)
                    ->orderBy('created_at', 'desc')
                    ->first();

                if (!$previousStage) {
                    return $this->jsonResponse(false, 'No previous construction stage found to revert to', null, 404);
                }

                $newStage = new ObjectConstructionStage();
                $newStage->object_id = $previousStage->object_id;
                $newStage->construction_stages_library_id = $previousStage->construction_stages_library_id;
                $newStage->smg_fact_start = $previousStage->smg_fact_start;
                $newStage->smg_fact_finish = $previousStage->smg_fact_finish;
                $newStage->oiv_plan_start = $previousStage->oiv_plan_start;
                $newStage->oiv_plan_finish = $previousStage->oiv_plan_finish;
                $newStage->smg_plan = $previousStage->smg_plan;
                $newStage->smg_fact = $previousStage->smg_fact;
                $newStage->oiv_plan = $previousStage->oiv_plan;
                $newStage->oiv_fact = $previousStage->oiv_fact;
                $newStage->created_date = $previousStage->created_date;
                $newStage->user_id = Auth::id();
                $newStage->created_at = now();

                $newStage->save();

                $this->logApiChange(
                    $request,
                    'ObjectConstructionStage',
                    $newStage->id,
                    'reverted',
                    $log->new_values,
                    $newStage->toArray()
                );

                return $this->jsonResponse(true, 'Construction stage reverted to previous state');
            }

            return $this->jsonResponse(false, 'Cannot revert this change', null, 400);
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

            // Получаем все этапы строительства объекта с данными из библиотеки
            $stages = ObjectConstructionStage::where('object_id', $object->id)
                ->whereNull('deleted_at')
                ->with('constructionStagesLibrary')
                ->get();

            // Форматируем ответ
            $data = $stages->map(function ($stage) {
                return [
                    'lib_id' => $stage->constructionStagesLibrary->id ?? 'Неизвестный этап',
                    'name' => $stage->constructionStagesLibrary->name ?? 'Неизвестный этап',
                    'view_name' => $stage->constructionStagesLibrary->view_name ?? 'Неизвестный этап',
                    'oiv_plan' => $stage->oiv_plan,
                    'oiv_fact' => $stage->oiv_fact,
                    'smg_fact' => $stage->smg_fact,
                ];
            })->toArray();

            return $this->jsonResponse(true, 'Строительные этапы по объекту: '.$object->name, $data);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }
}
