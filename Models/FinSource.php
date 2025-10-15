<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * БИБЛИОТЕКА: Источники финансирования. Таблица fin_sources.
 *
 * @property int $id Уникальный идентификатор источника финансирования.
 * @property string $name Имя Типа финансирования: городской бюджет, не городской бюджет или смешанное финансирование, иное
 */
class FinSource extends Model
{
    protected $table = 'fin_sources';

    public $timestamps = false;

    protected $fillable = ['name'];

    /**
     * Получить объекты, связанные с этим источником финансирования.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function objects()
    {
        return $this->hasMany(ObjectModel::class, 'fin_sources_id');
    }


    /**
     * Сохраняет или получает ID источника финансирования на основе данных из записи.
     *
     * @param object $record Запись из удаленной базы данных.
     * @return int|null ID источника финансирования или null, если данные отсутствуют.
     */
    public static function saveAndGetFinSourceID($record): int|null
    {
        // Чистим название от цифр в начале, если есть
        $cleanName = preg_replace('/^\d+\s*/', '', $record->{'Источник финансирования'});
        $cleanName = mb_strtolower($cleanName, 'UTF-8');
        $finSource = self::where('name', $cleanName)->first();
        if (!$finSource) {
            if (empty($cleanName)) {
                return null;
            }
            $finSource = new self();
            $finSource->name = $cleanName;
            $finSource->save();
        }

        return $finSource->id;
    }
}