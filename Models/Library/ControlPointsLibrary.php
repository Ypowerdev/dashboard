<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;

/**
 * БИБЛИОТЕКА: Контрольные точки, Таблицы control_points_library + object_control_point
 *
 * @property int $id Уникальный идентификатор Контрольная точка.
 * @property string $name Название Контрольная точка проекта.
 * @property int|null $parent_id Используется для подкатегорий контрольных точек.
 */
class ControlPointsLibrary extends Model
{
    // Константы для маппинга контрольных точек на этапы реализации
    public const MAPPING_TO_ETAPI = [
        'Получение Градостроительного плана земельного участка (ГПЗУ)' => 'ГПЗУ',
        'Заключение договоров технологического присоединения' => 'ТУ от РСО', //!! ОБЩКОНТРТОЧКА  -  в маппинге временно не участвует
        'Разработка и согласование архитектурно-градостроительных решений (АГР)' => 'АГР',
        'Получение положительного заключения Мосгосэкспертизы' => 'Экспертиза ПСД',
        'Получение разрешения на строительство' => 'РНС',
        'Выполнение строительно-монтажных работ' => 'СМР',
        'Устройство инженерных систем' => 'Техприс', //!! ОБЩКОНТРТОЧКА  -  в маппинге временно не участвует
        'Получение заключения о соответствии (ЗОС)' => 'ЗОС', //!! ОБЩКОНТРТОЧКА  -  в маппинге временно не участвует
        'Получение разрешения на ввод объекта в эксплуатацию' => 'РВ'
    ];

    public $timestamps = false;

    protected $table = 'control_points_library';

    protected $fillable = [
        'name',
        'view_name',
        'performer',
        'parent_id',
        'comments',
        'icon_or_percent'
    ];

    /**
     * Получить дочерние элементы этой контрольной точки
     */
    public function children()
    {
        return $this->hasMany(ControlPointsLibrary::class, 'parent_id', 'id');
    }

    /**
     * Получить родительский элемент этой контрольной точки
     */
    public function parent()
    {
        return $this->belongsTo(ControlPointsLibrary::class, 'parent_id');
    }

    /**
     * Проверить, есть ли у этой КТ дочерние элементы
     */
    public function hasChildren()
    {
        return ControlPointsLibrary::where('parent_id', $this->id)->exists();
    }

    /**
     * Проверить, есть ли маппинг для контрольной точки на этапы реализации по названию
     *
     * @param string $controlPointName Название контрольной точки
     * @return bool
     */
    public static function hasEtapMappingByName($controlPointName)
    {
        foreach (self::MAPPING_TO_ETAPI as $cpName => $etapName) {
            if (stripos(mb_strtoupper($controlPointName, 'UTF-8'), mb_strtoupper($cpName, 'UTF-8')) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Получить название этапа реализации для контрольной точки по названию
     *
     * @param string $controlPointName Название контрольной точки
     * @return int|null ID этапа реализации или null если нет маппинга
     */
    public static function getEtapIdForControlPoint($controlPointName)
    {
        foreach (self::MAPPING_TO_ETAPI as $cpName => $etapName) {
            if (stripos(mb_strtoupper($controlPointName, 'UTF-8'), mb_strtoupper($cpName, 'UTF-8')) !== false) {
                // Находим ID этапа реализации по его имени
                return EtapiRealizaciiLibrary::saveAndGetEtapiRealizaciiID(mb_strtoupper($etapName, 'UTF-8'));
            }
        }

        return null;
    }

    /**
     * Проверить, есть ли маппинг для контрольной точки на этапы реализации
     *
     * @param string $controlPointName Название контрольной точки
     * @return bool
     */
    public static function hasEtapMapping($controlPointName)
    {
        return self::getEtapIdForControlPoint($controlPointName) !== null;
    }

    /**
     * Сохраняет контрольную точку и возвращает ее ID.
     * Если контрольная точка уже существует, то возвращает ее ID,
     * иначе создает новую запись и возвращает ID новой записи.
     *
     * @param string $name Название контрольной точки
     * @param int|null $parentId ID родительской контрольной точки (если есть)
     * @return int ID контрольной точки
     */
    public static function saveAndGetIDbyName($name, $parentId = null)
{
    // Проверяем наличие триггерных слов
    $isObshayaControlnayaTochka = false;
    if (strpos($name, 'ОБЩКОНТРТОЧКА') !== false) {
        $isObshayaControlnayaTochka = true;
        $parentId = null; // Общее контрольное точка всегда с null parent_id
        $name = str_replace('ОБЩКОНТРТОЧКА ', '', $name);
        $name = trim($name);
        $name = mb_strtoupper($name);
    } elseif (strpos($name, 'КОНТРТОЧКА') !== false) {
        $name = str_replace('КОНТРТОЧКА ', '', $name);
        $name = trim($name);
        $name = mb_strtoupper($name);
        // parentId остается как есть
    } else {
        // Если триггерных слов нет, просто очищаем и делаем uppercase
        $name = trim($name);
        $name = mb_strtoupper($name);
    }

    // Генерируем ключ кэша
    $cacheKey = 'control_point_library_' . str_replace(' ', '', $name) . ($parentId ?? 'null');

    $controlPointId = cache()->get($cacheKey);

    if (!$controlPointId) {
        // Строим условие поиска в зависимости от типа контрольной точки
        $query = self::where('name', $name);

        if ($isObshayaControlnayaTochka) {
            // Общая контрольная точка - parent_id должно быть null
            $query->whereNull('parent_id');
        } else {
            // Обычная контрольная точка - parent_id должно быть равным $parentId
            $query->where('parent_id', $parentId);
        }

        $controlPointLibrary = $query->first();

        if (!$controlPointLibrary) {
            // Создаем новую запись
//            return null;
            $controlPointLibrary = self::create([
                'name' => $name,
                'parent_id' => $isObshayaControlnayaTochka ? null : $parentId,
            ]);
        }
        // Обновляем кеш
        cache()->put($cacheKey, $controlPointLibrary->id, 3600);
        $controlPointId = $controlPointLibrary->id;
    }

    return $controlPointId;
}

    /**
     * Создает и возвращает пустой структурированный массив Контрольных точек, так как они прописаны в БИТБЛИОТЕКЕ КТ.
     */
    public static function getControlPointsEmptyFactureArray($IDS)
    {
        // 1. Получаем элементы по $IDS + их родителей
        $items = self::whereIn('id', $IDS)->get();
        $parentIds = $items->pluck('parent_id')->filter()->unique()->toArray();
        $allItems = self::whereIn('id', $IDS)
            ->orWhereIn('id', $parentIds)
            ->get()
            ->keyBy('id') // Индексируем по id сразу
            ->toArray();

        // 2. Строим дерево, где ключи = id
        $tree = [];
        $childrenMap = [];

        // Группируем детей по parent_id
        foreach ($allItems as $id => $item) {
            if ($item['parent_id'] !== null) {
                $childrenMap[$item['parent_id']][$id] = $item;
            }
        }

        // Добавляем children и формируем дерево
        foreach ($allItems as $id => &$item) {
            $item['children'] = $childrenMap[$id] ?? [];
            if ($item['parent_id'] === null) {
                $tree[$id] = $item; // Ключ = id
            }
        }

        return $tree;
    }

}
