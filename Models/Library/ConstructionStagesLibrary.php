<?php

namespace App\Models\Library;

use App\Models\ObjectConstructionStage;
use App\Models\ObjectModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * БИБЛИОТЕКА: Этапы строительства. Таблицы: construction_stages_library + object_construction_stages
 *
 * @property int $id Уникальный идентификатор этапа строительства.
 * @property string $name Название этапа строительства.
 * @property int|null $tep_id_plan tep_xxxx дублируем сюда для удобства идентификации и связи с tep
 * @property int|null $tep_id_fact tep_xxxx дублируем сюда для удобства идентификации и связи с tep
 * @property string|null $comments Комментарии к этапу строительства.
 * @property int|null $parent_id Это подкатегории строительных этапов.
 */
class ConstructionStagesLibrary extends Model
{
    protected $table = 'construction_stages_library';
    public const NAME_STROYGOTOVNOST = 'СТРОИТЕЛЬНАЯ ГОТОВНОСТЬ';
    public $timestamps = false;

    protected $fillable = [
        'name', 'view_name', 'comments', 'parent_id'
    ];

    // Константы для идентификации этапов - названия из БД, которые анализируеются.. если в библиотеке поменяют названия - посыпется
    public static $constructionStageNames = [
        'DEVELOP_PD' => 'РАЗРАБОТКА ПД',
        'SUBMISSION' => 'СДАЧА ПД В МГЭ',
        'COMMENTS' => 'ПОЛУЧЕНИЕ ЗАМЕЧАНИЙ МГЭ',
        'FIXING' => 'СНЯТИЕ ЗАМЕЧАНИЙ',
        'CONCLUSION' => 'ПОЛУЧЕНИЕ ЗАКЛЮЧЕНИЯ МГЭ',
    ];

    /**
     * Получить объекты, связанные с этим этапом строительства.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function objects()
    {
        return $this->belongsToMany(ObjectModel::class, 'object_construction_stages', 'construction_stages_library_id', 'object_id')
            ->withTimestamps()
            ->withPivot(
                'object_id',
                'construction_stages_library_id',
                'user_id',
                'created_date',
                'smg_plan',
                'smg_fact',
                'smg_fact_start',
                'smg_fact_finish',
                'oiv_plan_start',
                'oiv_plan_finish',
                'oiv_plan',
                'oiv_fact',
            );
    }

    /**
     * Get the construction stages for this library entry.
     */
    public function objectConstructionStages()
    {
        return $this->hasMany(ObjectConstructionStage::class, 'construction_stages_library_id');
    }

    public function parent()
    {
        return $this->belongsTo(ConstructionStagesLibrary::class, 'parent_id');
    }

    public function childrens()
    {
        return $this->hasMany(ConstructionStagesLibrary::class, 'parent_id');
    }

    /**
     * Создает и возвращает пустой структурированный массив Строительных Этапов и подэтапов, который мы будем наполнять данными позже
     */
    public static function getConstructionStageEmptyFactureArray($object)
    {
        $IDS = self::getRequiredConstructionLibraryIDSbyObjectType($object);
        $constructionStages = ConstructionStagesLibrary::with([
            'childrens' => function ($query) use ($IDS) {
                $query->whereIn('id', $IDS);
            }
        ])
        ->whereIn('id', $IDS)
        ->orderBy('id')
        ->get();


        // Преобразуем коллекцию в массив
        $stagesArray = $constructionStages->keyBy('id')->toArray();

        // Обрабатываем children, чтобы ключи были равны их id
        foreach ($stagesArray as $key => &$stage) {
            if (!empty($stage['children'])) {
                $children = [];
                foreach ($stage['children'] as $child) {
                    $children[$child['id']] = $child; // Явно задаём ключ = id
                }
                $stage['children'] = $children;
            }
            if(!empty($stage['parent_id'])){
                unset($stagesArray[$key]);
            }
        }

        return $stagesArray;
    }


    /**
     * Создает и возвращает пустой структурированный массив Строительных Этапов и подэтапов, который мы будем наполнять данными позже
     */
    public static function getConstructionStageEmptyFactureArrayByIDS($IDS)
    {
        $constructionStages = ConstructionStagesLibrary::with(['childrens'])
            ->whereIn('id', $IDS)
            ->get();


        // Преобразуем коллекцию в массив
        $stagesArray = $constructionStages->keyBy('id')->toArray();

        // Обрабатываем children, чтобы ключи были равны их id
        foreach ($stagesArray as $key => &$stage) {
            if (!empty($stage['children'])) {
                $children = [];
                foreach ($stage['children'] as $child) {
                    $children[$child['id']] = $child; // Явно задаём ключ = id
                }
                $stage['children'] = $children;
            }
            if(!empty($stage['parent_id'])){
                unset($stagesArray[$key]);
            }
        }

        return $stagesArray;
    }


    /**
     * Отдает набор IDS для объекта в зависимости от его типа. Используется для фильтрации выборки
     */
    public static function getRequiredConstructionLibraryIDSbyObjectType($object){

        $constructionStagesNamesIDS = ObjectConstructionStage::where('object_id',$object->id)
            ->distinct()
            ->pluck('construction_stages_library_id')
            ->toArray();

        return $constructionStagesNamesIDS;

    }

    /**
     * Сохраняет Строительный Этап и возвращает ее ID.
     * Если Строительный Этап уже существует, то возвращает ее ID,
     * иначе создает новую запись и возвращает ID новой записи.
     *
     * @param string $name Название Строительный Этап
     * @param int|null $parentId ID родительской Строительный Этап (если есть)
     * @return int ID Строительный Этап
     */
    public static function saveAndGetConstructionStageID($name, $parentId = null)
    {
        $cleanName = self::cleanTextVariable($name);
        $cleanName = mb_strtoupper($cleanName);

        // Формируем уникальный ключ кеша с учетом parentId
        $cacheKey = 'construction_stage_' . $cleanName . ($parentId ? '_parent_' . $parentId : '');
        $stageId = cache()->get($cacheKey);

        if (!$stageId) {
            $query = DB::table('construction_stages_library')
                ->where('name', $cleanName);

            // Если передан parentId, сначала ищем с учетом parentId
            if ($parentId !== null) {
                $stage = $query->where('parent_id', $parentId)->first();

                if ($stage) {
                    $stageId = $stage->id;
                } else {
                    // Если не нашли с parentId, ищем без него
                    $stage = $query->whereNull('parent_id')->first();

                    if ($stage) {
                        $stageId = $stage->id;
                    }
                }
            } else {
                // Для случая без parentId просто ищем по имени
                $stage = $query->first();
                if ($stage) {
                    $stageId = $stage->id;
                }
            }

            // Если этап не найден, создаем новый
            if (!$stageId) {
                $data = ['name' => $cleanName];
                if ($parentId !== null) {
                    $data['parent_id'] = $parentId;
                }

                $stageId = DB::table('construction_stages_library')->insertGetId($data);
            }

            // Кешируем на 1 час, что бы не мучить БД типовыми запросами
            cache()->put($cacheKey, $stageId, 3600);
        }

        return $stageId;
    }

    private static function cleanTextVariable($text) {
        // Список значений, которые нужно удалить
        $patterns = [
            '/СТРЭТАП/',
            '/\(плановая дата начала\)/',
            '/\(плановая дата завершения\)/',
            '/\(фактическая дата начала\)/',
            '/\(фактическая дата завершения\)/',
            '/\(план\)/',
            '/\(факт\)/',
            '/\(наличие\)/',
            '/\(оценка\)/',
            '/\("\)/',
            '/\(\% выполнения, план\)/',
            '/\(\% выполнения, факт\)/',
        ];

        // Удаляем указанные значения
        $cleanedText = preg_replace($patterns, '', $text);

        // Удаляем пробелы в начале и конце строки
        $cleanedText = trim($cleanedText);

        return $cleanedText;
    }

    /**
     * Проверяет, является ли этап "СТРОИТЕЛЬНАЯ ГОТОВНОСТЬ"
     */
    public function isStroygotovnost(): bool
    {
        return $this->name === self::NAME_STROYGOTOVNOST;
    }
}
