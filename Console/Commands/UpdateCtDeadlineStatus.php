<?php

namespace App\Console\Commands;

use App\Models\Config;
use App\Models\ObjectModel;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class UpdateCtDeadlineStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-deadline-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Обновляет статус дедлайнов контрольных точек для всех объектов';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Начало обновления статусов дедлайнов контрольных точек...');

        // Получаем все объекты
        $objects = ObjectModel::with('objectControlPoint')->get();
        $currentDate = Carbon::now();

        $updatedCount = 0;

        foreach ($objects as $object) {
            $hasHighRisk = false;
            $hasFailure = false;

            // Сначала проверяем срыв срока
            $hasFailure = $this->hasFailurePoints($object, $currentDate);

            // Затем проверяем высокий риск только если нет срыва срока
            if (!$hasFailure) {
                $hasHighRisk = $this->hasHighRiskPoints($object, $currentDate);
            }

            $object->ct_deadline_failure = $hasFailure;
            $object->ct_deadline_high_risk = $hasHighRisk;
            $object->save();

            $this->info("Объект ID: {$object->id}, UIN: {$object->uin}, ct_deadline_failure: {$object->ct_deadline_failure}, ct_deadline_high_risk: {$object->ct_deadline_high_risk}");
            $updatedCount++;
        }

        $this->info("Обновление завершено. Обновлено объектов: {$updatedCount}");

        $telegramService = new TelegramService();
        $telegramService->sendMessage('Обновление СРЫВОВ СРОКОВ выполнено. Обновлено объектов:'. $updatedCount);

        return Command::SUCCESS;
    }

    /**
     * Проверяет, есть ли у объекта контрольные точки с просроченным дедлайном
     *
     * @param ObjectModel $object Объект модели
     * @param Carbon $currentDate Текущая дата
     * @return bool
     */
    private function hasFailurePoints($object, $currentDate)
    {
        foreach ($object->objectControlPoint as $point) {
            // Проверяем условие: план заполнен, факт не заполнен и текущая дата позже плана
            if (!empty($point->plan_finish_date) && empty($point->fact_finish_date)) {
                $planDate = Carbon::parse($point->plan_finish_date);

                if ($currentDate->gt($planDate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Проверяет, есть ли у объекта контрольные точки с высоким риском срыва
     *
     * @param ObjectModel $object Объект модели
     * @param Carbon $currentDate Текущая дата
     * @return bool
     */
    private function hasHighRiskPoints($object, $currentDate)
    {
        $condition1 = false; // Срок приближается, 1 месяц до плановой даты, работы не начаты
        $condition2 = false; // Срок ранее был нарушен и исполнен более чем через 1 месяц

        // Проверяем Условие 1
        foreach ($object->objectControlPoint as $point) {
            if (!empty($point->plan_finish_date) && empty($point->fact_finish_date) && empty($point->fact_start_date)) {
                $planDate = Carbon::parse($point->plan_finish_date);

                // Текущая дата меньше плановой, но разница ≤ 1 месяц
                if ($currentDate->lt($planDate) && $currentDate->diffInMonths($planDate) <= 1) {
                    $condition1 = true;
                    break;
                }
            }
        }

        // Проверяем Условие 2 только если Условие 1 выполнено
        if ($condition1) {
            foreach ($object->objectControlPoint as $point) {
                if (!empty($point->plan_finish_date) && !empty($point->fact_finish_date)) {
                    $planDate = Carbon::parse($point->plan_finish_date);
                    $factDate = Carbon::parse($point->fact_finish_date);

                    if ($factDate->gt($planDate->copy()->addMonth())) {
                        $condition2 = true;
                        break;
                    }
                }
            }
        }

        // Высокий риск только если оба условия выполнены
        return $condition1 && $condition2;
    }
}