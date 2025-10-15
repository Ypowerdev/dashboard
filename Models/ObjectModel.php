<?php

namespace App\Models;

use App\Models\Library\ConstructionStagesLibrary;
use App\Models\Library\ControlPointsLibrary;
use App\Models\Library\EtapiRealizaciiLibrary;
use App\Models\Library\FnoLevelLibrary;
use App\Models\Library\FnoLibrary;
use App\Models\Library\MapCoordinatesLibrary;
use App\Models\Library\Oiv;
use App\Models\Library\OksStatusLibrary;
use App\Models\Library\ProjectManagerLibrary;
use App\Models\Library\RegionLibrary;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

/**
 * СУЩНОСТЬ: Объект. Самая основная сущность проекта, хранит в себе все записи по объекты и связи с ним. Таблица objects.
 *
 * @property int $id Уникальный идентификатор объекта.
 * @property string $uin Уникальный идентификационный номер объекта (UIN).
 * @property string $name Имя объекта, его наименование.
 * @property string $address Адрес. Полный или ориентировочный.
 * @property bool $renovation Принадлежит ли проект Реновации?
 * @property float $area Площадь застройки.
 * @property bool $risks_flag Есть ли риски не сдачи вовремя. Вычисляемая единица по формуле. Должна обновляться раз в сутки.
 * @property bool $breakdowns_flag Есть ли проблемы на стройке, которые не решаются оперативно.
 * @property bool $overdue Возможно рудимент.
 * @property bool $expiration_possible Возможно рудимент.
 * @property \Illuminate\Support\Carbon|null $deadline Возможно рудимент.
 * @property int $readiness Возможно рудимент.
 * @property int $readiness_percentage Возможно рудимент.
 * @property string|null $link Ссылка на цифровой паспорт объекта.
 * @property int|null $fno_type_code Ссылка на вид функционального назначения объекта.
 * @property int|null $oiv_id Ссылка на орган исполнительной власти.
 * @property int|null $region_id Ссылка на район города.
 * @property int|null $fin_sources_id Ссылка на источник финансирования.
 * @property int|null $customer_id Ссылка на заказчика.
 * @property int|null $developer_id Ссылка на застройщика.
 * @property int|null $contractor_id Ссылка на генподрядчика.
 * @property \Illuminate\Support\Carbon|null $aip_inclusion_date Дата включения объекта в Адресную Инвестиционную Программу (АИП).
 * @property \Illuminate\Support\Carbon|null $contract_date Дата контрактации объекта.
 * @property \Illuminate\Support\Carbon|null $planned_commissioning_directive_date Дата планового ввода объекта по директивному графику.
 * @property \Illuminate\Support\Carbon|null $contract_commissioning_date Дата ввода объекта по договору.
 * @property \Illuminate\Support\Carbon|null $forecasted_commissioning_date Дата прогнозируемого ввода объекта в эксплуатацию.
 * @property \Illuminate\Support\Carbon|null $planned_commissioning_directive_think_change_date Дата директивного ввода объекта в эксплуатацию
 * @property int|null $oks_status_id Ссылка на статус объекта капитального строительства.
 * @property bool|null aip_flag Флаг АИП
 * @property float aip_prepay Сумма Аванс по АИП в млрд. руб
 * @property float aip_sum Сумма по АИП в млрд. руб
 * @property int aip_year Год ввода в АИП, первый экран, фильтр
 * @property float length Протяженность
 * @property string floors Этажность
 * @property string ct_deadline_failure Факт срыва срока // расчетный показатель из ночного скрипта
 * @property string ct_deadline_high_risk Факт высокого риска // расчетный показатель из ночного скрипта
 * @property int|null $parent_id Ссылка на Родительский УИН.
 * @property int|null $project_manager_id Ссылка на РП.
 * @property int|null $general_designer_id Ссылка на Генерального проектировщика.
 * @property float|null $aip_done_until_2025 Выполнено на 01.01.2025
 * @property float|null $ssr_cost Стоимость по CCP
 * @property string|null $aip_years_sum_json Суммы по годам.
 * @property string|null $suid_ksg_url Ссылка на СУИД
 */
class ObjectModel extends Model
{
    // use SoftDeletes;

    protected static $entity_type = 'Строительный объект';
    protected $table = 'objects';

    public $timestamps = false;
    // protected $observers = [ObjectModelObserver::class];

    protected $fillable = [
        'uin', 'name', 'address', 'renovation', 'area', 'risks_flag', 'breakdowns_flag', 'overdue', 'expiration_possible',
        'deadline', 'readiness', 'readiness_percentage', 'link', 'fno_type_code', 'oiv_id', 'region_id', 'fin_sources_id',
        'customer_id', 'developer_id', 'contractor_id', 'aip_inclusion_date', 'contract_date', 'planned_commissioning_directive_date', 'is_object_directive',
        'contract_commissioning_date', 'forecasted_commissioning_date', 'oks_status_id','aip_flag','aip_prepay','aip_sum','contract_sum','updated_date',
        'planned_commissioning_directive_think_change_date','aip_year','length', 'floors','year_of_introduction',
        'finance_advance', 'finance_readiness', 'finance_planned','master_kod_dc','raw_land_plot_cad_num','latitude_longitude','coordinates',
        'ct_deadline_failure', 'ct_deadline_high_risk', 'project_manager_id', 'parent_id','suid_ksg_url', 'ssr_cost', 'general_designer_id', 'fno_engineering'
    ];

    // protected $dateFormat = [
    //     'year_of_introduction',
    //     'ano_smg_updated_date',
    //     'updated_date',
    //     'planned_commissioning_directive_think_change_date',
    //     'forecasted_commissioning_date',
    //     'contract_commissioning_date',
    //     'planned_commissioning_directive_date',
    //     'contract_date',
    //     'aip_inclusion_date',
    // ];

    protected $casts = [
        'ssr_cost' => 'float',
    ];

    /**
     * Родительский объект
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ObjectModel::class, 'parent_id');
    }

    /**
     * Дочерние объекты
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function children(): HasMany
    {
        return $this->hasMany(ObjectModel::class, 'parent_id');
    }

    /**
     * Проверяет, имеет ли объект дочерние объекты
     *
     * @return bool
     */
    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    /**
     * Проверяет, имеет ли объект родительский объект
     *
     * @return bool
     */
    public function hasParent(): bool
    {
        return !is_null($this->parent_id);
    }

    /**
     * Получить фотографии, связанные с этим объектом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function photos()
    {
        return $this->hasMany(Photo::class, 'object_uin','uin');
    }

    /**
     * Получить данные мониторинга людей, связанные с этим объектом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function monitorPeople()
    {
        return $this->hasMany(MonitorPeople::class, 'object_id');
    }

    /**
     * Получить данные мониторинга техники, связанные с этим объектом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function monitorTechnica()
    {
        return $this->hasMany(MonitorTechnica::class, 'object_id');
    }

    /**
     * Получить этапы строительства, связанные с этим объектом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function constructionStagesWithPivot()
    {
        return $this->belongsToMany(ConstructionStagesLibrary::class, 'object_construction_stages', 'object_id', 'construction_stages_library_id')
            ->withPivot('start_date_plan', 'end_date_plan', 'start_date_fact', 'end_date_fact', 'developer_fact', 'ano_fact', 'user_id', 'created_date', 'deleted_at');
    }

    /**
     * Получить контрольные точки, связанные с этим объектом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function objectControlPoint()
    {
        return $this->hasMany(ObjectControlPoint::class, 'object_id');
    }

    /**
     * Получить этапы реализации, связанные с этим объектом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function ObjectEtapiRealizacii()
    {
        return $this->hasMany(ObjectEtapiRealizacii::class, 'object_id');
    }

    /** Получить контрольные точки, связанные с этим объектом.
    *
    * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
    */
    public function controlPointsLibraryWithPivot()
    {
        return $this->belongsToMany(ControlPointsLibrary::class, 'object_control_point', 'object_id', 'stage_id')
            ->withPivot('plan_finish_date', 'fact_finish_date', 'user_id', 'created_at', 'updated_at', 'deleted_at');
    }


    /**
     * Получить 'этапы реализации', связанные с этим объектом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function etapiRealizaciiLibraryWithPivot()
    {
        return $this->belongsToMany(related: EtapiRealizaciiLibrary::class, table: 'object_etapi_realizacii', foreignPivotKey: 'object_id', relatedPivotKey: 'etap_id')
            ->withPivot('plan_finish_date', 'fact_finish_date', 'user_id', 'created_at', 'updated_at', 'deleted_at');
    }


    public function getAllEtapiWithPivotData()
    {
        // Получаем все записи из библиотеки
        $allEtapi = EtapiRealizaciiLibrary::orderBy('id')->get();

        // Получаем PIVOT-данные для текущего объекта
        $pivotData = $this->etapiRealizaciiLibraryWithPivot()
            ->get()
            ->keyBy('id'); // Группируем по ID этапа

        // Объединяем данные
        return $allEtapi->map(function ($etap) use ($pivotData) {
            $etap->pivot = $pivotData->get($etap->id)?->pivot ?? null;
            return $etap;
        });
    }

    /**
     * Получить орган исполнительной власти, связанный с этим объектом.
     *
     * Связь через заказчика, который связан с ОИВ. Но здесь мы дублируем oiv_id для ускорения работы фильтра
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function oiv()
    {
        return $this->belongsTo(Oiv::class, 'oiv_id');
    }

    /**
     * Получить район, связанный с этим объектом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
//    todo: переименовать в regionLibrary
    public function region()
    {
        return $this->belongsTo(RegionLibrary::class, 'region_id')->with('DistrictLibrary');
    }

    /**
     * Получить источник финансирования, связанный с этим объектом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function finSource()
    {
        return $this->belongsTo(FinSource::class, 'fin_sources_id');
    }

    /**
     * Получить заказчика, связанного с этим объектом.
     *
     * У объекта только один заказчик.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Получить застройщика, связанного с этим объектом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function developer()
    {
        return $this->belongsTo(Developer::class, 'developer_id');
    }

    /**
     * Получить генподрядчика, связанного с этим объектом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function contractor()
    {
        return $this->belongsTo(Contractor::class, 'contractor_id');
    }

    /**
     * Получить записи Причины срыва сроков и мероприятия по их устранению, связанные с этим объектом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function breakdowns()
    {
        return $this->hasMany(BreakDown::class, 'object_id');
    }

    /**
     * Получить статусы объекта капитального строительства.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function oksStatusWithPivot()
    {
        return $this->belongsToMany(OksStatusLibrary::class, 'object_oks_status_changes', 'object_id', 'oks_status_library_id')
            ->withTimestamps()
            ->withPivot('user_id');
    }

    /**
     * Получить статус объекта капитального строительства.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function oksStatusLibrary()
    {
        return $this->belongsTo(OksStatusLibrary::class,'oks_status_id');
    }

    /**
     * Получить ФНО Из таблицы Функционального назначения
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function fno()
    {
        return $this->belongsTo(FnoLibrary::class, 'fno_type_code', 'type_code');
    }

    /**
     * Получить ФНО Из таблицы ФНО уровни для фильтра
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function fno_level()
    {
        return $this->belongsTo(FnoLevelLibrary::class, 'fno_type_code', 'type_code');
    }

    /**
     * TODO: вопросы к Артёму, он должен дать объяснения по поводу названия метода и несоответствия подключаемой связанной модели
     * Получить нарушения по культуре производства
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function culture_manufacture()
    {
        return $this->belongsTo(FnoLevelLibrary::class, 'object_id', 'id');
    }

    /**
     * TODO: вопросы к Артёму, он должен дать объяснения
     * Получить нарушения по культуре производства
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function cultureManufacture()
    {
        return $this->hasMany(CultureManufacture::class, 'object_id', 'id');
    }

    /**
     * Get the construction stages for this object.
     */
    public function objectConstructionStages()
    {
        return $this->hasMany(ObjectConstructionStage::class, 'object_id');
    }

    /**
     * Получить контрольные точки объекта
     */
    public function controlPoints()
    {
        return $this->hasMany(ObjectControlPoint::class, 'object_id')->with('controlPointLibrary');
    }

    /**
     * Получить координаты, связанные с этим объектом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function coords()
    {
        return $this->hasOne(MapCoordinatesLibrary::class, 'uin','uin');
    }

    /**
     * Получить РП объекта
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(ProjectManagerLibrary::class, 'project_manager_id');
    }

    /**
     * Пользовательские списки, которым принадлежит объект
     * @return BelongsToMany
     */
    // public function objectLists(): BelongsToMany
    // {
    //     return $this->belongsToMany(ObjectList::class, 'object_list_object', 'object_id', 'object_list_id');
    // }
    public function objectLists(): BelongsToMany
    {
        return $this->belongsToMany(
            ObjectList::class,
            'object_list_object',
            'object_id',
            'object_list_id'
        )->withPivot('position');
    }

    /**
     * Ссылки на внешние видео-потоки.
     * @return HasMany
     */
    public function videoLinks(): HasMany
    {
        return $this->hasMany(ObjectVideoLink::class, 'object_id');
    }

    /**
     * Получить Генерального проектировщика объекта
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function generalDesigner(): BelongsTo
    {
        return $this->belongsTo(GeneralDesigner::class, 'general_designer_id');
    }

    static public function getIdByUIN($uin)
    {
        return self::where('uin',$uin)->pluck('id')->first();
    }

    static public function getByUIN($uin)
    {
        return self::where('uin',$uin)->first();
    }

    static public function getIdByMasterCodeDC($master_kod_dc)
    {
        return self::where('master_kod_dc',$master_kod_dc)->pluck('id')->first();
    }

    static public function getByMasterCodeDC($master_kod_dc)
    {
        return self::where('master_kod_dc',$master_kod_dc)->first();
    }

    public static function existsByMasterCodeDC($masterCodeDC) {
        return self::where('master_kod_dc', $masterCodeDC)->exists();
    }

    public function getKsgLink() {
        try {
            if (!$this?->master_kod_dc) {
                return null;
            } else {
                $directory = $this?->master_kod_dc;
                $if_directory_exists = Storage::disk('ksg')->exists($directory);
                if (!$if_directory_exists) { return null; }

                $files = Storage::disk('ksg')->files($directory);

                $filesWithDates = collect($files)->map(function ($file) {
                    $filename = pathinfo($file, PATHINFO_FILENAME);

                    // Ищем дату в формате dd.mm.yyyy
                    preg_match('/(\d{2}\.\d{2}\.\d{4})/', $filename, $matches);

                    if (empty($matches)) return null;

                    try {
                        $date = Carbon::createFromFormat('d.m.Y', $matches[1]);
                    } catch (\Exception $e) {
                        return null;
                    }

                    return [
                        'path' => $file,
                        'date' => $date
                    ];
                })
                ->filter()
                ->sortByDesc('date');

                $latest_file = $filesWithDates->isNotEmpty()
                    ? $filesWithDates->first()['path']
                    : null;

                if (!$latest_file) { return null; }
                $url = asset('ksg/'.$latest_file);

                return $url;
            }
        } catch (\Throwable $th) {

            dd($th);
            return null;
        }
    }

    /**
     * Аксессор для срока ввода.
     * Если не указана дата ввода по директивному графику,
     * то в качестве показателя берется дата ввода по договору,
     * если она тоже не указана - то берётся из прогнозируемой даты
     */
    public function getDateOfIntroduction()
    {
        $date = $this->planned_commissioning_directive_date ?? $this->contract_commissioning_date ?? $this->forecasted_commissioning_date;
        return $date ? Carbon::parse($date)->format('d.m.Y') : null;
    }

    public static function findByIdOrUin(string|int $value): ?self
    {
        // Сначала пробуем как UIN
        $obj = self::where('uin', $value)->first();
        if ($obj) {
            return $obj;
        }

        // Если похоже на число — пробуем как id
        if (ctype_digit((string)$value)) {
            return self::find((int)$value);
        }

        return null;
    }

    public function detachAllRelatedData(): self
    {
        // Удаляем hasMany связи
        // $this->photos()->delete();
        $this->monitorPeople()->delete();
        $this->monitorTechnica()->delete();
        // $this->breakdowns()->delete();
        $this->objectConstructionStages()->delete();
        $this->controlPoints()->delete();
        // $this->cultureManufacture()->delete();
        // $this->videoLinks()->delete();

        // Удаляем belongsToMany связи (очищаем pivot-таблицы)
        // $this->constructionStagesWithPivot()->detach();
        // $this->controlPointsLibraryWithPivot()->detach();
        $this->etapiRealizaciiLibraryWithPivot()->detach();
        // $this->oksStatusWithPivot()->detach();
        // $this->objectLists()->detach();

        // Если coords — hasOne, то удаляем отдельно
        // if ($this->coords) {
        //     $this->coords->delete();
        // }

        // Опционально: сбросить поля модели (но не саму модель)
        // $this->update([
        //     'oks_status_id' => null,
        //     'project_manager_id' => null,
        //     // ... и т.д.
        // ]);

        return $this;
    }

    // public function softDeleteWithDetachAllRelatedData(): self {
    //     $this->delete();

    //     return $this->detachAllRelatedData();
    // }
}
