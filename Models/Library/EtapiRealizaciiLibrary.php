<?php

namespace App\Models\Library;

use App\Helpers\JsonHelper;
use App\Models\MongoChangeLogModel;
use App\Models\ObjectEtapiRealizacii;
use App\Models\ObjectModel;
use DateTime;
use Illuminate\Database\Eloquent\Model;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * БИБЛИОТЕКА:  Этапы реализации. Таблицы etapi_realizacii_library + object_etapi
 *
 * @property int $id Уникальный идентификатор этапа реализации.
 * @property string $name Название этапа реализации проекта.
 */
class EtapiRealizaciiLibrary extends Model
{
    protected $table = 'etapi_realizacii_library';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'name',
        'view_name',
        'comments',
        'performer'
    ];

    /**
     * Получить объекты, связанные с этим этапом реализации.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function objects()
    {
        return $this->belongsToMany(ObjectModel::class, 'object_etapi_realizacii', 'etap_id', 'object_id')
            ->withTimestamps()
            ->withPivot('plan_date', 'fact_date', 'user_id');
    }

    /**
     * Сохраняет этап реализации и возвращает ее ID.
     * Если этап реализации уже существует, то возвращает ее ID,
     * иначе создает новую запись и возвращает ID новой записи.
     *
     * @param string $name Название этапа реализации
     * @return int ID этапа реализации
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function saveAndGetEtapiRealizaciiID($name)
    {
        // Удаляем тригерные слова из названия этапа реализации
        $cleanName = str_replace(['ЭТАПРЕАЛИЗАЦИИ '], '', $name);
        $cleanName = trim($cleanName);
        $cleanName = strtoupper($cleanName);
        $cacheKey = 'etapi_realizacii_' . $cleanName;
        $etapiRealizaciiId = cache()->get($cacheKey);
        if(!$etapiRealizaciiId){
            // Ищем существующую запись с таким же названием
            $etapiRealizacii = self::where('name', $cleanName)->first();

            if (!$etapiRealizacii) {
                // Если запись не найдена, создаем новую
                 dd('ПОПЫТКА ДОБАВИТЬ НОВЫЙ "ЭТАП РЕАЛИЗАЦИИ" - СОГЛАСУЙТЕ С АЛЕКСЕЕМ и МАКСИМОМ:  '.$cleanName);
                $etapiRealizacii = self::create([
                    'name' => $cleanName,
                ]);
            }
            cache()->put($cacheKey, $etapiRealizaciiId, 3600);
        }else{
            return $etapiRealizaciiId;
        }

        return $etapiRealizacii->id;
    }

    /**
     * Сохраняет связь между объектом и Этапом Реализации
     *
     * @param int $objectId ID объекта
     * @param int $stageId ID Этапом Реализации
     * @param string|null $planDate Плановая дата завершения
     * @param string|null $factDate Фактическая дата завершения
     * @param int $userId ID пользователя
     * @param \Carbon\Carbon $now Текущие дата и время
     * @param string|null $comments Комментарии
     * @return void
     */
    protected function saveObjectetapiRealizacii($objectId, $stageId, $rowData, $userId, $updateDate,
        $initiatorUserId = null,
        $initiatorUserName = null,
    )
    {
        $now = new DateTime();
        // Подготовка данных для вставки или обновления
        $data = [
            'object_id' => $objectId,
            'etap_id' => $stageId,
            'user_id' => $userId,
//        todo тут внимательно смотрим на входящие данные
            'plan_finish_date' => isset($rowData["План Завершение"]) ? JsonHelper::validateAndAdaptDate($rowData["План Завершение"]) : null,
            'fact_finish_date' => isset($rowData["Факт Завершение"]) ? JsonHelper::validateAndAdaptDate($rowData["Факт Завершение"]) : null,
            'updated_at' => $updateDate, // Обновляем поле updated_at
        ];

        // Проверяем, существует ли уже такая связь
        $existingRecord = ObjectEtapiRealizacii
            ::where('object_id', $objectId)
            ->where('etap_id', $stageId)
            ->first();

        if ($existingRecord) {
            // Удаляем из массива $data поля, которые имеют значение null
            $data = array_filter($data, function ($value) {
                return !is_null($value);
            });

            // Обновляем существующую запись, только если есть данные для обновления
            if (!empty($data)) {
                $afterChanges = $existingRecord->getChanges();
                $beforeChanges = $existingRecord->toArray();
                $changes = array_intersect_key($beforeChanges, $afterChanges);
                $totalChanges = [];
                foreach ($afterChanges as $key => $value) {
                    if (!(self::normalizeValue($changes[$key]) == self::normalizeValue($value))) {
                        $totalChanges[$key] = [
                            'было' => $changes[$key] ?? null,
                            'стало' => $value
                        ];
                    }
                }

                $beforeChanges = $existingRecord->getOriginal();
                $edited = $existingRecord->fill($data)->save();

                if ($edited) {
                    MongoChangeLogModel::logEdit(
                        record: $existingRecord,
                        beforeChanges: $beforeChanges,
                        entity_type: 'Этап реализации',
                        initiator_user_id: $initiatorUserId,
                        initiator_user_name: $initiatorUserName,
                        json_user_id: null,
                    );
                }
//            echo 'Обновлена КТ для '.$objectId.' - Этап: '.$stageId;
            } else {
//            echo 'Нет данных для обновления КТ для '.$objectId.' - Этап: '.$stageId;
            }
        } else {
            // Создаем новую запись
            $data['created_at'] = $now; // Добавляем created_at только для новой записи
            
            $model = ObjectEtapiRealizacii::create($data);

            if ($model) {
                MongoChangeLogModel::logCreate(
                    record: $model,
                    entity_type: 'Этап реализации',
                    initiator_user_id: $initiatorUserId,
                    initiator_user_name: $initiatorUserName,
                );
            }
//        echo 'Добавлена КТ для '.$objectId.' - Этап: '.$stageId;
        }
    }
}
