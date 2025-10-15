<?php

namespace App\DTO;

class SmgDailyReportDTO
{
    // Колонки A-C (Служебные)
    public ?string $user_ano_stroinform = null;
    public ?string $edit_date = null;
    public ?string $actualization_date = null;

    // Колонки D-AD (Общие - 28 столбцов)
    public ?string $uin = null;
    public ?string $master_code_fr = null;
    public ?string $master_code_ds = null;
    public ?string $link = null;
    public ?string $fno = null;
    public ?string $name = null;
    public ?string $commercial_name = null;
    public ?string $short_name = null;
    public ?string $fno_analytics = null;
    public ?string $district = null;
    public ?string $area = null;
    public ?string $funding_source = null;
    public ?string $status = null;
    public ?string $aip = null;
    public ?string $aip_inclusion_date = null;
    public ?float $aip_amount = null;
    public ?string $address = null;
    public ?string $renovation_sign = null;
    public ?string $inn_developer = null;
    public ?string $developer = null;
    public ?string $inn_customer = null;
    public ?string $customer = null;
    public ?string $inn_contractor = null;
    public ?string $contractor = null;
    public ?float $total_area = null;
    public ?float $length = null;
    public ?int $floors = null;

    // Колонки AE-AW (Реновация, Дом, Школа, Больница - 18 столбцов)
    public ?float $construction_readiness = null;
    public ?float $constructive = null;
    public ?float $walls_partitions = null;
    public ?float $facade = null;
    public ?float $insulation = null;
    public ?float $facade_system = null;
    public ?float $interior_finishing = null;
    public ?float $rough_finishing = null;
    public ?float $fine_finishing = null;
    public ?float $internal_networks = null;
    public ?float $water_supply_heating_vertical = null;
    public ?float $water_supply_heating_horizontal = null;
    public ?float $ventilation = null;
    public ?float $power_supply_scs = null;
    public ?float $external_networks = null;
    public ?float $landscaping = null;
    public ?float $hard_coating = null;
    public ?float $greening = null;
    public ?float $maf = null;

    // Колонки AX-BB (Школа, Больница - 5 столбцов)
    public ?float $equipment_thz = null;
    public ?float $equipment_delivered = null;
    public ?float $equipment_installed = null;
    public ?float $furniture = null;
    public ?float $cleaning = null;

    // Колонки BC-BH (Дорога - 6 столбцов)
    public ?float $road_coating = null;
    public ?float $artificial_structures = null;
    public ?float $external_engineering_networks = null;
    public ?float $road_organization_means = null;
    public ?float $road_marking = null;
    public ?float $adjacent_territory_landscaping = null;

    // Комментарий (BI)
    public ?string $comment = null;

    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function toArray(): array
    {
        return [
            // A-C: Служебные (3 колонки)
            $this->user_ano_stroinform,
            $this->edit_date,
            $this->actualization_date,

            // D-AD: Общие (28 колонок)
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
            $this->aip_inclusion_date,
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

            // AE-AW: Реновация, Дом, Школа, Больница (18 колонок)
            $this->construction_readiness,
            $this->constructive,
            $this->walls_partitions,
            $this->facade,
            $this->insulation,
            $this->facade_system,
            $this->interior_finishing,
            $this->rough_finishing,
            $this->fine_finishing,
            $this->internal_networks,
            $this->water_supply_heating_vertical,
            $this->water_supply_heating_horizontal,
            $this->ventilation,
            $this->power_supply_scs,
            $this->external_networks,
            $this->landscaping,
            $this->hard_coating,
            $this->greening,
            $this->maf,

            // AX-BB: Школа, Больница (5 колонок)
            $this->equipment_thz,
            $this->equipment_delivered,
            $this->equipment_installed,
            $this->furniture,
            $this->cleaning,

            // BC-BH: Дорога (6 колонок)
            $this->road_coating,
            $this->artificial_structures,
            $this->external_engineering_networks,
            $this->road_organization_means,
            $this->road_marking,
            $this->adjacent_territory_landscaping,

            // BI: Комментарий (1 колонка)
            $this->comment
        ];
    }
}
