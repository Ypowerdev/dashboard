<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * СУЩНОСТЬ: Застройщики. Модель для таблицы developer. Хранит список застройщиков.
 *
 * @property int $id Уникальный идентификатор застройщика.
 * @property string $name Название застройщика.
 * @property int $inn ИНН застройщика, должен содержать ровно 10-12 цифр.
 */
class Developer extends Model
{
    protected $table = 'developers';

    public $timestamps = false;

    protected $fillable = ['name', 'inn'];

    /**
     * Получить объекты, связанные с этим застройщиком.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function objects()
    {
        return $this->hasMany(ObjectModel::class, 'developer_id');
    }


    /**
     * Получить пользователей, работающих в компании Застройщика
     */
    public function getUsers()
    {
        $userIDS = UserRoleRight::where('company_type','developer')->where('company_id', $this->id)->pluck('user_id');
        return User::whereIn('id', $userIDS)->get();
    }


    /**
     * Сохраняет или получает ID застройщика на основе данных из записи.
     *
     * @param object $record Запись из удаленной базы данных.
     * @return int|null ID застройщика или null, если данные отсутствуют.
     */
    public static function saveAndGetDeveloperID($record): int|null
    {
//            $record->ИНН Застройщика
//            $record->Застройщик
        $developer = self::where('inn', (int)$record->{'ИНН Застройщика'})->first();
        if (!$developer) {
            // Чистим название от цифр в начале, если есть
            $cleanName = preg_replace('/^\d+\s*/u', '', $record->{'Застройщик'});
//            self::warn('Застройщик: ' . $record->{'Застройщик'});
//            self::warn($record->{'ИНН Застройщика'} . ' => ' . $cleanName);
            if (empty($record->{'Застройщик'}) || empty($record->{'ИНН Застройщика'})) {
                return null;
            }
            $developer = new self();
            $developer->name = $cleanName;
            $developer->inn = (int)$record->{'ИНН Застройщика'};
            $developer->save();
        }

        return $developer->id;
    }


}