<?php

namespace App\DTO;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class OivKtDTO
{
    // Колонки A-J (Основные данные)
    public ?string $user_ano_stroinform;
    public ?string $edit_date;
    public ?string $actualization_date;
    public ?string $uin;
    public ?string $master_code_fr;
    public ?string $link;
    public ?string $master_code_ds;
    public ?string $district;
    public ?string $area;
    public ?string $address;

    // Данные по этапам (ключ - название этапа, значение - массив данных)
    private array $stageData = [];
    private array $stages = []; // Добавляем хранение этапов

    public function __construct(array $data = [], ?User $user = null, array $stages = [])
    {
        if ($user) {
            $this->user_ano_stroinform = $user->name ?? '';
        }
        // Основные данные
        $this->edit_date = $data['updated_date'] ?? null;
        $this->actualization_date = $data['oks_date'] ?? null;
        $this->uin = $data['uin'] ?? null;
        $this->master_code_fr = $data['master_code_fr'] ?? null;
        $this->link = $data['link'] ?? null;
        $this->master_code_ds = $data['master_kod_dc'] ?? null;
        $this->district = $data['region']['district_library']['name'] ?? '';
        $this->area = $data['region']['name'] ?? null;
        $this->address = $data['address'] ?? null;

        $this->stages = $stages; // Сохраняем этапы
    }

    public static function fromArray(array $data, User $user, array $stages = []): self
    {
        return new self($data, $user, $stages);
    }

    public function setStageData(string $stageName, array $stageData): void
    {
        $this->stageData[$stageName] = $stageData;
        // Отладка
        if (!empty($stageData['plan_start_date']) || !empty($stageData['fact_start_date']) ||
            !empty($stageData['plan_finish_date']) || !empty($stageData['fact_finish_date']) ||
            !empty($stageData['progress'])) {
            Log::info("Stage '{$stageName}' has data: " . json_encode($stageData));
        }
    }

    public function getAllStageData(): array
    {
        return $this->stageData;
    }

    public function toArray(): array // Убираем параметр, используем внутренние этапы
    {
        $result = [
            // A-J: Основные данные (10 колонок)
            $this->user_ano_stroinform ?? '',
            $this->edit_date ?? '',
            $this->actualization_date ?? '',
            $this->uin ?? '',
            $this->master_code_fr ?? '',
            $this->link ?? '',
            $this->master_code_ds ?? '',
            $this->district ?? '',
            $this->area ?? '',
            $this->address ?? '',
        ];

        // Используем этапы, сохраненные в DTO
        foreach ($this->stages as $stage) {
            $stageData = $this->stageData[$stage] ?? [
                'plan_start' => '',
                'fact_start' => '',
                'plan_end' => '',
                'fact_end' => '',
                'progress' => ''
            ];

            $result[] = $stageData['plan_start'] ?? '';
            $result[] = $stageData['fact_start'] ?? '';
            $result[] = $stageData['plan_end'] ?? '';
            $result[] = $stageData['fact_end'] ?? '';
            $result[] = $stageData['progress'] ?? '';
        }

        return $result;
    }

    public function setStages(array $stages): void
    {
        $this->stages = $stages;
    }

    public function getStages(): array
    {
        return $this->stages;
    }

    public function getStagesCount(): int
    {
        return count($this->stageData);
    }
}
