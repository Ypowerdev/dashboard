<?php

namespace App\Models\Library;

use App\Models\ObjectModel;
use Illuminate\Database\Eloquent\Model;

/**
 * СУЩНОСТЬ: РП объекта. Модель для таблицы project_managers_library. Хранит список РП объектов.
 *
 * @property int $id Уникальный идентификатор заказчика.
 * @property string $name Имя РП.
 * @property string $photo Путь к фото.
 */
class ProjectManagerLibrary extends Model
{
    protected $table = 'project_managers_library';

    public $timestamps = false;

    protected $fillable = ['name', 'photo'];

    /**
     * Получить объекты, связанные с этим РП.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function objects()
    {
        return $this->hasMany(ObjectModel::class, 'project_manager_id');
    }

    /**
     * Сохраняет или получает ID РП по его Имени
     *
     * @param string $name Имя из выгрузки
     * @returns int|null
     */
    public static function saveAndGetIdByName(string $name): int|null
    {
        $manager = self::where('name', $name)->first();

        if (!$manager) {
            $manager = new self();
            $manager->name = $name;
            $manager->save();
        }

        return $manager->id;
    }

}
