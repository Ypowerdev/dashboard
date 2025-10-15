<?php

namespace App\Models\Library;

use App\Models\ObjectModel;
use Illuminate\Database\Eloquent\Model;

/**
 * БИБЛИОТЕКА: ФНО. Возможно будет использоваться в детализации по проекту. Данная библиотека иначе структурирована в отличии от ФНО уровни. Таблицы fno_library.
 *
 * @property string $group_name Наименование группы видов функционального назначения объектов.
 * @property int $group_code Код группы видов функционального назначения объектов.
 * @property string|null $subgroup_name Наименование подгруппы видов функционального назначения объектов.
 * @property int|null $subgroup_code Код подгруппы видов функционального назначения объектов.
 * @property string|null $type_name Наименование вида функционального назначения объекта.
 * @property int $type_code Код вида функционального назначения объекта.
 */
class FnoLibrary extends Model
{
    protected $table = 'fno_library';

    /**
     * Первичный ключ таблицы.
     *
     * @var string
     */
    protected $primaryKey = 'type_code';

    /**
     * Указывает, что первичный ключ не является автоинкрементным.
     *
     * @var bool
     */
    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'group_name', 'group_code', 'subgroup_name', 'subgroup_code', 'type_name', 'type_code'
    ];

    /**
     * Получить объекты, связанные с этим видом функционального назначения.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function objects()
    {
        return $this->hasMany(ObjectModel::class, 'fno_type_code');
    }

    static public function getFullLibrary()
    {
        return self::all()->toArray();
    }
    static public function getLibraryByIDS($ids)
    {
        return self::whereIn($ids)->get()->toArray();
    }


}