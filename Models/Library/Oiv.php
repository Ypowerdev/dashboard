<?php

namespace App\Models\Library;

use App\Models\Customer;
use App\Models\ObjectModel;
use App\Models\User;
use App\Models\UserRoleRight;
use Illuminate\Database\Eloquent\Model;

/**
 * СУЩНОСТЬ: ОИВ. Модель для таблицы oivs. Хранит список ОИВ.
 *
 * @property int $id Уникальный идентификатор органа исполнительной власти (ОИВ).
 * @property string $name Название ОИВ.
 * @property string $inn ИНН органа исполнительной власти.
 */
class Oiv extends Model
{
    protected $table = 'oivs';

    public $timestamps = false;

    protected $fillable = ['name', 'inn'];

    /**
     * Получить объекты, связанные с этим ОИВ.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function objects()
    {
        return $this->hasMany(ObjectModel::class, 'oiv_id');
    }

    /**
     * Получить заказчиков, связанных с этим ОИВ.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function customers()
    {
        return $this->hasMany(Customer::class, 'oiv_id');
    }

    /**
     * Получить пользователей, работающих в компании ОИВ
     */
    public function getUsers()
    {
        $userIDS = UserRoleRight::where('company_type','oiv')->where('company_id', $this->id)->pluck('user_id');
        return User::whereIn('id', $userIDS)->get();
    }

}