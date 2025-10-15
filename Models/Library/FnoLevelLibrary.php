<?php

namespace App\Models\Library;

use App\Models\ObjectModel;
use Illuminate\Database\Eloquent\Model;

/**
 * БИБЛИОТЕКА: Фильтры -> расширенные фильтры -> УРОВНИ.
 * Используется для фильтра на главной странице.
 *
 * @property int $type_code Код вида функционального назначения объекта.
 * @property int $lvl1_code Наименование очередности 1 уровня фильтра.
 * @property string $lvl1_name Наименование название 1 уровня фильтра.
 * @property int $lvl2_code Наименование очередности 2 уровня фильтра.
 * @property string $lvl2_name Наименование название 2 уровня фильтра.
 * @property int $lvl3_code Наименование очередности 3 уровня фильтра.
 * @property string $lvl3_name Наименование название 3 уровня фильтра.
 * @property int $lvl4_code Наименование очередности 4 уровня фильтра.
 * @property string $lvl4_name Наименование название 4 уровня фильтра.
 *
 * @property ObjectModel $object Связь с моделью ObjectModel через type_code.
 */
class FnoLevelLibrary extends Model
{
    /**
     * Название таблицы в базе данных.
     *
     * @var string
     */
    protected $table = 'fno_level_library';
    public $timestamps = false;

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

    /**
     * Тип данных первичного ключа.
     *
     * @var string
     */
    protected $keyType = 'bigint';

    /**
     * Поля, которые могут быть заполнены массово.
     *
     * @var array
     */
    protected $fillable = [
        'lvl1_code',
        'lvl1_name',
        'lvl2_code',
        'lvl2_name',
        'lvl3_code',
        'lvl3_name',
        'lvl4_code',
        'lvl4_name',
        'type_code',
    ];

    /**
     * Связь с моделью ObjectModel через type_code.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function objects()
    {
        return $this->hasMany(ObjectModel::class, 'fno_type_code');
    }

}