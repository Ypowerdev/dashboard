<?php

namespace App\Services\ExcelServices;

use App\DTO\SmgDailyReportDTO;
use App\Models\Library\ConstructionStagesLibrary;
use App\Models\User;


class SmgDailyReportService
{
    /**
     * Метод формирует массив данных по отобранным объектам для листа "ОИВ факт"
     *
     * @param $objects
     * @param User $user
     * @return array
     *
     */
    public function getDailyData($objects, User $user): array
    {
        $objectsData = collect($objects)->map(function ($item) use ($user) {
            $data = $item->toArray();

            // Маппинг базовых полей из объекта в DTO
            $mappedData = [
                // Служебные поля
                'user_ano_stroinform' => $user->name ?? '',
                'edit_date' => ExcelDataService::formatDate($data['updated_date'] ?? ''),
                'actualization_date' => ExcelDataService::formatDate($data['oks_date'] ?? ''),

                // Общие поля
                'uin' => $data['uin'] ?? '',
                'master_code_fr' => '',
                'master_code_ds' => $data['master_kod_dc'] ?? '',
                'link' => $data['link'] ?? '',
                'fno' => $data['fno_type_code'] ?? '',
                'name' => $data['name'] ?? '',
                'commercial_name' => '',
                'short_name' => '',
                'fno_analytics' => $data['fno']['group_name'] ?? '',
                'district' => $data['region']['district_library']['name'] ?? '',
                'area' => $data['region']['name'] ?? '',
                'funding_source' => $data['fin_source']['name'] ?? '',
                'status' => $data['oks_status_library']['name'] ?? '',
                'aip' => ExcelDataService::formatAipFlag($data['aip_flag'] ?? null),
                'aip_inclusion_date' => ExcelDataService::formatDate($data['aip_inclusion_date'] ?? ''),
                'aip_amount' => isset($data['aip_sum']) ? (float)$data['aip_sum'] : 0,
                'address' => $data['address'] ?? '',
                'renovation_sign' => ExcelDataService::formatBooleanFlag($data['renovation'] ?? null),
                'inn_developer' => $data['developer']['inn'] ?? '',
                'developer' => $data['developer']['name'] ?? '',
                'inn_customer' => $data['customer']['inn'] ?? '',
                'customer' => $data['customer']['name'] ?? '',
                'inn_contractor' => $data['contractor']['inn'] ?? '',
                'contractor' => $data['contractor']['name'] ?? '',
                'total_area' => isset($data['area']) ? (float)$data['area'] : 0,
                'length' => isset($data['length']) ? (float)$data['length'] : 0,
                'floors' => (int)($data['floors'] ?? 0),
            ];

            // Заполняем данные по строительным этапам
            $objectConstructionStages = $item->objectConstructionStages ?? collect();

            // Получаем все возможные названия этапов из метода generateStagePropertyKey
            $stageMapping = $this->getStageMapping();

            // Проходим по всем возможным этапам строительства
            foreach ($stageMapping as $stageName => $propertyKey) {
                // Ищем ID этапа по его названию (это нужно для поиска в objectConstructionStages)
                $stage = $objectConstructionStages->firstWhere('stage_name', strtoupper($stageName));

                if ($stage) {
                    if (isset($stage['smg_fact']) && $stage['smg_fact'] !== '') {
                        $factValue = (float)$stage['smg_fact'];
                        $mappedData[$propertyKey] = $factValue;
                    } else {
                        $mappedData[$propertyKey] = null;
                    }
                } else {
                    $mappedData[$propertyKey] = null;
                }
            }

            // Устанавливаем финальные поля
            $mappedData['comment'] = '';

            // Создаем DTO
            $dto = SmgDailyReportDTO::fromArray($mappedData);

            return $dto;

        })->toArray();

        return $objectsData;
    }

    /**
     * Получает маппинг названий этапов к свойствам DTO
     *
     * @return array
     */
    private function getStageMapping(): array
    {
        return [
            'СТРОИТЕЛЬНАЯ ГОТОВНОСТЬ' => 'construction_readiness',
            'КОНСТРУКТИВ' => 'constructive',
            'СТЕНЫ И ПЕРЕГОРОДКИ' => 'walls_partitions',
            'ФАСАД' => 'facade',
            'УТЕПЛИТЕЛЬ' => 'insulation',
            'ФАСАДНАЯ СИСТЕМА' => 'facade_system',
            'ВНУТРЕННЯЯ ОТДЕЛКА' => 'interior_finishing',
            'ЧЕРНОВАЯ ОТДЕЛКА' => 'rough_finishing',
            'ЧИСТОВАЯ ОТДЕЛКА' => 'fine_finishing',
            'ВНУТРЕННИЕ СЕТИ' => 'internal_networks',
            'ВОДОСНАБЖЕНИЕ И ОТОПЛЕНИЕ (ВЕРТИКАЛЬ)' => 'water_supply_heating_vertical',
            'ВОДОСНАБЖЕНИЕ И ОТОПЛЕНИЕ ПОЭТАЖНО (ГОРИЗОНТ)' => 'water_supply_heating_horizontal',
            'ВЕНТИЛЯЦИЯ' => 'ventilation',
            'ЭЛЕКТРОСНАБЖЕНИЕ И СКС' => 'power_supply_scs',
            'НАРУЖНЫЕ СЕТИ' => 'external_networks',
            'БЛАГОУСТРОЙСТВО' => 'landscaping',
            'ТВЕРДОЕ ПОКРЫТИЕ' => 'hard_coating',
            'ОЗЕЛЕНЕНИЕ' => 'greening',
            'МАФ' => 'maf',
            'ОБОРУДОВАНИЕ ПО ТХЗ' => 'equipment_thz',
            'ПОСТАВЛЕНО НА ОБЪЕКТ' => 'equipment_delivered',
            'СМОНТИРОВАНО' => 'equipment_installed',
            'МЕБЕЛЬ' => 'furniture',
            'КЛИНИНГ' => 'cleaning',
            'ДОРОЖНОЕ ПОКРЫТИЕ' => 'road_coating',
            'ИСКУССТВЕННЫЕ СООРУЖЕНИЯ (ИССО)' => 'artificial_structures',
            'НАРУЖНЫЕ ИНЖЕНЕРНЫЕ СЕТИ' => 'external_engineering_networks',
            'СРЕДСТВА ОРГАНИЗАЦИИ ДОРОЖНОГО ДВИЖЕНИЯ' => 'road_organization_means',
            'ДОРОЖНАЯ РАЗМЕТКА' => 'road_marking',
            'БЛАГОУСТРОЙСТВО ПРИЛЕГАЮЩЕЙ ТЕРРИТОРИИ' => 'adjacent_territory_landscaping',
        ];
    }

    /**
     * Генерирует ключ свойства для этапа строительства (оставлен для совместимости)
     *
     * @param string $stageName
     * @return string
     */
    private function generateStagePropertyKey(string $stageName): string
    {
        $stageMapping = $this->getStageMapping();
        return $stageMapping[$stageName] ?? '';
    }
}
