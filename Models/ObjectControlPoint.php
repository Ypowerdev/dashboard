<?php

namespace App\Models;

use App\Helpers\JsonHelper;
use App\Models\Library\ControlPointsLibrary;
use App\Models\Library\EtapiRealizaciiLibrary;
use DateTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class ObjectControlPoint extends Model
{
    // use SoftDeletes;
    use HasFactory;

    public static string $entity_type = 'Контрольная точка';

    /**
     * Название таблицы в базе данных
     */
    protected $table = 'object_control_point';

    /**
     * Атрибуты, которые можно массово назначать
     */
    protected $fillable = [
        'object_id',
        'stage_id',
        'status',
        'plan_start_date',
        'fact_start_date',
        'plan_finish_date',
        'fact_finish_date',
        'user_id',
    ];

    /**
     * Атрибуты, которые следует преобразовывать в даты
     */
    protected $dates = [
        'plan_start_date',
        'fact_start_date',
        'plan_finish_date',
        'fact_finish_date',
        'created_at',
        'updated_at',
    ];

    /**
     * Получить объект, к которому относится контрольная точка
     */
    public function object(): BelongsTo
    {
        return $this->belongsTo(ObjectModel::class, 'object_id');
    }

    /**
     * Получить этап из библиотеки контрольных точек
     */
    public function controlPointLibrary(): BelongsTo
    {
        return $this->belongsTo(ControlPointsLibrary::class, 'stage_id');
    }

    /**
     * Получить пользователя, создавшего/изменившего запись
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Отношение: дочерние контрольные точки
     */
    public function children()
    {
        if (!$this->relationLoaded('controlPointLibrary')) {
            $this->load('controlPointLibrary');
        }

        if (!$this->controlPointLibrary) {
            return $this->newCollection();
        }

        // Используем whereHas для фильтрации через библиотеку
        return $this->hasMany(ObjectControlPoint::class, 'object_id', 'object_id')
            ->where('object_id', $this->object_id)
            ->whereHas('controlPointLibrary', function ($query) {
                $query->where('parent_id', $this->controlPointLibrary->id);
            });
    }

    /**
     * Проверить, является ли эта КТ дочерней
     */
    public function isChild()
    {
        return $this->controlPointLibrary && $this->controlPointLibrary->parent_id;
    }


    /**
     * Вычисляет даты для текущей контрольной точки на основе дочерних контрольных точек
     * Используется для родительских КТ, которые агрегируют данные от дочерних
     *
     * @param int|null $initiatorUserId ID инициатора
     * @param string|null $initiatorUserName Имя инициатора
     * @param int|null $jsonUserId ID пользователя из JSON
     * @return array
     */
    public function calculateDatesFromChildren(
        ?int $initiatorUserId = null,
        ?string $initiatorUserName = null,
        ?int $jsonUserId = null
    ): array {
        // Log::info('calculateDatesFromChildren: начал выполнение для КТ ID ' . $this->id . ', stage_id: ' . $this->stage_id);

        $result = [
            'plan_finish_date' => null,
            'fact_finish_date' => null
        ];

        // Получаем дочерние контрольные точки для текущего объекта
        // Log::info('calculateDatesFromChildren: ищу дочерние КТ для object_id: ' . $this->object_id . ', parent stage_id: ' . $this->stage_id);

        $children = ObjectControlPoint::where('object_id', $this->object_id)
            ->whereHas('controlPointLibrary', function ($query) {
                $query->where('parent_id', $this->stage_id);
            })
            ->get();

        // Log::info('calculateDatesFromChildren: найдено ' . $children->count() . ' дочерних КТ');

        // Если нет дочерних КТ - возвращаем пустой результат
        if ($children->isEmpty()) {
            // Log::info('calculateDatesFromChildren: нет дочерних КТ, возвращаю пустой результат');
            return $result;
        }

        // Собираем все даты окончания из дочерних КТ
        $planFinishDates = [];
        $factFinishDates = [];

        foreach ($children as $child) {
            // Log::info('calculateDatesFromChildren: обработка дочерней КТ ID ' . $child->id .
//                ', plan_finish: ' . ($child->plan_finish_date ?? 'null') .
//                ', fact_finish: ' . ($child->fact_finish_date ?? 'null'));
//
            if ($child->plan_finish_date) {
                $planFinishDates[] = $child->plan_finish_date;
                // Log::info('calculateDatesFromChildren: добавлена план дата: ' . $child->plan_finish_date);
            }
            if ($child->fact_finish_date) {
                $factFinishDates[] = $child->fact_finish_date;
                // Log::info('calculateDatesFromChildren: добавлена факт дата: ' . $child->fact_finish_date);
            }
        }

        // Log::info('calculateDatesFromChildren: собрано planFinishDates: ' . count($planFinishDates) .
//            ', factFinishDates: ' . count($factFinishDates));

        // Логика для дат ОКОНЧАНИЯ (план):
        // МАКСИМУМ из всех значений ТОЛЬКО при условии, что ВСЕ дочерние КТ имеют заполненное поле
        $allHavePlanFinish = $children->every(function ($child) {
            return !empty($child->plan_finish_date);
        });

        // Log::info('calculateDatesFromChildren: allHavePlanFinish = ' . ($allHavePlanFinish ? 'true' : 'false'));

        if ($allHavePlanFinish && !empty($planFinishDates)) {
            $result['plan_finish_date'] = max($planFinishDates);
            // Log::info('calculateDatesFromChildren: установлен plan_finish_date = ' . $result['plan_finish_date']);
        } else {
            // Log::info('calculateDatesFromChildren: план дата не установлена (allHavePlanFinish: ' .
//                ($allHavePlanFinish ? 'true' : 'false') . ', empty planFinishDates: ' .
//                (empty($planFinishDates) ? 'true' : 'false') . ')');
        }

        // Логика для дат ОКОНЧАНИЯ (факт):
        // МАКСИМУМ из всех значений ТОЛЬКО при условии, что ВСЕ дочерние КТ имеют заполненное поле
        $allHaveFactFinish = $children->every(function ($child) {
            return !empty($child->fact_finish_date);
        });

        // Log::info('calculateDatesFromChildren: allHaveFactFinish = ' . ($allHaveFactFinish ? 'true' : 'false'));

        if ($allHaveFactFinish && !empty($factFinishDates)) {
            $result['fact_finish_date'] = max($factFinishDates);
            // Log::info('calculateDatesFromChildren: установлен fact_finish_date = ' . $result['fact_finish_date']);
        } else {
            // Log::info('calculateDatesFromChildren: факт дата не установлена (allHaveFactFinish: ' .
//                ($allHaveFactFinish ? 'true' : 'false') . ', empty factFinishDates: ' .
//                (empty($factFinishDates) ? 'true' : 'false') . ')');
        }

        // Автоматически сохраняем вычисленные значения в текущей записи
        if ($result['plan_finish_date'] || $result['fact_finish_date']) {
            $updates = [];

            if ($result['plan_finish_date']) {
                $updates['plan_finish_date'] = $result['plan_finish_date'];
            }

            if ($result['fact_finish_date']) {
                $updates['fact_finish_date'] = $result['fact_finish_date'];
            }

            if (!empty($updates)) {
                // Log::info('calculateDatesFromChildren: сохраняю вычисленные значения через MongoChangeLogModel::logEdit');
                MongoChangeLogModel::logEdit(
                    record: $this,
                    beforeChanges: $this->toArray(),
                    entity_type: static::$entity_type,
                    initiator_user_id: $initiatorUserId,
                    initiator_user_name: $initiatorUserName,
                    json_user_id: $jsonUserId,
                );
                // Log::info('calculateDatesFromChildren: значения успешно сохранены');
            }
        }

        // Log::info('calculateDatesFromChildren: завершил выполнение, результат: ' . json_encode($result));
        return $result;
    }

    /**
     * Найти родительскую контрольную точку и обновить её данные
     */
    public function findParentOrCreateIfNotExist()
    {
        // Log::info('findParentOrCreateIfNotExist: начал выполнение для КТ ID ' . $this->id . ', stage_id: ' . $this->stage_id);

        if (!$this->controlPointLibrary) {
            // Log::info('findParentOrCreateIfNotExist: controlPointLibrary не загружен, возвращаю null');
            return null;
        }

        // Log::info('findParentOrCreateIfNotExist: ищу родительскую библиотеку для parent_id: ' . $this->controlPointLibrary->parent_id);
        $parentLibrary = ControlPointsLibrary::find($this->controlPointLibrary->parent_id);

        if (!$parentLibrary) {
            // Log::info('findParentOrCreateIfNotExist: родительская библиотека не найдена, возвращаю null');
            return null;
        }

        // Log::info('findParentOrCreateIfNotExist: ищу существующую родительскую КТ для object_id: ' .
//            $this->object_id . ', stage_id: ' . $parentLibrary->id);

        // Ищем существующую запись в БД
        $parentControlPoint = ObjectControlPoint::where('object_id', $this->object_id)
            ->where('stage_id', $parentLibrary->id)
            ->first();

        // Если нашли - обновляем данные и возвращаем
        if ($parentControlPoint) {
            // Log::info('findParentOrCreateIfNotExist: найдена существующая родительская КТ ID ' . $parentControlPoint->id . ', обновляю данные');
            $parentControlPoint->updateFromChildren();
            // Log::info('findParentOrCreateIfNotExist: возвращаю существующую родительскую КТ');
            return $parentControlPoint;
        }

        // Log::info('findParentOrCreateIfNotExist: родительская КТ не найдена, создаю новую');

        // Если нет в БД - создаем новую запись
        $parentControlPoint = new ObjectControlPoint();
        $parentControlPoint->object_id = $this->object_id;
        $parentControlPoint->stage_id = $parentLibrary->id;
        $parentControlPoint->status = 'ОБЩКОНТРТОЧКА';

        // Log::info('findParentOrCreateIfNotExist: создана новая родительская КТ, вычисляю даты из дочерних');

        // Вычисляем даты из дочерних КТ
        $dates = $parentControlPoint->calculateDatesFromChildren();

        if ($dates['plan_finish_date']) {
            $parentControlPoint->plan_finish_date = $dates['plan_finish_date'];
            // Log::info('findParentOrCreateIfNotExist: установлен plan_finish_date = ' . $dates['plan_finish_date']);
        }

        if ($dates['fact_finish_date']) {
            $parentControlPoint->fact_finish_date = $dates['fact_finish_date'];
            // Log::info('findParentOrCreateIfNotExist: установлен fact_finish_date = ' . $dates['fact_finish_date']);
        }

        // Log::info('findParentOrCreateIfNotExist: сохраняю новую родительскую КТ в БД');

        // Сохраняем в БД
        $parentControlPoint->save();

        // Загружаем отношение библиотеки
        $parentControlPoint->load('controlPointLibrary');

        // Log::info('findParentOrCreateIfNotExist: возвращаю новую родительскую КТ ID ' . $parentControlPoint->id);

        return $parentControlPoint;
    }

    /**
     * Установить статус на основе даты завершения
     */
    public function setStatusByFinisDate($finishDate)
    {
        return !empty($finishDate) ? 'completed' : 'active';
    }

    /**
     * Сохраняет связь между объектом и контрольной точкой с учетом soft deleted записей
     * Автоматически обновляет соответствующие Этапы Реализации
     *
     * @param int $objectId ID объекта
     * @param int $stageId ID контрольной точки
     * @param array $rowData Данные контрольной точки
     * @param int $userId ID пользователя
     * @param mixed $updateDate Дата обновления
     * @param int|null $initiatorUserId ID инициатора
     * @param string|null $initiatorUserName Имя инициатора
     * @return ObjectControlPoint|null
     */
    protected function saveObjectControlPointFromEXONParcer(
        $objectId,
        $stageId,
        $rowData,
        $userId,
        $updateDate,
        ?int $initiatorUserId = null,
        ?string $initiatorUserName = null
    )
    {
        if (!$objectId) {
            Log::error("Невозможно сохранить контрольную точку: object_id не задан.");
            return null;
        }

        $now = new DateTime();
        // Подготовка данных для вставки или обновления
        $rowData = array_filter($rowData, function ($value) {
            return !is_null($value);
        });

        // Если данных нет, то и незачем всё это...
        if (count($rowData) === 0) {
            return null;
        }

        $objectControlPointDateArr = [
            'object_id' => $objectId,
            'stage_id' => $stageId,
            'user_id' => $userId,
            'plan_start_date' => isset($rowData["План Начало"]) ? JsonHelper::validateAndAdaptDate($rowData["План Начало"]) : null,
            'fact_start_date' => isset($rowData["Факт Начало"]) ? JsonHelper::validateAndAdaptDate($rowData["Факт Начало"]) : null,
            'plan_finish_date' => isset($rowData["План Завершение"]) ? JsonHelper::validateAndAdaptDate($rowData["План Завершение"]) : null,
            'fact_finish_date' => isset($rowData["Факт Завершение"]) ? JsonHelper::validateAndAdaptDate($rowData["Факт Завершение"]) : null,
            'status' => $this->setStatusByFinisDate($rowData["Факт Завершение"] ?? null),
            'updated_at' => $updateDate,
        ];

        // Проверяем существование записи (включая soft deleted)
        $existingRecord = self::withTrashed()
            ->where('object_id', $objectId)
            ->where('stage_id', $stageId)
            ->first();

        if ($existingRecord) {
            // Если запись soft deleted - восстанавливаем
            if ($existingRecord->trashed()) {
                $existingRecord->restore();
            }

            // Удаляем из массива поля, которые имеют значение null
            $objectControlPointDateArr = array_filter($objectControlPointDateArr, function ($value) {
                return !is_null($value);
            });

            // Обновляем существующую запись, только если есть данные для обновления
            if (!empty($objectControlPointDateArr)) {
                $beforeChanges = $existingRecord->getOriginal();
                $edited = $existingRecord->fill($objectControlPointDateArr)->save();

                if ($edited) {
                    MongoChangeLogModel::logEdit(
                        record: $existingRecord,
                        beforeChanges: $beforeChanges,
                        entity_type: 'Контрольная точка',
                        initiator_user_id: null,
                        initiator_user_name: null,
                        json_user_id: null,
                    );
                }
                echo 'Обновлена КТ для '.$objectId.' - Этап: '.$stageId;
            } else {
                echo 'Нет данных для обновления КТ для '.$objectId.' - Этап: '.$stageId;
            }
        } else {
            // Создаем новую запись
            $objectControlPointDateArr['created_at'] = $now;
            static::create($objectControlPointDateArr);
            echo 'Добавлена КТ для '.$objectId.' - Этап: '.$stageId;
        }
    }

    /**
     * Обновляет даты текущей контрольной точки на основе дочерних КТ
     * Используется для родительских КТ, которые агрегируют данные
     *
     * @param int|null $initiatorUserId ID инициатора
     * @param string|null $initiatorUserName Имя инициатора
     * @param int|null $jsonUserId ID пользователя из JSON
     * @return bool
     */
    public function updateFromChildren(
        ?int $initiatorUserId = null,
        ?string $initiatorUserName = null,
        ?int $jsonUserId = null
    ): bool {
    // Log::info('updateFromChildren: начал выполнение для КТ ID ' . $this->id . ', stage_id: ' . $this->stage_id);

        if (!$this->controlPointLibrary) {
            // Log::info('updateFromChildren: controlPointLibrary не загружен, пропускаю');
            return false;
        }

        if (!$this->controlPointLibrary->parent_id) {
            // Log::info('updateFromChildren: это не родительская КТ (parent_id = null), пропускаю');
            return false;
        }

        // Log::info('updateFromChildren: это родительская КТ, вычисляю даты из дочерних');

        // Вызываем calculateDatesFromChildren, который теперь автоматически сохраняет значения
        $dates = $this->calculateDatesFromChildren(
            $initiatorUserId,
            $initiatorUserName,
            $jsonUserId
        );

        // Проверяем, были ли сохранены изменения
        $hasChanges = !empty($dates['plan_finish_date']) || !empty($dates['fact_finish_date']);

        // Log::info('updateFromChildren: завершил выполнение, изменения: ' . ($hasChanges ? 'да' : 'нет'));
        return $hasChanges;
    }

}
