<?php

namespace App\Services\ExcelServices;

use App\DTO\OivPlanDTO;
use App\Models\User;
use App\Services\ObjectEtapiRealizaciiViewService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class OivPlanDataService
{
    /**
     * Метод формирует массив данных по отобранным объектам для заполнения листа "ОИВ план"
     *
     * @param $objects
     * @param User $user
     * @param array $constructionStages
     * @return array
     */
    public function getPlanData($objects, User $user, array $constructionStages = []): array
    {
        $objectsData = collect($objects)->map(function ($item, $index) use ($user, $constructionStages) {
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
                'directive_schedule_input' => ExcelDataService::formatDate($data['planned_commissioning_directive_date'] ?? ''),
                'meeting_date_directive_change' => ExcelDataService::formatDate($data['planned_commissioning_directive_think_change_date'] ?? ''),
                'contract_input' => ExcelDataService::formatDate($data['contract_commissioning_date'] ?? ''),
                'forecast_input_date' => ExcelDataService::formatDate($data['forecasted_commissioning_date'] ?? ''),
                'gpzu_plan' => ExcelDataService::formatDate($service->getPlanDateByEtapName($item, "ГПЗУ (план)")),
                'tu_rso_plan' => ExcelDataService::formatDate($service->getPlanDateByEtapName($item, "ТУ от РСО (план)")),
                'agr_plan' => ExcelDataService::formatDate($service->getPlanDateByEtapName($item, "АГР (план)")),
                'expertise_psd_plan' => ExcelDataService::formatDate($service->getPlanDateByEtapName($item, "Экспертиза ПСД (план)")),
                'rns_plan' => ExcelDataService::formatDate($service->getPlanDateByEtapName($item, "РНС (план)")),
                'smr_plan' => ExcelDataService::formatDate($service->getPlanDateByEtapName($item, "СМР (план)")),
                'tech_connect_plan' => ExcelDataService::formatDate($service->getPlanDateByEtapName($item, "Техприс (план)")),
                'zos_plan' => ExcelDataService::formatDate($service->getPlanDateByEtapName($item, "ЗОС (план)")),
                'rv_plan' => ExcelDataService::formatDate($service->getPlanDateByEtapName($item, "РВ (план)")),
            ];

            // Создаем DTO
            $dto = OivPlanDTO::fromArray($mappedData);

            // Заполняем данные по строительным этапам
            $objectConstructionStages = $item->objectConstructionStages ?? collect();

            foreach ($constructionStages as $stage) {
                // Ищем данные для этого этапа в таблице object_construction_stages
                $objectStage = $objectConstructionStages->firstWhere('construction_stages_library_id', $stage['id']);

                if ($objectStage) {
                    // Используем метод из сервиса
                    $stageKeyStart = ExcelDataService::generateStagePropertyKey($stage, 'start');
                    $stageKeyEnd = ExcelDataService::generateStagePropertyKey($stage, 'end');

                    $startDate = ExcelDataService::formatDate($objectStage['oiv_plan_start'] ?? '');
                    $endDate = ExcelDataService::formatDate($objectStage['oiv_plan_finish'] ?? '');

                    // Берем данные из таблицы object_construction_stages
                    $dto->setConstructionStageData($stageKeyStart, $startDate);
                    $dto->setConstructionStageData($stageKeyEnd, $endDate);
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
