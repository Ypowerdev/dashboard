<?php

namespace App\DTO;

class SmgActionsDTO
{
    public ?string $uin;
    public ?string $link;
    public ?string $work_stage;
    public ?string $deadline_reason;
    public ?string $corrective_actions;
    public ?string $taken_measures;
    public ?string $plan_date;
    public ?string $fact_date;
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
            $this->link,
            $this->work_stage,
            $this->deadline_reason,
            $this->corrective_actions,
            $this->taken_measures,
            $this->plan_date,
            $this->fact_date,
            $this->master_code_ds
        ];
    }
}
