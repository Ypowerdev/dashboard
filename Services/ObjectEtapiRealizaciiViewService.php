<?php

namespace App\Services;

use App\Models\Library\EtapiRealizaciiLibrary;
use App\Models\ObjectModel;
use Illuminate\Support\Collection;

class ObjectEtapiRealizaciiViewService
{
    /**
     * Получить плановые даты по имени этапа реализации
     *
     * @param ObjectModel $object
     * @param string $etapName
     * @return string|null
     */
    public function getPlanDateByEtapName(ObjectModel $object, string $etapName): ?string
    {
        $etap = $this->findEtapByName($etapName);

        if (!$etap) {
            return '';
        }

        $pivotData = $object->etapiRealizaciiLibraryWithPivot()
            ->where('etap_id', $etap->id)
            ->first();

        return $pivotData->pivot->plan_finish_date ?? '';
    }

    /**
     * Получить фактические даты по имени этапа реализации
     *
     * @param ObjectModel $object
     * @param string $etapName
     * @return string|null
     */
    public function getFactDateByEtapName(ObjectModel $object, string $etapName): ?string
    {
        $etap = $this->findEtapByName($etapName);

        if (!$etap) {
            return '';
        }

        $pivotData = $object->etapiRealizaciiLibraryWithPivot()
            ->where('etap_id', $etap->id)
            ->first();

        return $pivotData->pivot->fact_finish_date ?? '';
    }

    /**
     * Установить плановые даты по имени этапа реализации
     *
     * @param ObjectModel $object
     * @param string $etapName
     * @param string $planDate
     * @return bool
     */
    public function setPlanDateByEtapName(ObjectModel $object, string $etapName, string $planDate): bool
    {
        $etap = $this->findEtapByName($etapName);

        if (!$etap) {
            return false;
        }

        // Обновляем или создаем запись в pivot таблице
        $object->etapiRealizaciiLibraryWithPivot()->syncWithoutDetaching([
            $etap->id => ['plan_finish_date' => $planDate]
        ]);

        return true;
    }

    /**
     * Установить фактические даты по имени этапа реализации
     *
     * @param ObjectModel $object
     * @param string $etapName
     * @param string $planDate
     * @return bool
     */
    public function setFactDateByEtapName(ObjectModel $object, string $etapName, string $factDate): bool
    {
        $etap = $this->findEtapByName($etapName);

        if (!$etap) {
            return false;
        }

        // Обновляем или создаем запись в pivot таблице
        $object->etapiRealizaciiLibraryWithPivot()->syncWithoutDetaching([
            $etap->id => ['fact_finish_date' => $factDate]
        ]);

        return true;
    }

    /**
     * Получить все плановые даты для объекта по списку этапов
     *
     * @param ObjectModel $object
     * @param array $etapNames
     * @return Collection
     */
    public function getAllPlanDates(ObjectModel $object, array $etapNames): Collection
    {
        $result = collect();

        foreach ($etapNames as $etapName) {
            $cleanName = $this->cleanEtapName($etapName);
            $result->put($etapName, $this->getPlanDateByEtapName($object, $cleanName));
        }

        return $result;
    }

    /**
     * Получить все фактические даты для объекта по списку этапов
     *
     * @param ObjectModel $object
     * @param array $etapNames
     * @return Collection
     */
    public function getAllFactDates(ObjectModel $object, array $etapNames): Collection
    {
        $result = collect();

        foreach ($etapNames as $etapName) {
            $cleanName = $this->cleanEtapName($etapName);
            $result->put($etapName, $this->getFactDateByEtapName($object, $cleanName));
        }

        return $result;
    }

    /**
     * Найти этап по имени (с очисткой от скобок и модификаторов)
     *
     * @param string $etapName
     * @return EtapiRealizaciiLibrary|null
     */
    private function findEtapByName(string $etapName): ?EtapiRealizaciiLibrary
    {
        $cleanName = $this->cleanEtapName($etapName);

        return EtapiRealizaciiLibrary::where('name', $cleanName)->first();
    }

    /**
     * Очистить название этапа от модификаторов (план/факт) и скобок
     *
     * @param string $etapName
     * @return string
     */
    private function cleanEtapName(string $etapName): string
    {
        // Удаляем "(план)", "(факт)" и лишние пробелы
        return trim(str_replace(['(план)', '(факт)'], '', $etapName));
    }
}
