<?php

namespace App\Models;

use App\Helpers\JsonHelper;
use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\Auth;

class MongoChangeLogModel extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'change_logs';
    
    protected $fillable = [
        'entity_name',
        'entity_type',
        'entity_id',
        'changed_by',
        'changed_by_name',
        'type_of_action',
        'changed_at',
        'changes',
        'before',
        'after',
        'json_user_id', 
        'json_user_name',
    ];
    
    protected $casts = [
        'changed_at' => 'datetime',
        'changes' => 'array',
        'before' => 'array',
        'after' => 'array',
        'entity_id' => 'string',
        'changed_by' => 'integer',
        'changed_by_name' => 'string',
        'type_of_action' => 'string',
        'entity_name' => 'string',
    ];
    
    // Связь с пользователем (если у вас есть User модель)
    public function user()
    {
        // return $this->belongsTo(\App\Models\User::class, 'changed_by');
        // return $this->belongsTo(\App\Models\User::class, 'changed_by', 'id');
        return $this->belongsTo(User::class, 'changed_by', 'id');

        
        // return $this->hasOne(\App\Models\User::class, 'id', 'changed_by')
        //     ->setConnection(config('database.default')); // Явно указываем соединение
    }

    public function username(): ?string
    {
        return User::where('id', 10000)->first()->name ?? 'Система';
    }
    
    // // Получение имени сущности
    // public function getEntityNameAttribute()
    // {
    //     return $this->getEntityName($this->entity_type);
    // }
    
    // Получение ссылки на сущность
    public function getEntityLinkAttribute()
    {
        $modelClass = $this->getEntityModelClass();
        return $modelClass ? $this->getEntityLink() : null;
    }
    
    protected function getEntityModelClass()
    {
        $models = [
            'Строительный объект' => 'ObjectModel',
            'Строительный этап' => 'ObjectConstructionStage',
            'Контрольная точка' => 'ObjectControlPoint',
            'Этап реализации' => 'ObjectEtapiRealizacii',
            'Мониторинг людей' => 'MonitorPeople',
            'Мониторинг техники' => 'MonitorTechnica',
        ];

        $modelClass = 'App\\Models\\'.$models[$this->entity_type] ?? null;
        return class_exists($modelClass) ? $modelClass : null;
    }

    public function getEntityName()
    {
        switch ($this->entity_type) {
            case 'Строительный объект':
                return $this->after['name'] ?? $this->before['name'] ?? null;

            case 'Строительный этап':
                $construction_stages_library_id = $this->after['construction_stages_library_id'] ?? null;
                if ($construction_stages_library_id) {
                    $stageName = \App\Models\Library\ConstructionStagesLibrary::find($construction_stages_library_id);
                    return $stageName ? $stageName->name : null;
                }
                break;

            case 'Контрольная точка':
                $stage_id = $this->after['stage_id'] ?? null;
                if ($stage_id) {
                    $checkpoint = \App\Models\Library\ControlPointsLibrary::find($stage_id);
                    return $checkpoint ? $checkpoint->name : null;
                }
                break;

            case 'Этап реализации':
                $etap_id = $this->after['etap_id'] ?? null;
                if ($etap_id) {
                    $checkpoint = \App\Models\Library\EtapiRealizaciiLibrary::find($etap_id);
                    return $checkpoint ? $checkpoint->name : null;
                }
                break;

            default:
                return '';            
        }
    }

    /** 
     * Логирование обновлений записи сущности в mongodb.
     *
     * @param EloquentModel $record - Запись модели сущности
     * @param array $beforeChanges - Предыдущее состояние сущности
     * @param string $entity_type - Название сущности для вывода в таблицу
     */
    public static function logEdit(
        EloquentModel $record, 
        string $entity_type,
        ?string $entity_name = null,
        ?array $beforeChanges = null, 
        ?string $action = 'Обновление',
        ?int $initiator_user_id = null,
        ?string $initiator_user_name = null,
        ?int $json_user_id = null,
    ): void
    {
        $afterChanges = $record->getChanges();
        if (!$beforeChanges) {
            $beforeChanges = $record->toArray();
        }
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

        if ($totalChanges && count($totalChanges) > 0) {
            self::log(
                action: $action, 
                record: $record, 
                beforeChanges: [], 
                changes: $totalChanges, 
                entity_name: $entity_name,
                entity_type: $entity_type, 
                initiator_user_id: $initiator_user_id, 
                initiator_user_name: $initiator_user_name, 
                json_user_id :$json_user_id
            );
        }
    }

    /** 
     * Логирование создания записи сущности в mongodb.
     *
     * @param EloquentModel $record - Запись модели сущности
     * @param string $entity_type - Название сущности для вывода в таблицу
     */
    public static function logCreate(
        EloquentModel $record, 
        string $entity_type,
        ?string $entity_name = null,
        ?string $action = 'Создание',
        ?int $initiator_user_id = null,
        ?string $initiator_user_name = null,
        ?int $json_user_id = null,
    ): void
    {
        self::log(
            action: $action, 
            record: $record, 
            beforeChanges: [], 
            changes: $record->getAttributes(), 
            entity_name: $entity_name,
            entity_type: $entity_type, 
            initiator_user_id: $initiator_user_id, 
            initiator_user_name: $initiator_user_name, 
            json_user_id :$json_user_id
        );
    }

    /** 
     * Логирование удаления записи сущности в mongodb.
     *
     * @param EloquentModel $record - Запись модели сущности
     * @param string $entity_type - Название сущности для вывода в таблицу
     */
    public static function logDelete(
        EloquentModel $record,
        string $entity_type,
        ?string $entity_name = null,
        ?string $action = 'Удаление',
        ?int $initiator_user_id = null,
        ?string $initiator_user_name = null,
        ?int $json_user_id = null,
    ): void
    {
        self::log(
            action: $action, 
            record: $record, 
            beforeChanges: $record->getAttributes(), 
            changes: [],
            entity_name: $entity_name,
            entity_type: $entity_type, 
            initiator_user_id: $initiator_user_id, 
            initiator_user_name: $initiator_user_name, 
            json_user_id: $json_user_id
        );
    }

    /** 
     * Централизованный метод логирования записи сущности в mongodb.
     *
     * @param string $action - Тип действия Обновление|Создание|Удаление
     * @param EloquentModel $record - Запись модели сущности
     * @param array $beforeChanges - Предыдущее состояние сущности
     * @param array $changes - Изменения сущности
     * @param string $entity_type - Название сущности для вывода в таблицу
     */
    private static function log(
        string $action, 
        EloquentModel $record, 
        array $beforeChanges, 
        array $changes, 
        string $entity_type,
        ?string $entity_name = null, 
        ?int $initiator_user_id = null,
        ?string $initiator_user_name = null,
        ?int $json_user_id = null,
    ): void
    {
        $user_id = $initiator_user_id ?? auth()?->user()?->id;
        $username = $initiator_user_name ?? auth()?->user()?->name;

        $modelName = class_basename($record);

        $serviceColumns = [
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        $afterChanges = $record->getAttributes();
        foreach ($serviceColumns as $key => $value) {
            unset($beforeChanges[$value]);
            unset($afterChanges[$value]);
            unset($changes[$value]);
        }

        $json_user_name = "";
        if ($json_user_id) {
            $json_user_name = User::where('id', $json_user_id)
                ->pluck('name')
                ->first();
        }

        if ($modelName === 'ObjectOksStatusChanges') {
            $entity_id = null;
        } else {
            $entity_id = $modelName === 'ObjectModel' ? ($record['uin'] ?? null) : ($record?->object['uin'] ?? null);
        }

        if (!$entity_name) {
            $entity_name = null;
            switch ($entity_type) {
                case 'Строительный объект':
                    $entity_name = $afterChanges['name'] ?? $beforeChanges['name'] ?? null;

                case 'Строительный этап':
                    $construction_stages_library_id = $afterChanges['construction_stages_library_id'] ?? null;
                    if ($construction_stages_library_id) {
                        $stageName = \App\Models\Library\ConstructionStagesLibrary::find($construction_stages_library_id);
                        $entity_name = $stageName ? $stageName->name : null;
                    }
                    break;

                case 'Контрольная точка':
                    $stage_id = $afterChanges['stage_id'] ?? null;
                    if ($stage_id) {
                        $checkpoint = \App\Models\Library\ControlPointsLibrary::find($stage_id);
                        $entity_name = $checkpoint ? $checkpoint->name : null;
                    }
                    break;

                case 'Этап реализации':
                    $etap_id = $afterChanges['etap_id'] ?? null;
                    if ($etap_id) {
                        $checkpoint = \App\Models\Library\EtapiRealizaciiLibrary::find($etap_id);
                        $entity_name = $checkpoint ? $checkpoint->name : null;
                    }
                    break;

                default:
                    $entity_name = null;            
            }
        }

        self::create([
            'entity_name' => $entity_name,
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'changed_by' => $user_id ?? null,
            'changed_by_name' => $username ?? 'admin',
            'type_of_action' => $action,
            'changed_at' => now(),
            'before' => $beforeChanges,
            'after' => $afterChanges,
            'changes' => ($action !== 'Удаление' ? $changes : []),
            'json_user_id' => $json_user_id,
            'json_user_name' => $json_user_name,
        ]);
    }

    private static function normalizeValue($value)
    {
        // Обработка числовых значений
        if (is_numeric($value)) {
            return floatval($value);
        }
        
        // Обработка булевых значений
        if (is_bool($value)) {
            return (bool)$value;
        }
        
        // Обработка дат
        $date = "";
        if (strtotime($value)) {
            if ($date = JsonHelper::validateAndAdaptDate($value)) {
                return $date;
            }
        }
        
        // Обработка пустых значений
        if ($value === '' || $value === 'null') {
            return null;
        }
        
        // Для строк: тримминг и нормализация
        if (is_string($value)) {
            return trim($value);
        }
        
        return $value;
    }
}