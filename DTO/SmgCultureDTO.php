<?php

namespace App\DTO;

class SmgCultureDTO
{
    public ?string $uin;
    public ?string $link;
    public ?string $remark_content;
    public ?string $plan_fix_date;
    public ?string $fact_fix_date;
    public ?string $master_code_ds;
    public ?string $comment;
    public ?string $exon_remark_id;

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
            $this->remark_content,
            $this->plan_fix_date,
            $this->fact_fix_date,
            $this->master_code_ds,
            $this->comment,
            $this->exon_remark_id
        ];
    }
}
