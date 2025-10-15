<?php

namespace App\Models;

use App\Models\Library\ConstructionStagesLibrary;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ObjectConstructionStage extends Model
{
    // use SoftDeletes;
    use HasFactory;

    public $timestamps = false;

    public static string $entity_type = 'Строительный этап';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'object_construction_stages';

    protected array $append = [
        'stage_name'
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'object_id',
        'construction_stages_library_id',
        'user_id',
        'created_date',
        'smg_plan',
        'smg_fact',
        'smg_fact_start',
        'smg_fact_finish',
        'oiv_plan_start',
        'oiv_plan_finish',
        'oiv_plan',
        'oiv_fact',
        'ai_fact',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'smg_fact_start' => 'date',
        'smg_fact_finish' => 'date',
        'oiv_plan_start' => 'date',
        'oiv_plan_finish' => 'date',

        'smg_plan' => 'integer',
        'smg_fact' => 'integer',
        'oiv_plan' => 'integer',
        'oiv_fact' => 'integer',
        'ai_fact' => 'integer',

        'created_date' => 'date',
    ];

    /**
     * Get the object that the construction stage belongs to.
     */
    public function object(): BelongsTo
    {
        return $this->belongsTo(ObjectModel::class, 'object_id');
    }

    /**
     * Get the construction stages library entry that this stage belongs to.
     */
    public function constructionStagesLibrary(): BelongsTo
    {
        return $this->belongsTo(ConstructionStagesLibrary::class, 'construction_stages_library_id');
    }

    /**
     * Get the user that created/manages this construction stage.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * smg_plan
     * smg_fact
     * smg_fact_start
     * smg_fact_finish
     * oiv_plan_start
     * oiv_plan_finish
     * oiv_plan
     * oiv_fact
     * ai_fact
     **/
    public static function getAttributeByName($name)
    {
        if(str_contains($name, '(план)')){return 'oiv_plan';}
        if(str_contains($name, '(факт)')){return 'oiv_fact';}
        if(str_contains($name, '(плановая дата начала)')){return 'oiv_plan_start';}
        if(str_contains($name, '(плановая дата завершения)')){return 'oiv_plan_finish';}

        if(str_contains($name, '(фактическая дата начала)')){return 'smg_fact_start';}
        if(str_contains($name, '(фактическая дата завершения)')){return 'smg_fact_finish';}
        if(str_contains($name, '(% выполнения, план)')){return 'smg_plan';}
        if(str_contains($name, '(% выполнения, факт)')){return 'smg_fact';}

        Log::error("Это что за покемон? нераспознана запись строительного этапа:". $name);
        dd('Это что за покемон? : '. $name);

        return false;
    }

    /**
     * Метод возвращает название этапа строительства
     * @return mixed
     */
    public function getStageNameAttribute()
    {
        return $this->constructionStagesLibrary->name;
    }

    /**
     * Обработка массива данных по строительным этапам объекта с учетом soft deleted записей
     * Все даты приводятся к прошедшему понедельнику
     *
     * @param array $constructionStageData Данные строительных этапов
     * @param int $userId ID пользователя
     * @param string $dayInJSON Дата из JSON записи
     * @param int $objectId ID объекта
     * @param int|null $initiator_user_id ID инициатора
     * @param string|null $initiator_user_name Имя инициатора
     * @param int|null $json_user_id ID пользователя из JSON
     * @return void
     */
    public static function saveConstructionStageData($constructionStageData, $userId, $dayInJSON, $objectId,
                                                     ?int $initiator_user_id = null,
                                                     ?string $initiator_user_name = null,
                                                     ?int $json_user_id = null,
    ) : void {
        $mondayDate = Carbon::parse($dayInJSON)->startOfWeek();

        foreach ($constructionStageData as $stageId => $stageData) {
            // Добавляем user_id к данным
            $dataToSave = array_merge($stageData, [
                'user_id' => $userId,
            ]);

            // Ищем существующую запись (включая soft deleted)
            $existingRecord = self::withTrashed()
                ->where('object_id', $objectId)
                ->where('construction_stages_library_id', $stageId)
                ->where('created_date', $mondayDate->format('Y-m-d'))
                ->first();

            if ($existingRecord) {
                // Если запись soft deleted - восстанавливаем и обновляем
                if ($existingRecord->trashed()) {
                    $existingRecord->restore();
                }
                $beforeChanges = $existingRecord->toArray();
                $edited = $existingRecord->fill($dataToSave)->save();

                if ($edited) {
                    MongoChangeLogModel::logEdit(
                        record: $existingRecord,
                        beforeChanges: $beforeChanges,
                        entity_type: 'Строительный этап',
                        initiator_user_id: $initiator_user_id,
                        initiator_user_name: $initiator_user_name,
                        json_user_id: $json_user_id
                    );
                }
            } else {
                // Создаем новую запись
                $model = self::create(
                    array_merge([
                        'object_id' => $objectId,
                        'construction_stages_library_id' => $stageId,
                        'created_date' => $mondayDate->format('Y-m-d')
                    ], $dataToSave),
                );
                if ($model) {
                    MongoChangeLogModel::logCreate(
                        record: $model,
                        entity_type: static::$entity_type,
                        initiator_user_id: $initiator_user_id,
                        initiator_user_name: $initiator_user_name,
                        json_user_id: $json_user_id
                    );
                }
            }
        }
    }
}