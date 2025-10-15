<?php

namespace App\Services\ExcelServices;

use App\DTO\OivFactDTO;
use App\Models\User;
use App\Services\ObjectEtapiRealizaciiViewService;

class OivFactDataService
{
    /**
     * Метод формирует массив данных по отобранным объектам для листа "ОИВ факт"
     *
     * @param $objects
     * @param User $user
     * @param array $constructionStages
     * @return array
     *
     */
    public function getFactData($objects, User $user, array $constructionStages = []): array
    {
        $objectsData = collect($objects)->map(function ($item) use ($user, $constructionStages) {
            $data = $item->toArray();
            $service = new ObjectEtapiRealizaciiViewService();

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
                'contract_date' => ExcelDataService::formatDate($data['contract_date'] ?? ''),
                'contract_amount' => isset($data['contract_sum']) ? (float)$data['contract_sum'] : 0,
                'funded_amount' => isset($data['finance_planned']) ? (float)$data['finance_planned'] : 0,
                'advance_amount' => isset($data['finance_advance']) ? (float)$data['finance_advance'] : 0,
                'completed_amount' => isset($data['finance_readiness']) ? (float)$data['finance_readiness'] : 0,
                'gpzu_fact' => ExcelDataService::formatDate($service->getFactDateByEtapName($item, "ГПЗУ (факт)")),
                'tu_rso_fact' => ExcelDataService::formatDate($service->getFactDateByEtapName($item, "ТУ от РСО (факт)")),
                'agr_fact' => ExcelDataService::formatDate($service->getFactDateByEtapName($item, "АГР (факт)")),
                'expertise_psd_fact' => ExcelDataService::formatDate($service->getFactDateByEtapName($item, "Экспертиза ПСД (факт)")),
                'rns_fact' => ExcelDataService::formatDate($service->getFactDateByEtapName($item, "РНС (факт)")),
                'smr_fact' => ExcelDataService::formatDate($service->getFactDateByEtapName($item, "СМР (факт)")),
                'tech_connect_fact' => ExcelDataService::formatDate($service->getFactDateByEtapName($item, "Техприс (факт)")),
                'zos_fact' => ExcelDataService::formatDate($service->getFactDateByEtapName($item, "ЗОС (факт)")),
                'rv_fact' => ExcelDataService::formatDate($service->getFactDateByEtapName($item, "РВ (факт)")),
            ];

            // Создаем DTO
            $dto = OivFactDTO::fromArray($mappedData);

            // Заполняем данные по строительным этапам
            $objectConstructionStages = $item->objectConstructionStages ?? collect();

            foreach ($constructionStages as $stage) {
                // Ищем данные для этого этапа в объекте
                $objectStage = $objectConstructionStages->firstWhere('construction_stages_library_id', $stage['id']);

                if ($objectStage) {
                    $stageKeyPlan = ExcelDataService::generateStagePropertyKey($stage, 'plan');
                    $stageKeyFact = ExcelDataService::generateStagePropertyKey($stage, 'fact');

                    // Берем данные oiv_plan и oiv_fact из таблицы object_construction_stages
                    // и преобразуем в int (проценты)
                    $planValue = !empty($objectStage['oiv_plan']) ? (int)$objectStage['oiv_plan'] : null;
                    $factValue = !empty($objectStage['oiv_fact']) ? (int)$objectStage['oiv_fact'] : null;

                    $dto->setConstructionStageData($stageKeyPlan, $planValue);
                    $dto->setConstructionStageData($stageKeyFact, $factValue);
                }
            }

            // Устанавливаем финальные поля
            $dto->comment = '';
            $dto->dgs_parent_code = '';

            return $dto;
        })->toArray();

        return $objectsData;
    }
}
