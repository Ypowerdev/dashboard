<?php

namespace App\Models;

use App\Models\Library\ControlPointsLibrary;
use App\Models\Library\EtapiRealizaciiLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class ObjectEtapiRealizacii extends Model
{
    // use SoftDeletes;

    protected static $entity_type = 'Этап реализации';

    protected $table = 'object_etapi_realizacii';
    protected $fillable = [
        'object_id',
        'etap_id',
        'plan_finish_date',
        'fact_finish_date',
        'fact_percent',
    ];

    /**
     * Get the construction stages library entry that this stage belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function etapiRealizaciiLibrary(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EtapiRealizaciiLibrary::class, 'etap_id');
    }

    /**
     * Получить объект, к которому относится контрольная точка
     */
    public function object(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ObjectModel::class, 'object_id');
    }

    /**
     * Связь с пользователем, который оставил запись
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Синхронизирует Этапы Реализации на основе переданной контрольной точки
     * и её дочерних элементов (если есть) с учетом soft deleted записей
     *
     * @param ObjectControlPoint $objectControlPoint Полная запись контрольной точки с загруженными отношениями
     * @return void
     */
    public function syncEtapiRealizaciiFromControlPoint(ObjectControlPoint $objectControlPoint): void
    {
        // Проверяем, что отношения загружены
        if (!$objectControlPoint->relationLoaded('controlPointLibrary')) {
            Log::warning("Библиотечная запись не загружена для контрольной точки ID: {$objectControlPoint->id}");
            return;
        }

        $controlPointLibrary = $objectControlPoint->controlPointLibrary;

        if (!$controlPointLibrary) {
            Log::warning("Не найдена библиотечная запись для контрольной точки ID: {$objectControlPoint->id}");
            return;
        }

        $controlPointName = $controlPointLibrary->name;
        $objectId = $objectControlPoint->object_id;

        // Получаем ID этапа реализации для этой контрольной точки
        $etapId = ControlPointsLibrary::getEtapIdForControlPoint($controlPointName);

        if (!$etapId) {
            // Log::info("Контрольная точка '{$controlPointName}' не входит в маппинг для синхронизации");
            return; // Контрольная точка не входит в маппинг
        }

        try {
            // Подготавливаем данные для Этапа Реализации
            // Log::info("Подготавливаем данные для Этапа Реализации: Контрольная точка '{$controlPointName}' ");
            $etapData = [
                'updated_at' => $objectControlPoint->updated_at,
                'user_id' => $objectControlPoint->user_id
            ];

            // 1. Сначала проверяем, есть ли у этой КТ дочерние элементы в библиотеке
            $hasChildrenInLibrary = ControlPointsLibrary::where('parent_id', $objectControlPoint->stage_id)->exists();

            // 2. Если есть дочерние в библиотеке, ищем реальные дочерние КТ для этого объекта
            $hasRealChildren = false;
            $parentDataFromChildren = [];

            if ($hasChildrenInLibrary) {
                $children = ObjectControlPoint::where('object_id', $objectId)
                    ->whereHas('controlPointLibrary', function ($query) use ($objectControlPoint) {
                        $query->where('parent_id', $objectControlPoint->stage_id);
                    })
                    ->get();

                $hasRealChildren = $children->isNotEmpty();

                if ($hasRealChildren) {
                    // Log::info("Найдено {$children->count()} реальных дочерних КТ для '{$controlPointName}'");
                    $parentDataFromChildren = $objectControlPoint->calculateDatesFromChildren();
                }
            }

            if ($hasRealChildren && !empty($parentDataFromChildren)) {
                // Если есть реальные дочерние КТ - используем агрегацию данных
                // Log::info("У этой Контрольной Точки есть реальные дети '{$controlPointName}'");
                $etapData = array_merge($etapData, $parentDataFromChildren);
            } else {
                // Если детей нет - используем данные самой контрольной точки
                // Log::info("У этой Контрольной Точки НЕТ реальных детей '{$controlPointName}'");
                $etapData['plan_finish_date'] = $objectControlPoint->plan_finish_date;
                $etapData['fact_finish_date'] = $objectControlPoint->fact_finish_date;
            }

            // Проверяем существование записи Этапа Реализации (включая soft deleted)
            $existingEtap = self::withTrashed()
                ->where('object_id', $objectId)
                ->where('etap_id', $etapId)
                ->first();

            if ($existingEtap) {
                // Если запись soft deleted - восстанавливаем
                if ($existingEtap->trashed()) {
                    $existingEtap->restore();
                    // Log::info("Восстановлен soft deleted Этап Реализации ID: {$etapId} для объекта ID: {$objectId}");
                }

                // Обновляем существующую запись
                $beforeChanges = $this->toArray();
                $edited = $this->fill($etapData)->save();

                if ($edited) {
                    MongoChangeLogModel::logEdit(
                        record: $this,
                        beforeChanges: $beforeChanges,
                        entity_type: 'Этап реализации',
                        initiator_user_id: null, // initiator_user_id
                        initiator_user_name: null, // initiator_user_name
                        json_user_id: null // json_user_id
                    );
                }
                // Log::info("Обновлен Этап Реализации ID: {$etapId} для объекта ID: {$objectId} на основе КТ '{$controlPointName}'");
            } else {
                // Создаем новую запись
                $etapData['object_id'] = $objectId;
                $etapData['etap_id'] = $etapId;
                $etapData['created_at'] = $objectControlPoint->created_at ?? now();

                if ($etapData) {
                    MongoChangeLogModel::logCreate(
                        record: self::create($etapData),
                        entity_type: 'Этап реализации',
                        initiator_user_id: null, // initiator_user_id
                        initiator_user_name: null, // initiator_user_name
                        json_user_id: null // json_user_id
                    );
                }
                // Log::info("Создан Этап Реализации ID: {$etapId} для объекта ID: {$objectId} на основе КТ '{$controlPointName}'");
            }

        } catch (\Throwable $e) {
            Log::error("Ошибка при обновлении Этапа Реализации ID: {$etapId} для объекта ID: {$objectId}: " . $e->getMessage());
        }
    }

}
