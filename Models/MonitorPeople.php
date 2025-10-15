<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Мониторинг ресурсов на строительной площадке.
 * Таблица для хранения данных мониторинга людей на объектах: monitor_people
 * Записи хранят План/Факт по наличию людей на объекте в тот или иной день.
 */
class MonitorPeople extends Model
{
    // use SoftDeletes;

    protected $table = 'monitor_people';
    protected $fillable = [
        'count_plan',
        'count_fact',
        'object_id',
        'user_id',
        'date'
    ];

    protected $casts = [
        'date'        => 'date',
        'count_plan'  => 'float',
        'count_fact'  => 'float',
    ];

    public $timestamps = false;

    /**
     * Получить объект, которому принадлежит запись
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function object()
    {
        return $this->belongsTo(ObjectModel::class, 'object_id');
    }

    /**
     * Связь с пользователем, который оставил запись
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Обработать данные мониторинга людей и создать/обновить запись с учетом soft deleted записей
     *
     * @param array $item Данные в формате:
     * [
     *     "Код ДС" => "012-0265",
     *     "Дата редактирования" => "23.06.2024",
     *     "Количество людей (факт)" => 12,
     *     "Количество людей (план)" => 34
     * ]
     * @param int|null $userId ID пользователя, который добавляет запись (может быть null)
     * @return array Результат обработки
     */
    public static function addingMonitoringDataFromExonParser(array $item, ?int $userId = null): array
    {
        try {
            // Валидация обязательных полей
            if (empty($item['Код ДС'])) {
                return [
                    'success' => false,
                    'error' => 'Отсутствует обязательное поле: Код ДС',
                    'data' => $item
                ];
            }

            if (empty($item['Дата редактирования'])) {
                return [
                    'success' => false,
                    'error' => 'Отсутствует обязательное поле: Дата редактирования',
                    'data' => $item
                ];
            }

            // Поиск объекта по коду ДС
            $object = ObjectModel::where('master_kod_dc', $item['Код ДС'])->first();

            if (!$object) {
                return [
                    'success' => false,
                    'error' => 'Объект с кодом ДС "' . $item['Код ДС'] . '" не найден',
                    'data' => $item
                ];
            }

            // Парсинг даты
            try {
                $date = Carbon::createFromFormat('d.m.Y', $item['Дата редактирования'])->startOfDay();
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => 'Неверный формат даты: ' . $item['Дата редактирования'] . '. Ожидается формат дд.мм.гггг',
                    'data' => $item
                ];
            }

            // Подготовка значений (с проверкой на наличие и валидацией)
            $countPlan = isset($item['Количество людей (план)']) && is_numeric($item['Количество людей (план)'])
                ? (float)$item['Количество людей (план)']
                : 0;

            $countFact = isset($item['Количество людей (факт)']) && is_numeric($item['Количество людей (факт)'])
                ? (float)$item['Количество людей (факт)']
                : 0;

            // Проверка на существование записи за эту дату (включая soft deleted)
            $existingRecord = self::withTrashed()
                ->where('object_id', $object->id)
                ->where('date', $date->toDateString())
                ->first();

            if ($existingRecord) {
                // Если запись soft deleted - восстанавливаем
                if ($existingRecord->trashed()) {
                    $existingRecord->restore();
                }

                // Обновление существующей записи
                $existingRecord->update([
                    'count_plan' => $countPlan,
                    'count_fact' => $countFact,
                    'user_id' => $userId
                ]);

                $action = 'updated';
                $recordId = $existingRecord->id;
            } else {
                // Создание новой записи
                $record = self::create([
                    'object_id' => $object->id,
                    'date' => $date,
                    'count_plan' => $countPlan,
                    'count_fact' => $countFact,
                    'user_id' => $userId
                ]);

                $action = 'created';
                $recordId = $record->id;
            }

            return [
                'success' => true,
                'record_id' => $recordId,
                'object_id' => $object->id,
                'master_kod_dc' => $item['Код ДС'],
                'object_name' => $object->name,
                'date' => $date->format('d.m.Y'),
                'count_plan' => $countPlan,
                'count_fact' => $countFact,
                'action' => $action,
                'user_id' => $userId
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Ошибка обработки: ' . $e->getMessage(),
                'data' => $item
            ];
        }
    }
    /**
     * Получить данные по дням (последние 7 дней). Пустые значения — нули.
     *
     * @param int $objectId ID объекта
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getDailyData(int $objectId): Collection
    {
        $startDate = Carbon::now()->subDays(6)->startOfDay();
        $endDate   = Carbon::now()->endOfDay();

        // Базовый ряд: каждый день -> нули
        $series = [];
        $cursor = $startDate->copy()->startOfDay();
        while ($cursor->lte($endDate)) {
            $series[$cursor->toDateString()] = ['date' => $cursor->toDateString(), 'count_plan' => 0, 'count_fact' => 0];
            $cursor->addDay();
        }

        // Данные
        $rows = self::where('object_id', $objectId)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'asc')
            ->get();

        foreach ($rows as $item) {
            $key = $item->date->toDateString();
            // Если в день несколько записей — берём последнее (или можно суммировать/усреднять по желанию)
            $series[$key]['count_plan'] = (int) $item->count_plan;
            $series[$key]['count_fact'] = (int) $item->count_fact;
        }

        return collect(array_values($series));
    }

    /**
     * Получить данные по неделям (последние 6 календарных недель)
     *
     * @param int $objectId ID объекта
     * @return \Illuminate\Support\Collection
     */
    public static function getWeeklyData(int $objectId): Collection
    {
        // 1. Выбираем данные за последние 6 календарных недель
        $startDate = Carbon::now()->subWeeks(5)->startOfWeek(Carbon::MONDAY); // понедельник 6 недель назад
        $endDate = Carbon::now()->endOfWeek(Carbon::SUNDAY); // конец текущей недели (воскресенье)

        // Базовый ряд недель (всегда 6 недель, даже если данных нет)
        $series = [];
        $cursor = $startDate->copy();
        while ($cursor->lte($endDate)) {
            $key = $cursor->toDateString(); // понедельник недели
            $series[$key] = [
                'date' => $key,
                'count_plan' => 0,
                'count_fact' => 0,
                'week_number' => $cursor->weekOfYear,
                'year' => $cursor->year
            ];
            $cursor->addWeek();
        }

        // 2. В текущей неделе выбираем данные включительно за текущий день
        $currentWeekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $currentWeekEnd = Carbon::now()->endOfDay(); // только до текущего дня

        // 3. Получаем ВСЕ данные за период
        $rows = self::where('object_id', $objectId)
            ->where(function ($query) use ($startDate, $endDate, $currentWeekStart, $currentWeekEnd) {
                // Для предыдущих недель - полная неделя
                $query->whereBetween('date', [
                    $startDate,
                    $currentWeekStart->copy()->subDay() // до конца предыдущей недели
                ]);

                // Для текущей недели - только до текущего дня
                $query->orWhereBetween('date', [
                    $currentWeekStart,
                    $currentWeekEnd
                ]);
            })
            ->get();

        // Группировка по началу недели (понедельнику)
        $grouped = $rows->groupBy(fn($item) => $item->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString());

        foreach ($grouped as $weekStart => $weekData) {
            if (isset($series[$weekStart])) {
                // Для count_plan - среднее по всем записям
                $series[$weekStart]['count_plan'] = (float) round($weekData->avg('count_plan') ?? 0, 2);

                // Для count_fact - среднее только по записям где fact != null && fact != 0
                $validFactData = $weekData->filter(fn($item) =>
                    $item->count_fact !== null && $item->count_fact != 0
                );

                $series[$weekStart]['count_fact'] = $validFactData->isNotEmpty()
                    ? (float) round($validFactData->avg('count_fact'), 2)
                    : 0;
            }
        }

        // Отсортировано по дате начала недели
        ksort($series);

        // Убедимся, что возвращаем ровно 6 недель
        $result = array_values($series);

        // Если по какой-то причине недель меньше 6, добавим недостающие
        while (count($result) < 6) {
            $missingWeekStart = Carbon::now()->subWeeks(count($result))->startOfWeek(Carbon::MONDAY);
            $result[] = [
                'date' => $missingWeekStart->toDateString(),
                'count_plan' => 0,
                'count_fact' => 0,
                'week_number' => $missingWeekStart->weekOfYear,
                'year' => $missingWeekStart->year
            ];
        }

        // Сортируем по дате (от старых к новым)
        usort($result, fn($a, $b) => strcmp($a['date'], $b['date']));

        return collect($result);
    }

    /**
     * Получить данные по месяцам (последние 3 календарных месяца)
     *
     * @param int $objectId ID объекта
     * @return \Illuminate\Support\Collection
     */
    public static function getMonthlyData(int $objectId): Collection
    {
        // 1. Выбираем данные за последние три календарных месяца
        $startDate = Carbon::now()->subMonths(2)->startOfMonth(); // 1-е число 3 месяца назад
        $endDate = Carbon::now()->endOfMonth(); // конец текущего месяца

        // Базовый ряд месяцев
        $series = [];
        $cursor = $startDate->copy();
        while ($cursor->lte($endDate)) {
            $key = $cursor->toDateString(); // старт месяца (1-е)
            $series[$key] = ['date' => $key, 'count_plan' => 0, 'count_fact' => 0];
            $cursor->addMonth();
        }

        // 2. В текущем месяце выбираем данные включительно за текущий день
        $currentMonthStart = Carbon::now()->startOfMonth();
        $currentMonthEnd = Carbon::now()->endOfDay(); // только до текущего дня

        // 3. Получаем ВСЕ данные за период (без фильтрации по count_fact)
        $rows = self::where('object_id', $objectId)
            ->where(function ($query) use ($startDate, $endDate, $currentMonthStart, $currentMonthEnd) {
                // Для предыдущих месяцев - полный месяц
                $query->whereBetween('date', [
                    $startDate,
                    $currentMonthStart->copy()->subDay() // до конца предыдущего месяца
                ]);

                // Для текущего месяца - только до текущего дня
                $query->orWhereBetween('date', [
                    $currentMonthStart,
                    $currentMonthEnd
                ]);
            })
            ->get(); // Убраны фильтры по count_fact

        // Группировка по началу месяца
        $grouped = $rows->groupBy(fn($item) => $item->date->copy()->startOfMonth()->toDateString());

        foreach ($grouped as $monthStart => $monthData) {
            if (isset($series[$monthStart])) {
                // Для count_plan - среднее по всем записям
                $series[$monthStart]['count_plan'] = (float) round($monthData->avg('count_plan') ?? 0, 2);

                // Для count_fact - среднее только по записям где fact != null && fact != 0
                $validFactData = $monthData->filter(fn($item) =>
                    $item->count_fact !== null && $item->count_fact != 0
                );

                $series[$monthStart]['count_fact'] = $validFactData->isNotEmpty()
                    ? (float) round($validFactData->avg('count_fact'), 2)
                    : 0;
            }
        }

        // Отсортировано по дате старта месяца
        ksort($series);

        return collect(array_values($series));
    }

    /**
     * Получает все записи для указанного объекта за последние 3 месяца
     *
     * @param int $objectId
     * @return array
     */
    public static function getLastThreeMonthsData(int $objectId): array
    {
        // Вычисляем дату 3 месяца назад от текущей даты
        $threeMonthsAgo = Carbon::now()->subMonths(3);
        $today = Carbon::now();

        return self::where('object_id', $objectId)
            ->whereBetween('date', [$threeMonthsAgo->format('Y-m-d'), $today->format('Y-m-d')])
            ->orderBy('date', 'asc')
            ->get()
            ->toArray();
    }


}
