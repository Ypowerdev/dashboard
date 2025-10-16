<?php

namespace App\Console\Commands;

use App\Models\Library\ConstructionStagesLibrary;
use App\Models\ObjectConstructionStage;
use App\Models\ObjectModel;
use App\Services\ObjectConstructionStagesViewService;
use App\Services\ObjectReadinessService;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class EveryNightScript extends Command
{
    /**
     * Название и сигнатура консольной команды.
     *
     * @var string
     */
    protected $signature = 'app:every-night-script';

    /**
     * Описание консольной команды.
     *
     * @var string
     */
    protected $description = 'Скрипт для выполнения ночных задач, включая обновление deadline и readiness объектов';

    public function __construct(
        private readonly ObjectConstructionStagesViewService $objectDataService,
        private readonly ObjectReadinessService $objectReadinessService
    )
    {
        parent::__construct();
    }

    /**
     * Выполнить консольную команду.
     */
    public function handle()
    {

        $telegramService = new TelegramService();
        $telegramService->sendMessage('Запуск выполнения ночного скрипта...');

        $this->info('Запуск выполнения ночного скрипта...');
        // ОБновляем информацию о РИСКАХ
        $this->hasRisk();

        $this->warn("Обновляем: Низкий риск / Высокий риск / Срыв срока");
        Artisan::call('app:update-deadline-status');

        // Обновление и readiness объектов
        // Перенёс потому что проценты в ConstructionStages по строительной готовности обновляются выше
        $this->objectReadinessService->updateAllObjectsReadiness();

        $this->smg_plan_from_oiv_plan();

        $this->ano_smg_updated_date();

        $telegramService->sendMessage('Запуск формирования Этапов Реализации из Контрольных точек');
        Artisan::call('sync:control-points-to-etapi');

        $telegramService->sendMessage('Запуск скрипта обновления данных по объектам из УГД');
        Artisan::call('ugd:fetch-all --force');

//        $this->warn("Получение ai_fact объектов от ИИ-сервиса");
//        Artisan::call('ai:fetch-stages');

        $this->info('Выполнение ночного скрипта завершено.');
        $telegramService->sendMessage('Выполнение ночного скрипта завершено.');
        return Command::SUCCESS;

    }

    /** реализовать расчет Есть Риск / Нет РИска и Метка ВЫСОКИЙ РИСК - это всё одно
    *  risk_flag для объекта
    * 1.2 Метка «Высокий риск» присваивается в случае, если факт по строительной готовности (или одному из входящих в нее этапов работ) отстает от плана
    **/
    private function hasRisk()
    {
        // 1. Снимаем все флаги risks_flag у всех объектов
        DB::table('objects')->update(['risks_flag' => false]);

        // 2. Получаем object_id, которые соответствуют условиям
        $objectIds = ObjectConstructionStage::whereColumn('oiv_fact', '<', 'oiv_plan')
            ->orWhereColumn('smg_fact', '<', 'smg_plan')
            ->distinct()
            ->pluck('object_id')
            ->toArray();

        // 3. Устанавливаем risks_flag = true для найденных объектов
        if (!empty($objectIds)) {
            DB::table('objects')
                ->whereIn('id', $objectIds)
                ->update(['risks_flag' => true]);
        }

        $this->info('Выполнено  проставление HAS RISK FLAG');

    }


    /**
     * Обновляет ano_smg_updated_date для всех объектов на основе самой свежей записи
     * о строительной готовности (СТРОИТЕЛЬНАЯ ГОТОВНОСТЬ) в ObjectConstructionStage
     */
    private function ano_smg_updated_date()
    {
        // Получаем ID этапа "СТРОИТЕЛЬНАЯ ГОТОВНОСТЬ"
        $libraryID = ConstructionStagesLibrary::where('name', ConstructionStagesLibrary::NAME_STROYGOTOVNOST)->pluck('id')->first();

        if (!$libraryID) {
            $this->error('Не найден этап "СТРОИТЕЛЬНАЯ ГОТОВНОСТЬ" в справочнике ConstructionStagesLibrary');
            return;
        }

        // Получаем все объекты, у которых есть записи о строительной готовности
        $objects = ObjectModel::whereHas('objectConstructionStages', function($query) use ($libraryID) {
            $query->where('construction_stages_library_id', $libraryID);
        })->get();

        foreach ($objects as $object) {
            // Находим самую свежую запись о строительной готовности для текущего объекта
            $latestStage = $object->objectConstructionStages()
                ->where('construction_stages_library_id', $libraryID)
                ->whereNotNull('smg_fact')
                ->orderByDesc('created_date')
                ->first();

            if ($latestStage) {
                // Обновляем дату в основном объекте
                $object->ano_smg_updated_date = $latestStage->created_date;
                $object->save();

                $this->info("Обновлена дата ano_smg_updated_date для объекта {$object->id} на {$latestStage->created_date}");
            }
        }

        $this->info("Обновление дат ano_smg_updated_date завершено. Обработано объектов: " . $objects->count());
    }

    private function smg_plan_from_oiv_plan()
    {
        $this->info("Начало обновления данных: заполнение oiv_plan_start и oiv_plan_finish");

        // Обновление oiv_plan_start и oiv_plan_finish
        $updatedDatesCount = DB::update('
        WITH source_data AS (
            SELECT DISTINCT ON (object_id, construction_stages_library_id)
                object_id,
                construction_stages_library_id,
                oiv_plan_start,
                oiv_plan_finish
            FROM public.object_construction_stages
            WHERE
                oiv_plan_start IS NOT NULL AND
                oiv_plan_finish IS NOT NULL
            ORDER BY object_id, construction_stages_library_id, id DESC
        )
        UPDATE public.object_construction_stages AS target
        SET
            oiv_plan_start = source.oiv_plan_start,
            oiv_plan_finish = source.oiv_plan_finish
        FROM source_data AS source
        WHERE
            target.object_id = source.object_id AND
            target.construction_stages_library_id = source.construction_stages_library_id AND
            target.oiv_plan_start IS NULL AND
            target.oiv_plan_finish IS NULL
    ');

        $this->info(sprintf("Обновлено записей (даты): %d", $updatedDatesCount));

        // Обновление oiv_plan
        $this->info("Расчет процента выполнения (oiv_plan) на основе дат");

        $updatedOivPlanCount = DB::update('
        UPDATE public.object_construction_stages
        SET oiv_plan = ROUND(
            LEAST(100, GREATEST(0,
                100.0 *
                (created_date::date - oiv_plan_start::date) /
                (oiv_plan_finish::date - oiv_plan_start::date)
            ))
        )
        WHERE oiv_plan IS NULL
          AND oiv_plan_start IS NOT NULL
          AND oiv_plan_finish IS NOT NULL
          AND created_date IS NOT NULL
          AND oiv_plan_finish > oiv_plan_start
    ');

        $this->info(sprintf("Рассчитано процентов выполнения (oiv_plan): %d", $updatedOivPlanCount));

        // Обновление smg_plan
        $this->info("Копирование значений из oiv_plan в smg_plan");

        $updatedSmgPlanCount = ObjectConstructionStage::query()
            ->whereNotNull('oiv_plan')
            ->whereNotNull('smg_fact')
            ->whereNull('smg_plan')
            ->update(['smg_plan' => DB::raw('oiv_plan')]);

        $this->info(sprintf("Обновлено записей (smg_plan): %d", $updatedSmgPlanCount));

        $this->info("Обновление данных завершено");

        // Вывод сводной таблицы
        $this->info("Статистика обновления:");
        $this->table(
            ['Этап', 'Кол-во записей'],
            [
                ['Обновление дат', $updatedDatesCount],
                ['Расчет oiv_plan', $updatedOivPlanCount],
                ['Копирование в smg_plan', $updatedSmgPlanCount],
                ['Всего', $updatedDatesCount + $updatedOivPlanCount + $updatedSmgPlanCount]
            ]
        );
    }
}
