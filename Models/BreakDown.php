<?php

namespace App\Models;

use App\Models\Library\ControlPointsLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * СУЩНОСТЬ: Причины срыва сроков и мероприятия по их устранению. Breakdowns
 *
 * @property int $object_id Идентификатор объекта, связанного с записью.
 * @property int|null $control_points_library_id Идентификатор контрольной точки из библиотеки.
 * @property string|null $reason_of_fail Причина срыва сроков.
 * @property string|null $actions_to_fix Мероприятия по устранению срыва сроков.
 * @property string|null $performer Исполнитель, ответственный за устранение.
 * @property string|null $what_was_done Предпринятые меры.
 * @property string|null $date_to_fix Срок устранения причин срыва.
 * @property string|null $status Статус выполнения: "Не отработано", "В работе", "Выполнено".
 *
 * @property-read \App\Models\ObjectModel $object Связь с моделью Object.
 * @property-read \App\Models\ObjectControlPoint|null $controlPointsLibrary Связь с моделью ControlPointsLibrary.
 */
class BreakDown extends Model
{
    /**
     * Название таблицы, связанной с моделью.
     *
     * @var string
     */
    protected $table = 'break_downs';

    public $timestamps = false;

    protected $primaryKey = 'id';

    public $incrementing = true;


    /**
     * Атрибуты, которые могут быть заполнены массово.
     *
     * @var array
     */
    protected $fillable = [
        'object_id',
        'control_points_library_id',
        'reason_of_fail',
        'actions_to_fix',
        'performer',
        'link',
        'what_was_done',
        'date_to_fix_fact',
        'date_to_fix_plan',
        'master_cod_dc',
        'status',
    ];

    /**
     * Атрибуты, которые должны быть приведены к определенным типам.
     *
     * @var array
     */
    protected $casts = [
        'date_to_fix_fact' => 'date',
        'date_to_fix_plan' => 'date',
    ];

    /**
     * Получить объект, связанный с записью.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function object(): BelongsTo
    {
        return $this->belongsTo(ObjectModel::class, 'object_id');
    }

    /**
     * Получить контрольную точку из библиотеки, связанную с записью.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function controlPointsLibrary(): BelongsTo
    {
        return $this->belongsTo(ControlPointsLibrary::class, 'control_points_library_id');
    }
}