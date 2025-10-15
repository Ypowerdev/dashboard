<?php

namespace App\Services\ExcelServices;

use App\DTO\OivEWDTO;
use App\Models\MonitorPeople;
use App\Models\MonitorTechnica;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class OivEWDataService
{
    public function getEWData($objects, User $user): array
    {
        $resultData = [];

        // Генерируем даты на 3 месяца НАЗАД от текущей даты
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subMonths(3);
        $dates = [];

        for ($date = clone $startDate; $date->lte($endDate); $date->addDay()) {
            $dates[] = clone $date;
        }

        foreach ($objects as $object) {
            $objectData = $object->toArray();

            // Создаем DTO с основными данными
            $dto = OivEWDTO::fromArray([
                'user_ano_stroinform' => $user->name ?? '',
                'uin' => $objectData['uin'] ?? '',
                'name' => $objectData['name'] ?? '',
                'master_code_fr' => '',
                'master_code_ds' => $objectData['master_kod_dc'] ?? '',
                'address' => $objectData['address'] ?? '',
            ]);

            // Получаем данные о технике и рабочих по дням
            $dailyData = $this->getDailyEquipmentAndWorkers($object->id, $dates);

            // ИЗМЕНИТЕ НАЗВАНИЕ ПЕРЕМЕННОЙ В ЦИКЛЕ!
            foreach ($dailyData as $date => $dailyItem) {
                $dto->setDailyData($date, $dailyItem);
            }

            $resultData[] = $dto;

        }

        return [$resultData, $dates];
    }

    /**
     * Получает данные о технике и рабочих по дням из реальных сервисов
     */
    private function getDailyEquipmentAndWorkers(int $objectId, array $dates): array
    {
        $dailyData = [];

        // Получаем данные о рабочих
        $peopleData = MonitorPeople::getLastThreeMonthsData($objectId);

        // Получаем данные о технике
        $technicaData = MonitorTechnica::getLastThreeMonthsData($objectId);

        // Преобразуем данные в удобный формат
        $peopleByDate = [];
        $technicaByDate = [];

        // Обрабатываем данные о рабочих
        foreach ($peopleData as $person) {
            if (isset($person['date'])) {
                $dateKey = Carbon::parse($person['date'])->format('Y-m-d');
                $peopleByDate[$dateKey] = [
                    'plan' => $person['count_plan'] ?? 0,
                    'fact' => $person['count_fact'] ?? 0
                ];
            }
        }

        // Обрабатываем данные о технике
        foreach ($technicaData as $technic) {
            if (isset($technic['date'])) {
                $dateKey = Carbon::parse($technic['date'])->format('Y-m-d');
                $technicaByDate[$dateKey] = [
                    'plan' => $technic['count_plan'] ?? 0,
                    'fact' => $technic['count_fact'] ?? 0
                ];
            }
        }

        // Заполняем данные для всех дат
        foreach ($dates as $date) {
            $dateKey = $date->format('Y-m-d');

            $dailyData[$dateKey] = [
                'equipment_plan' => $technicaByDate[$dateKey]['plan'] ?? '',
                'equipment_fact' => $technicaByDate[$dateKey]['fact'] ?? '',
                'workers_plan' => $peopleByDate[$dateKey]['plan'] ?? '',
                'workers_fact' => $peopleByDate[$dateKey]['fact'] ?? ''
            ];
        }

        return $dailyData;
    }
}
