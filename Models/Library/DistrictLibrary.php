<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;

/**
 * БИБЛИОТЕКА: Название округа Москвы. Таблица districts_library.
 *
 * @property int $id Уникальный идентификатор округа.
 * @property string $name Название округа.
 */
class DistrictLibrary extends Model
{
    protected $table = 'districts_library';

    public $timestamps = false;

    protected $fillable = ['name'];

    /**
     * Получить районы, связанные с этим округом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function regions()
    {
        return $this->hasMany(RegionLibrary::class, 'district_id');
    }
}