<?php

namespace App\DTO;

use App\Services\ExcelServices\ExcelDataService;
use Illuminate\Support\Facades\Log;

class OivPlanDTO
{
    // Колонки A-C (Служебные) - 3 колонки
    public string $user_ano_stroinform = '';
    public string $edit_date = '';
    public string $actualization_date = '';

    // Колонки D-AQ (Общие - 40 столбцов)
    public string $uin = '';
    public string $master_code_fr = '';
    public string $master_code_ds = '';
    public string $link = '';
    public string $fno = '';
    public string $name = '';
    public string $commercial_name = '';
    public string $short_name = '';
    public string $fno_analytics = '';
    public string $district = '';
    public string $area = '';
    public string $funding_source = '';
    public string $status = '';
    public string $aip = '';
    public string $aip_inclusion_date = '';
    public ?float $aip_amount = null;
    public string $address = '';
    public string $renovation_sign = '';
    public string $inn_developer = '';
    public string $developer = '';
    public string $inn_customer = '';
    public string $customer = '';
    public string $inn_contractor = '';
    public string $contractor = '';
    public ?float $total_area = null;
    public ?float $length = null;
    public ?int $floors = null;
    public string $directive_schedule_input = '';
    public string $meeting_date_directive_change = '';
    public string $contract_input = '';
    public string $forecast_input_date = '';
    public string $gpzu_plan = '';
    public string $tu_rso_plan = '';
    public string $agr_plan = '';
    public string $expertise_psd_plan = '';
    public string $rns_plan = '';
    public string $smr_plan = '';
    public string $tech_connect_plan = '';
    public string $zos_plan = '';
    public string $rv_plan = '';

    // Динамические строительные этапы
    private array $constructionStagesData = [];

    // Финальные колонки - 2 колонки
    public string $comment = '';
    public string $dgs_parent_code = '';

    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            } else {
                // Сохраняем динамические данные строительных этапов
                $this->constructionStagesData[$key] = $value;
            }
        }
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * Устанавливает данные для строительного этапа
     * @param string $propertyName
     * @param string $value
     */
    public function setConstructionStageData(string $propertyName, string $value): void
    {
        $this->constructionStagesData[$propertyName] = $value;
    }

    /**
     * Получает данные для строительного этапа
     * @param string $propertyName
     * @return string
     */
    public function getConstructionStageData(string $propertyName): string
    {
        return $this->constructionStagesData[$propertyName] ?? '';
    }

    public function toArray(array $constructionStages = []): array
    {
        // Базовые данные: 3 служебных + 40 общих = 43 колонки
        $result = [
            // A-C: Служебные (3)
            $this->user_ano_stroinform,
            ExcelDataService::formatDate($this->edit_date),
            ExcelDataService::formatDate($this->actualization_date),

            // D-AQ: Общие (40)
            $this->uin,
            $this->master_code_fr,
            $this->master_code_ds,
            $this->link,
            $this->fno,
            $this->name,
            $this->commercial_name,
            $this->short_name,
            $this->fno_analytics,
            $this->district,
            $this->area,
            $this->funding_source,
            $this->status,
            $this->aip,
            ExcelDataService::formatDate($this->aip_inclusion_date),
            $this->aip_amount,
            $this->address,
            $this->renovation_sign,
            $this->inn_developer,
            $this->developer,
            $this->inn_customer,
            $this->customer,
            $this->inn_contractor,
            $this->contractor,
            $this->total_area,
            $this->length,
            $this->floors,
            ExcelDataService::formatDate($this->directive_schedule_input),
            ExcelDataService::formatDate($this->meeting_date_directive_change),
            ExcelDataService::formatDate($this->contract_input),
            ExcelDataService::formatDate($this->forecast_input_date),
            ExcelDataService::formatDate($this->gpzu_plan),
            ExcelDataService::formatDate($this->tu_rso_plan),
            ExcelDataService::formatDate($this->agr_plan),
            ExcelDataService::formatDate($this->expertise_psd_plan),
            ExcelDataService::formatDate($this->rns_plan),
            ExcelDataService::formatDate($this->smr_plan),
            ExcelDataService::formatDate($this->tech_connect_plan),
            ExcelDataService::formatDate($this->zos_plan),
            ExcelDataService::formatDate($this->rv_plan),
        ];

        // Добавляем данные по строительным этапам (2 колонки на этап)
        $stageDataCount = 0;
        foreach ($constructionStages as $stage) {
            $stageKeyStart = ExcelDataService::generateStagePropertyKey($stage, 'start');
            $stageKeyEnd = ExcelDataService::generateStagePropertyKey($stage, 'end');

            $startValue = $this->getConstructionStageData($stageKeyStart);
            $endValue = $this->getConstructionStageData($stageKeyEnd);

            // Форматируем даты строительных этапов
            $result[] = ExcelDataService::formatDate($startValue);
            $result[] = ExcelDataService::formatDate($endValue);

        }

        // Финальные колонки (2)
        $result[] = $this->comment;
        $result[] = $this->dgs_parent_code;

        return $result;
    }
}
