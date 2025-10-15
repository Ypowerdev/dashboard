<?php
namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;

/**
 * СУЩНОСТЬ: Пользователь системы
 * Хранит информацию о пользователях
 *
 */

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens;
    use HasFactory, Notifiable;

    protected $fillable = [
        'email',
        'password',
        'name',
        'family_name',
        'father_name',
        'comment',
        'is_admin',
        'access_to_raiting',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->is_admin) {
            return true;
        }

        // Любое право C или U хотя бы по одной записи в user_role_right
        return $this->roleRights()
            ->where(function ($q) {
                $q->where('rights', 'like', '%C%')
                ->orWhere('rights', 'like', '%U%');
            })
            ->exists();
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function getUsersByIDS($IDS)
    {
        return $this->whereIn('id', $IDS)->get();
    }

    static public function saveAndGetUserIDbyANOusername($AnoUserName):int|null
    {
        if(empty($AnoUserName)){
            return null;
        }
        $user = User::where('father_name',$AnoUserName)->first();
        if (!$user){
            $user = new User();
            $user->name = 'user АНО СТРОИНФОРМ '.$AnoUserName;
            $user->family_name = 'user АНО СТРОИНФОРМ '.$AnoUserName;
            $user->father_name = 'user АНО СТРОИНФОРМ '.$AnoUserName;
            $user->save();
        }

        return $user->id;
    }

    static public function getUserIDbyANOusername($AnoUserName):int|null
    {
        if(empty($AnoUserName)){
            return null;
        }
        $user = User::where('father_name',$AnoUserName)->first();

        return $user->id;
    }

    public static function getUserByToken($token)
    {
        return self::where('id',cache()->get('cacheActivatedUserID_'.$token))->first();
    }

    public function rights()
    {
        return $this->hasOne(UserRoleRight::class, 'user_id');
    }

    public function roleRights()
    {
        return $this->hasMany(UserRoleRight::class, 'user_id');
    }

    public function hasRight(string $right, string $companyType, int $companyId): bool
    {
        return $this->roleRights()
            ->where('company_type', $companyType)
            ->where('company_id', $companyId)
            ->where('rights', 'like', "%{$right}%")
            ->exists();
    }

    public function getAllowedOrganizationIds(string $companyType): array
    {
        return $this->roleRights()
            ->where('company_type', $companyType)
            ->where('rights', 'like', '%R%') // Проверяем право на чтение
            ->pluck('company_id')
            ->toArray();
    }

    public function hasRightToAnyOrganization(string $requiredRight, string $type): bool
    {
        // Логика проверки, есть ли у пользователя право $requiredRight
        // в любой организации типа $type (например, через отношения)
        return $this->roleRights()
            ->where('company_type', $type)
            ->where('rights', 'like', "%{$requiredRight}%")
            ->exists();
    }

    public function setPasswordAttribute($value)
    {
        // Если пароль уже захэширован (например, начинается с '$2y$'), не хэшируем повторно
        if (!empty($value) && !str_starts_with($value, '$2y$')) {
            $value = Hash::make($value);
        }

        $this->attributes['password'] = $value;
    }

    // Акцессор: склеивает Фамилию Имя Отчество, пропуская пустые
    public function getFullnameAttribute(): string
    {
        $parts = array_filter([
            $this->family_name,
            $this->name,
            $this->father_name,
        ], fn ($v) => filled($v));

        return trim(implode(' ', $parts));
    }


}
