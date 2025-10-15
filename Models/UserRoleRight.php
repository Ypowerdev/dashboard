<?php

namespace App\Models;

use App\Models\Library\Oiv;
use Illuminate\Database\Eloquent\Model;

/**
 * СУЩНОСТЬ: РОЛИ и ПРАВА пользователя в системе.
 * Таблица хранит информацию о том, к какой организации принадлежит пользователь,
 * какую занимает должность и его права доступа по CRUD по отношению к объектам компании
 *
 * КАЖДЫЙ ПОЛЬЗОВАТЕЛЬ МОЖЕТ ПРИНАДЛЕЖАТЬ НЕСКОЛЬКИМ РАЗДИЧНЫМ ОРГАНИЗАЦИЯМ
 * И ИМЕТЬ РАЗНЫЕ ПРАВА НА СОЗДАНИЕ / ЧТЕНИЕ / РЕДАКТИРОВАНИЕ / СКРЫТИЕ записей
 *
 * Таблица: user_role_right
 *
 */
class UserRoleRight extends Model
{
    protected $table = 'user_role_right';

    /**
     * Отключение временных меток.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Атрибуты, которые можно массово присваивать.
     *
     * @var array
     */
    protected $fillable = [
        'user_id', 'company_type', 'company_id', 'position_in_company', 'rights'
    ];
    public $incrementing = true;
    protected $keyType = 'int';

    /**
     * Связь с пользователем (многие ко многим через данную таблицу).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Связь с компаниями по типу компании (один пользователь может принадлежать различным компаниям).
     * Система поддерживает следующие типы компаний:
     * oiv
     * customer
     * developer
     * contractor
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return match ($this->company_type) {
            'oiv' => $this->belongsTo(Oiv::class, 'company_id'),
            'customer' => $this->belongsTo(Customer::class, 'company_id'),
            'developer' => $this->belongsTo(Developer::class, 'company_id'),
            'contractor' => $this->belongsTo(Contractor::class, 'company_id'),
            'regulatory_authorities' => $this->belongsTo(RegulatoryAuthority::class, 'company_id'),
            default => null,
        };
    }

    /**
     * Проверяет наличие определенного права у пользователя в рамках одной записи
     *
     * @param string $right Одна из букв C, R, U, D (Create, Read, Update, Delete)
     * @return bool
     */
    public function hasRight(string $right): bool
    {
        return str_contains($this->rights, $right);
    }

    /**
     * Получить все объекты, к которым у пользователя есть доступ на чтение
     *
     * @return array
     */
    public static function getAllowedObjectToReadIDS($userId): array
    {
        $roles = self::where('user_id', $userId)->get();
        $companys = [];
        foreach ($roles as $role) {
            if ($role->hasRight('R')) {
                $companys[] = ['type' => $role->company_type, 'id' => $role->company_id];
            }
        }
        return self::getIDSfromCompanyes($companys);
    }

    /**
     * Получить все объекты, к которым у пользователя есть доступ на изменение
     *
     * @return array
     */
    public static function getAllowedObjectToUpdate($userId):array
    {
        $roles = self::where('user_id', $userId)->get();
        $companys = [];
        foreach ($roles as $role) {
            if ($role->hasRight('U')) {
                $companys[] = ['type' => $role->company_type, 'id' => $role->company_id];
            }
        }
        return self::getIDSfromCompanyes($companys);
    }

    /**
     * Получить все объекты, к которым у пользователя есть доступ на изменение
     *
     * @return array
     */
    public static function getAllowedObjectToUpdateOrCreate($userId):array
    {
        $roles = self::where('user_id', $userId)->get();
        $companys = [];
        foreach ($roles as $role) {
            if ($role->hasRight('U') || $role->hasRight('C')) {
                $companys[] = ['type' => $role->company_type, 'id' => $role->company_id];
            }
        }
        return self::getIDSfromCompanyes($companys);
    }

    public static function getUserCompanies($userId)
    {
        return self::where('user_id', $userId)->get();
    }

    public static function getCompanyNames(int $userId, array $neededRights = ['U','C']): array
    {
        $roles = self::where('user_id', $userId)->get();
        if ($roles->isEmpty()) return [];

        $companies = [];
        $companyTypes = [
            'oiv' => 'ОИВ', 
            'customer' => 'Заказчик' ,
            'developer' => 'Застройщик' ,
            'contractor' => 'Генподрядчик', 
            'regulatory_authorities' => 'Контролирующие органы',
        ];

        foreach ($roles as $role) {
            // проверяем, что есть хотя бы одно право из нужных
            $hasRight = false;
            foreach ($neededRights as $right) {
                if ($role->hasRight($right)) {
                    $hasRight = true;
                    break;
                }
            }
            if (!$hasRight) continue;

            $company = $role->company()?->first();
            if ($company && !empty($company->name)) {
                $companies[] = [
                    'type' => $companyTypes[$role->company_type] ?? 'Организация',  // oiv, customer, developer, contractor
                    'name' => $company->name,
                ];
            }
        }

        // убираем дубликаты (по type+name)
        $unique = collect($companies)
            ->unique(fn ($c) => $c['type'].'#'.$c['name'])
            ->values()
            ->toArray();

        return $unique;
    }

    private static function getIDSfromCompanyes($companys)
    {
        $allowedObjectsToReadIDS = [];

        foreach ($companys as $company) {
            switch ($company['type']){
                case 'developer':
                    $developer = Developer::where('id',$company['id'])->first();
                    if($developer) {
                        $ids = $developer->objects()->get()->pluck('id')->toArray();
                    }
                    break;
                case 'customer':
                    $customer = Customer::where('id',$company['id'])->first();
                    if ($customer) {
                        $ids = $customer->objects()->get()->pluck('id')->toArray();
                    }
                    break;
                case 'oiv':
                    $oiv = Oiv::where('id',$company['id'])->first();
                    if ($oiv) {
                        $ids = $oiv->objects()->get()->pluck('id')->toArray();
                    }
                    break;
                case 'contractor':
                    if ($contractor = Contractor::find($company['id'])) {
                        $ids = $contractor->objects()->pluck('id')->all();
                    }
                    break;
                case 'regulatory_authorities':
                    // 🎯 Главная правка: берём объекты из culture_manufactures
                    // $ids = DB::table('culture_manufactures')
                    $ids = CultureManufacture
                        ::where('author_org_type', 'regulatory_authority')
                        ->where('author_org_id', (int)$company['id'])
                        ->distinct()
                        ->pluck('object_id')
                        ->all();
                    break;
            default:
                $ids = [];
            }

            if ($ids) {
                $allowedObjectsToReadIDS = array_merge($allowedObjectsToReadIDS, $ids);
            }
        }
        
        // Уникализуем + приводим к int
        $allowedObjectsToReadIDS = array_map('intval', $allowedObjectsToReadIDS);
        $allowedObjectsToReadIDS = array_values(array_unique($allowedObjectsToReadIDS, SORT_NUMERIC));

        return $allowedObjectsToReadIDS;
    }

    public function getCompanyNameAttribute(): ?string
    {
        // Сначала получим модель через match
        $relation = match ($this->company_type) {
            'oiv'                    => Oiv::class,
            'customer'               => Customer::class,
            'developer'              => Developer::class,
            'contractor'             => Contractor::class,
            'regulatory_authorities' => RegulatoryAuthority::class,
            default                  => null,
        };

        if (!$relation) return null;

        return $relation::find($this->company_id)?->name ?? '';
    }

}
