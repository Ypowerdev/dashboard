<?php

namespace App\Console\Commands;

use App\Models\ObjectControlPoint;
use App\Models\ObjectEtapiRealizacii;
use App\Models\Library\ControlPointsLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SyncControlPointsToEtapiRealizacii extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:control-points-to-etapi';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Синхронизирует контрольные точки с этапами реализации за последние 24 часа';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Начинаем синхронизацию контрольных точек с этапами реализации...');

        // 1. Сначала создаем недостающие родительские записи для всех объектов
        $this->createMissingParentControlPoints();

        // 2. Получаем названия контрольных точек из маппинга
        $mappedControlPointNames = array_map('mb_strtolower', array_keys(ControlPointsLibrary::MAPPING_TO_ETAPI));

        // Получаем только те контрольные точки, которые:
        // 1. Обновлены за последние 24 часа
        // 2. Имеют название из маппинга
        $objectControlPoints = ObjectControlPoint::with(['controlPointLibrary'])
            ->whereHas('controlPointLibrary', function($query) use ($mappedControlPointNames) {
                $query->where(function($q) use ($mappedControlPointNames) {
                    foreach ($mappedControlPointNames as $name) {
                        $q->orWhereRaw('LOWER(name) LIKE ?', ['%' . $name . '%']);
                    }
                });
            })
//            ->where('updated_at', '>=', now()->subDay())
            ->get();


        $this->info("Найдено контрольных точек для обработки: " . $objectControlPoints->count());

        if ($objectControlPoints->isEmpty()) {
            $this->info('Нет контрольных точек для обработки.');
            return 0;
        }

        $etapiRealizaciiModel = new ObjectEtapiRealizacii();

        foreach ($objectControlPoints as $objectControlPoint) {
            try {
                // Обрабатываем иерархию для каждой КТ
                $etapiRealizaciiModel->syncEtapiRealizaciiFromControlPoint($objectControlPoint);
                $this->info("Обработана контрольная точка ID: {$objectControlPoint->id} - {$objectControlPoint->controlPointLibrary->name}");
            } catch (\Exception $e) {
                Log::error("Ошибка при обработке контрольной точки ID: {$objectControlPoint->id}: " . $e->getMessage());
                $this->error("Ошибка при обработке контрольной точки ID: {$objectControlPoint->id}");
            }
        }

        $this->info("Обработано контрольных точек: " . $objectControlPoints->count());
        $this->info('Синхронизация завершена.');

        return 0;
    }


    /**
     * Создать недостающие родительские контрольные точки для всех объектов
     */
    protected function createMissingParentControlPoints()
    {
        $this->info('Создание недостающих родительских КТ для всех объектов...');

        $createdCount = 0;

        // Получаем все уникальные object_id из существующих КТ
        $objectIds = ObjectControlPoint::distinct()->pluck('object_id');

        $this->info("Найдено объектов для обработки: " . $objectIds->count());

        $mappedControlPointNames = array_map('mb_strtolower', array_keys(ControlPointsLibrary::MAPPING_TO_ETAPI));

        // Получаем все КТ из библиотеки, которые участвуют в маппинге и являются родителями
        $mappedParentControlPoints = ControlPointsLibrary::where(function($q) use ($mappedControlPointNames) {
            foreach ($mappedControlPointNames as $name) {
                $q->orWhereRaw('LOWER(name) LIKE ?', ['%' . $name . '%']);
            }
        })  ->whereHas('children') // Является родителем (есть дочерние КТ)
            ->get();

        $this->info("Родительских КТ в маппинге: " . $mappedParentControlPoints->count());

        foreach ($objectIds as $objectId) {
            foreach ($mappedParentControlPoints as $parentLibrary) {
                // Проверяем, существует ли уже такая запись
                $existingRecord = ObjectControlPoint::where('object_id', $objectId)
                    ->where('stage_id', $parentLibrary->id)
                    ->exists();

                if (!$existingRecord) {
                    // Создаем новую запись
                    $parentControlPoint = new ObjectControlPoint();
                    $parentControlPoint->object_id = $objectId;
                    $parentControlPoint->stage_id = $parentLibrary->id;
                    $parentControlPoint->status = 'ОБЩКОНТРТОЧКА';

                    // Сохраняем в БД
                    $parentControlPoint->save();

                    $createdCount++;
                    $this->info("Создана родительская КТ для object_id: {$objectId}, stage_id: {$parentLibrary->id}");
                }
            }
        }

        $this->info("Создано недостающих КТ: {$createdCount}");
    }
}