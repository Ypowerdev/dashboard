<?php

namespace App\DTO;

class OivResourcesDTO
{
    public ?string $uin;
    public ?string $month;
    public ?int $people_plan;
    public ?int $equipment_plan;
    public ?string $master_code_ds;

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
            $this->uin,
            $this->month,
            $this->people_plan,
            $this->equipment_plan,
            $this->master_code_ds
        ];
    }
}
