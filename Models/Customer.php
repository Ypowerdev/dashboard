<?php

namespace App\Models;

use App\Models\Library\Oiv;
use Illuminate\Database\Eloquent\Model;

/**
 * СУЩНОСТЬ: Заказчики. Модель для таблицы customers. Хранит список заказчиков.
 *
 * @property int $id Уникальный идентификатор заказчика.
 * @property string $name Название заказчика.
 * @property int $inn ИНН заказчика, должен содержать ровно 10-12 цифр.
 * @property int $oiv_id Идентификатор связанного ОИВ.
 */
class Customer extends Model
{
    protected $table = 'customers';

    public $timestamps = false;

    protected $fillable = ['name', 'oiv_id', 'inn'];

    /**
     * Получить объекты, связанные с этим заказчиком.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function objects()
    {
        return $this->hasMany(ObjectModel::class, 'customer_id');
    }

    /**
     * Получить ОИВ, к которому относится этот заказчик.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function oiv()
    {
        return $this->belongsTo(Oiv::class, 'oiv_id');
    }

    /**
     * Получить пользователей, работающих в компании Заказчика
     */
    public function getUsers()
    {
        $userIDS = UserRoleRight::where('company_type','customer')->where('company_id', $this->id)->pluck('user_id');
        return User::whereIn('id', $userIDS)->get();
    }


    /**
     * Сохраняет или получает ID заказчика на основе данных из записи.
     *
     * @param object $record Запись из удаленной базы данных.
     * @return int|null ID заказчика или null, если данные отсутствуют.
     */
    public static function saveAndGetCustomerID($record, $oivId = null): int|null
    {
//            $record->ИНН Заказчика
//            $record->Заказчик
        $customer = self::where('inn', (int)$record->{'ИНН Заказчика'})->first();
        if (!$customer) {
            if (empty($record->{'Заказчик'}) || empty($record->{'ИНН Заказчика'})) {
                return null;
            }
            $customer = new self();
            $customer->name = $record->{'Заказчик'};
            $customer->inn = (int)$record->{'ИНН Заказчика'};
            $customer->oiv_id = $oivId;
            $customer->save();
        }

        return $customer->id;
    }

}