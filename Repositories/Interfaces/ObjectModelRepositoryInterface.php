<?php

namespace App\Repositories\Interfaces;

use App\Http\Requests\CatalogRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Интерфейс репозитория для работы с моделью объектов
 */
interface ObjectModelRepositoryInterface
{
    /**
     * Получение отфильтрованного набора данных объектов
     *
     * @param Request $request Входящий HTTP-запрос с текущими фильтрами
     * @param array $filterOptionsData Массив параметров фильтрации, содержащий:
     *   - period_aip: массив дат для фильтрации по дате включения в АИП
     *   - aip_flag: флаг АИП
     *   - oivs: массив ID органов исполнительной власти
     *   - customer_ids: массив ID заказчиков
     *   - lvl1_id: ID уровня 1 ФНО
     *   - lvl2_id: ID уровня 2 ФНО
     * @return \Illuminate\Database\Query\Builder|Exception Построитель запросов или исключение
     */
    public function getFilteredDataHomePage(Request $request,);

    /**
     * Получение изменений статусов ОКС за указанный период
     *
     * @param array $oksStatuses Массив ID статусов ОКС для фильтрации
     * @param \Illuminate\Database\Query\Builder $query Базовый запрос для дальнейшей фильтрации
     * @param int $daysBefore Количество дней для выборки (по умолчанию 7)
     * @return \Illuminate\Database\Query\Builder|Exception Построитель запросов или исключение
     */
    public function getDelta($oksStatuses, $query, $daysBefore);

    /**
     * Get catalog data based on request parameters
     *
     * @param array $validatedData Request object containing filter parameters
     * @return array Array of filtered objects data
     */
    public function getCatalogData(array $validatedData): Collection;

    /**
     * Метод для получения количества объектов для каждого значения фильтра.
     *
     * Возвращает JSON-ответ с количеством объектов для каждого возможного значения фильтров:
     * - Типы объектов (Жилые, Нежилые, Дороги, Метро)
     * - Стадии (В строительстве, Введен, Приостановлено, Иное)
     * - Типы рисков (Есть риски, Нет рисков)
     * - Типы нарушений (Есть нарушения, Нет нарушения)
     * - Статусы сроков (Низкий риск, Высокий риск, Срыв срока)
     *
     * @param array $validatedData Входящий HTTP-запрос с текущими фильтрами
     * @return JsonResponse
     */
    public function getFilterCountsForCatalog(array $validatedData): JsonResponse;

    public function applyOtherFilters(
        $query = null,
        $currentOksStatusArrayName = null,
        $currentViolationType = null,
        $currentDeadlineStatus = null,
        $currentRiskType = null,
        $currentObjectType = null,

        $aip_flag = null,
        $aip_years = null,
        $objectStatus = null,
        $oiv_id = null,
        $any_company_id = null,
        $contractor_id = null,
        $lvl4_ids = null,
        $lvl3_id = null,
        $lvl2_id = null,
        $lvl1_id = null,
        $fno_engineering = null,
        $searchTEXT = null,
        $planned_commissioning_directive_date_years = null,
        $contractSizes = null,
        $renovation = null,
        $culture_manufacture = null,
        $is_object_directive = null,
        $ct_deadline_failure = null,
        $ct_deadline_high_risk = null,
        $commissioning_years = null,
    ): void;

    /**
     * Вспомогательный метод для применения фильтра по флагу АИП
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param number $oksStatusArrayName
     * @return void
     */
    public function applyAipFlagFilter($query, $aip_flag): void;

    /**
     * Вспомогательный метод для применения фильтра по флагу FNO_ENGINEERING
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param boolean $fno_engineering
     * @return void
     */
    public function applyFnoEngineeringFilter($query, bool $fno_engineering): void;

    /**
     * Вспомогательный метод для применения фильтра по флагу АИП
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param number $oksStatusArrayName
     * @return void
     */
    public function applyAipYearsFilter($query, $aip_years): void;

    /**
     * Вспомогательный метод для применения фильтра по статусу объекта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param number $oksStatusArrayName
     * @return void
     */
    public function applyObjectStatusFilter($query, $objectStatus): void;

    /**
     * Вспомогательный метод для применения фильтра по стадии объекта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $oksStatusArrayName
     * @return void
     */
    public function applyoksStatusArrayNameFilter($query, $oksStatusArrayName): void;

    /**
     * Вспомогательный метод для применения фильтра по нарушениям
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $violationType
     * @return void
     */
    public function applyViolationTypeFilter($query, $violationType): void;

    /**
     * Вспомогательный метод для применения фильтра по ОИВам
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $violationType
     * @return void
     */
    public function applyOIVFilter($query, $oiv_id): void;

    /**
     * Вспомогательный метод для применения фильтра по подведам
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $anyCompanyInn
     * @return void
     */
    public function applyAnyCompanyInnFilter($query, $anyCompanyInn): void;

    /**
     * Вспомогательный метод для применения фильтра по подведам
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $violationType
     * @return void
     */
    public function applySearchTEXTFilter($query, $searchTEXT): void;

    /**
     * Вспомогательный метод для применения фильтра по подрядчику
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $contractor_id
     * @return void
     */
    public function applyContractorIdFilter($query, $contractor_id): void;

    /**
     * Вспомогательный метод для применения фильтра по типам объекта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $violationType
     * @return void
     */
    public function applyObjectTypeFilter($query, $lvl4_ids, $lvl3_id, $lvl2_id, $lvl1_id): void;

    /**
     * Вспомогательный метод для применения фильтра по нескольким годам
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $contractor_id
     * @return void
     */
    public function applyPlannedCommissioningDirectiveDateYearsFilter($query, $planned_commissioning_directive_date_years): void;

    /**
     * Вспомогательный метод для применения фильтра по размерам контракта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $contractSizes
     * @return void
     */
    public function applyContractSizesFilter($query, $contractSizes): void;

    /**
     * Вспомогательный метод для применения фильтра по принадлежности объекта к программе реновации
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param boolean $renovation
     * @return void
     */
    public function applyRenovationFilter($query, $renovation): void;

    /**
     * Вспомогательный метод для применения фильтра по наличию проставленной даты планового ввода по директивному графику у объекта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param boolean $is_object_directive
     * @return void
     */
    public function applyIsObjectDirective($query, $is_object_directive): void;

    /**
     * Вспомогательный метод для применения фильтра по наличию срыва сроков у объекта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param boolean $ct_deadline_failure
     * @return void
     */
    public function applyHasDeadlineFailure($query, $ct_deadline_failure): void;

    /**
     * Вспомогательный метод для применения фильтра по наличию высокого риска у объекта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param boolean $ct_deadline_high_risk
     * @return void
     */
    public function applyHasDeadlineHighRisk($query, $ct_deadline_high_risk): void;

    /**
     * Вспомогательный метод для применения фильтра по наличию высокого риска у объекта
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $commissioning_years
     * @return void
     */
    public function applyCommissioningYears($query, $commissioning_years, $is_object_directive): void;


    /**
     * Применить фильтр по факты срыва сроков
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Базовый запрос
     * @return \Illuminate\Database\Eloquent\Builder Модифицированный запрос
     */
    public function applyDeadlineFailureFilter($query);

    /**
     * Применить фильтр по высокому риску
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Базовый запрос
     * @return \Illuminate\Database\Eloquent\Builder Модифицированный запрос
     */
    public function applyDeadlineHighRiskFilter($query);

    /**
     * Применить фильтр по низкому риску
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Базовый запрос
     * @param string|null $status Значение статуса для фильтрации
     * @return \Illuminate\Database\Eloquent\Builder Модифицированный запрос
     */
    public function applyDeadlineOnlyLowRiskFilter($query);

}
