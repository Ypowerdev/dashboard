<?php

namespace App\Services;

use App\Models\Library\ConstructionStagesLibrary;
use App\Models\ObjectControlPoint;
use App\Models\ObjectModel;
use Carbon\Carbon;

class ObjectControlPointViewService
{
    public function __construct(private readonly ObjectControlPoint $objectControlPoint)
    {
    }

    /**
     * Получает исполнителя с учетом подстановки подрядчика
     *
     * @return string|null
     */
    public function getPerformer(): ?string
    {
        $performer = $this->objectControlPoint->controlPointLibrary?->performer;

        if ($performer === 'Генподрядчик' &&
            isset($this->objectControlPoint->object->contractor) &&
            isset($this->objectControlPoint->object->contractor->name)) {
            return $this->objectControlPoint->object->contractor->name;
        }

        return $performer;
    }

    /**
     * Определяет статусы готовности и задержки для контрольной точки
     *
     * @return array
     */
    public function getStatuses(): array
    {
        $planDate = Carbon::parse($this->objectControlPoint->plan_finish_date);
        $factDate = $this->objectControlPoint->fact_finish_date
            ? Carbon::parse($this->objectControlPoint->fact_finish_date)
            : null;

        // Определяем статус готовности
        $readyStatus = $this->determineReadyStatus($planDate, $factDate);

        // Определяем статус задержки
        $delayStatus = $this->determineDelayStatus($readyStatus, $planDate, $factDate);

        return [
            'ready_status' => $readyStatus,
            'delay_status' => $delayStatus,
            'color' => $this->determineColor($readyStatus, $planDate, $factDate),
        ];
    }

    /**
     * Определяет статус готовности контрольной точки
     *
     * @param Carbon $planDate
     * @param Carbon|null $factDate
     * @return string
     */
    private function determineReadyStatus(Carbon $planDate, ?Carbon $factDate): string
    {
        if (!is_null($factDate)) {
            return 'completed';
        }

        if ($planDate->lt(Carbon::today())) {
            return 'overdue';
        }

        return 'pending';
    }

    /**
     * Определяет количество дней задержки
     *
     * @param string $readyStatus
     * @param Carbon $planDate
     * @param Carbon|null $factDate
     * @return int|null
     */
    private function determineDelayStatus(string $readyStatus, Carbon $planDate, ?Carbon $factDate): ?int
    {
        if ($readyStatus === 'completed' && $factDate->gt($planDate)) {
            return $factDate->diffInDays($planDate);
        }

        if ($readyStatus === 'overdue') {
            return Carbon::today()->diffInDays($planDate);
        }

        return null;
    }

    /**
     * Определяет цвет для отображения
     *
     * @param string $readyStatus
     * @param Carbon $planDate
     * @param Carbon|null $factDate
     * @return string
     */
    private function determineColor(string $readyStatus, Carbon $planDate, ?Carbon $factDate): string
    {
        $now = Carbon::now();
        $deadlineDays = config('app.deadline_days', 7) * 86400; // Конфигурируемый дедлайн

        if ($readyStatus === 'complete') {
            if (!$planDate) {
                return 'green';
            }
            return ($factDate->lte($planDate)) ? 'green' : 'red';
        }

        if ($readyStatus === 'overdue') {
            return 'red';
        }

        // Для pending статуса
        if ($planDate) {
            $timeDiff = $planDate->timestamp - $now->timestamp;
            if ($timeDiff <= $deadlineDays && $timeDiff >= 0) {
                return 'yellow';
            } elseif ($timeDiff < 0) {
                return 'red';
            }
        }

        return 'white';
    }

    /**
     * Статический метод для быстрого получения статусов
     *
     * @param ObjectControlPoint $controlPoint
     * @return array
     */
    public static function getStatusesForControlPoint(ObjectControlPoint $controlPoint): array
    {
        $service = new self($controlPoint);
        return $service->getStatuses();
    }


}
