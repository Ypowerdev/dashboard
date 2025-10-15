<?php

namespace App\DTO;

use App\Services\ExcelServices\ExcelDataService;

class OivFactDTO
{
    // Колонки A-C (Служебные) - 3 колонки
    public ?string $user_ano_stroinform = '';
    public ?string $edit_date = '';
    public ?string $actualization_date = '';

    // Колонки D-AY (Общие) - 51 колонка
    public ?string $uin = '';
    public ?string $master_code_fr = '';
    public ?string $master_code_ds = '';
    public ?string $link = '';
    public ?string $fno = '';
    public ?string $name = '';
    public ?string $commercial_name = '';
    public ?string $short_name = '';
    public ?string $fno_analytics = '';
    public ?string $district = '';
    public ?string $area = '';
    public ?string $funding_source = '';
    public ?string $status = '';
    public ?string $aip = '';
    public ?string $aip_inclusion_date = '';
    public ?float $aip_amount = null;
    public ?string $address = '';
    public ?string $renovation_sign = '';
    public ?string $inn_developer = '';
    public ?string $developer = '';
    public ?string $inn_customer = '';
    public ?string $customer = '';
    public ?string $inn_contractor = '';
    public ?string $contractor = '';
    public ?float $total_area = null;
    public ?float $length = null;
    public ?int $floors = null;
    public ?string $contract_date = '';
    public ?float $contract_amount = null;
    public ?float $funded_amount = null;
    public ?float $advance_amount = null;
    public ?float $completed_amount = null;
    public ?string $gpzu_fact = '';
    public ?string $tu_rso_fact = '';
    public ?string $agr_fact = '';
    public ?string $expertise_psd_fact = '';
    public ?string $rns_fact = '';
    public ?string $smr_fact = '';
    public ?string $tech_connect_fact = '';
    public ?string $zos_fact = '';
    public ?string $rv_fact = '';

    // Динамические строительные этапы (все int)
    private array $constructionStagesData = [];

    // Комментарий и код ДГС - 2 колонки
    public ?string $comment = '';
    public ?string $dgs_parent_code = '';

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
     * @param int|null $value
     */
    public function setConstructionStageData(string $propertyName, ?int $value): void
    {
        $this->constructionStagesData[$propertyName] = $value;
    }

    /**
     * Получает данные для строительного этапа
     * @param string $propertyName
     * @return int|null
     */
    public function getConstructionStageData(string $propertyName): ?int
    {
        return $this->constructionStagesData[$propertyName] ?? null;
    }

    public function toArray(array $constructionStages = []): array
    {
        // Базовые данные: 3 служебных + 51 общих = 54 колонки
        $result = [
            // A-C: Служебные (3)
            $this->user_ano_stroinform,
            ExcelDataService::formatDate($this->edit_date),
            ExcelDataService::formatDate($this->actualization_date),

            // D-AY: Общие (51)
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
            ExcelDataService::formatDate($this->contract_date),
            $this->contract_amount,
            $this->funded_amount,
            $this->advance_amount,
            $this->completed_amount,
            ExcelDataService::formatDate($this->gpzu_fact),
            ExcelDataService::formatDate($this->tu_rso_fact),
            ExcelDataService::formatDate($this->agr_fact),
            ExcelDataService::formatDate($this->expertise_psd_fact),
            ExcelDataService::formatDate($this->rns_fact),
            ExcelDataService::formatDate($this->smr_fact),
            ExcelDataService::formatDate($this->tech_connect_fact),
            ExcelDataService::formatDate($this->zos_fact),
            ExcelDataService::formatDate($this->rv_fact),
        ];

        // Добавляем данные по строительным этапам (2 колонки на этап - все int)
        foreach ($constructionStages as $stage) {
            $stageKeyPlan = ExcelDataService::generateStagePropertyKey($stage, 'plan');
            $stageKeyFact = ExcelDataService::generateStagePropertyKey($stage, 'fact');

            $planValue = $this->getConstructionStageData($stageKeyPlan);
            $factValue = $this->getConstructionStageData($stageKeyFact);

            $result[] = $planValue;
            $result[] = $factValue;
        }

        // Финальные колонки (2)
        $result[] = $this->comment;
        $result[] = $this->dgs_parent_code;

        return $result;
    }
}
