<?php

namespace App\Models\Library;

use App\Models\ObjectModel;
use Illuminate\Database\Eloquent\Model;

/**
 * БИБЛИОТЕКА: Названия районов Москвы. Таблица regions_library.
 * Каждый район принадлежит какому-то округу Москвы.
 *
 * @property int $id Уникальный идентификатор района.
 * @property string $name Название района.
 * @property int $district_id Ссылка на округ, к которому относится район.
 */
class RegionLibrary extends Model
{
    protected $table = 'regions_library';

    public $timestamps = false;

    protected $fillable = ['name', 'district_id'];

    /**
     * Получить округ, к которому относится этот район.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function DistrictLibrary()
    {
        return $this->belongsTo(DistrictLibrary::class, 'district_id');
    }

    /**
     * Получить объекты, связанные с этим районом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function objects()
    {
        return $this->hasMany(ObjectModel::class, 'region_id');
    }


    /**
     * Сохраняет или получает ID района и региона на основе данных из записи.
     *
     * @param object $record Запись из удаленной базы данных.
     * @return int|null ID региона или null, если данные отсутствуют.
     */
    public static function saveAndGetDistrictRegionIDS($record): int|null
    {
        $district = DistrictLibrary::where('name', $record->{'Округ'})->first();
        if (!$district && !empty($record->{'Округ'})) {
            $district = new DistrictLibrary;
            $district->name = $record->{'Округ'};
            $district->save();
        }

        $region = self::where('name', $record->{'Район'})->first();
        if (!$region) {
            if (empty($record->{'Район'})) {
                return null;
            }
            $region = new self();
            $region->name = $record->{'Район'};
            $region->district_id = $district->id;
            $region->save();
        }

        return $region->id;
    }
}