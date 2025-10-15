<?php

namespace App\DTO;

class OivEWDTO
{
    // Колонки A-F (Основные данные)
    public ?string $user_ano_stroinform = '';

    public ?string $uin = '';

    public ?string $name = '';
    public ?string $master_code_fr = '';

    public ?string $master_code_ds = '';

    public ?string $address = '';

    // Данные по дням (ключ - дата в формате Y-m-d, значение - массив данных)
    private array $dailyData = [];

    public function __construct(array $data = [])
    {
        // Основные данные
        $this->user_ano_stroinform = $data['user_ano_stroinform'] ?? '';

        $this->uin = $data['uin'] ?? '';
        $this->name = $data['name'] ?? '';
        $this->master_code_fr = $data['master_code_fr'] ?? '';
        $this->master_code_ds = $data['master_code_ds'] ?? '';
        $this->address = $data['address'] ?? '';
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function setDailyData(string $date, array $data): void
    {
        $this->dailyData[$date] = $data;
    }

    public function getDailyData(): array
    {
        return $this->dailyData;
    }

    public function toArray(array $dates = []): array
    {
        $result = [
            // A-F: Основные данные (6 колонок) - В ТОЧНОМ ПОРЯДКЕ КАК В ЗАГОЛОВКАХ
            $this->user_ano_stroinform,
            $this->uin,
            $this->name,
            $this->master_code_fr,
            $this->master_code_ds,
            $this->address,
        ];

        // Добавляем данные по дням
        foreach ($dates as $date) {
            $dateKey = $date->format('Y-m-d');
            $dayData = $this->dailyData[$dateKey] ?? [
                'equipment_plan' => '',
                'equipment_fact' => '',
                'workers_plan' => '',
                'workers_fact' => ''
            ];

            $result[] = $dayData['equipment_plan'] ?? '';
            $result[] = $dayData['equipment_fact'] ?? '';
            $result[] = $dayData['workers_plan'] ?? '';
            $result[] = $dayData['workers_fact'] ?? '';
        }

        return $result;
    }
}
