<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * СУЩНОСТЬ: Генподрядчики. Модель для таблицы general_contractors. Хранит список генподрядчиков.
 *
 * @property int $id Уникальный идентификатор генподрядчика.
 * @property string $name Название генподрядчика.
 * @property int $inn ИНН генподрядчика, должен содержать ровно 10-12 цифр.
 */
class Contractor extends Model
{
    protected $table = 'general_contractors';

    public $timestamps = false;

    protected $fillable = ['name', 'inn'];

    /**
     * Получить объекты, связанные с этим генподрядчиком.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function objects()
    {
        return $this->hasMany(ObjectModel::class, 'contractor_id');
    }


    /**
     * Получить пользователей, работающих в компании Генерального подрайчика
     */
    public function getUsers()
    {
        $userIDS = UserRoleRight::where('company_type','contractor')->where('company_id', $this->id)->pluck('user_id');
        return User::whereIn('id', $userIDS)->get();
    }



    /**
     * Сохраняет или получает ID генподрядчика на основе данных из записи.
     *
     * @param object $record Запись из удаленной базы данных.
     * @return int|null ID генподрядчика или null, если данные отсутствуют.
     */
    public static function saveAndGetContractorID($record): int|null
    {
//            $record->ИНН Генподрядчика
//            $record->Генподрядчик
        $contractor = self::where('inn', (int)$record->{'ИНН Генподрядчика'})->first();
        if (!$contractor) {
//            self::warn('Генподрядчик: ' . $record->{'Генподрядчик'});
//            self::warn('ИНН Генподрядчика: ' . $record->{'ИНН Генподрядчика'});
            if (empty($record->{'Генподрядчик'}) || empty($record->{'ИНН Генподрядчика'})) {
                return null;
            }
            $contractor = new self();
            $contractor->name = $record->{'Генподрядчик'};
            $contractor->inn = (int)$record->{'ИНН Генподрядчика'};
            $contractor->save();
        }

        return $contractor->id;
    }


}
