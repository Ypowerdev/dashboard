<?php

namespace App\Services;

use App\Models\Library\ConstructionStagesLibrary;
use App\Models\ObjectModel;

class ObjectReadinessService
{
    /**
     * Обновление показателей готовности для всех объектов.
     */
    public function updateAllObjectsReadiness(): void
    {
        $allObjects = ObjectModel::all();
        foreach ($allObjects as $object) {
            $this->updateReadinessForObject($object);
        }
    }

    /**
     * Обновление показателей готовности для одного объекта.
     */
    public function updateReadinessForObject(ObjectModel $object): void
    {
        $ObjectConstructionStagesViewService = new ObjectConstructionStagesViewService;
        $data = collect($ObjectConstructionStagesViewService->getDataForOIV($object));
        $stroygotovnost = $data
            ->where('name', ConstructionStagesLibrary::NAME_STROYGOTOVNOST)
            ->filter(fn($item) => isset($item['oiv']['fact']) && isset($item['oiv']['plan']))
            ->sortByDesc('created_date')
            ->first();

        if ($stroygotovnost) {
            $object->readiness_percentage_fact = $stroygotovnost['oiv']['fact'] ?? 0;
            $object->readiness_percentage_plan = $stroygotovnost['oiv']['plan'] ?? 0;
            $object->save();
        }
    }
}
