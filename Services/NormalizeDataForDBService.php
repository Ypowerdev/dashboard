<?php

namespace App\Services;

use App\Services\ExcelServices\ExcelDataService;

class NormalizeDataForDBService
{
    /**
     * Нормализует данные для сохранения в БД
     * - Приводит даты к формату Y-m-d (по маске 00.00.0000)
     * - Преобразует "Да"/"Нет" в boolean
     */
    public function normalizeDataForDatabase(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            $normalized[$key] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    /**
     * Нормализует отдельное значение
     */
    private function normalizeValue($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Обработка булевых значений "Да"/"Нет"
        if ($this->isBooleanValue($value)) {
            return $this->normalizeBoolean($value);
        }

        // Обработка дат в формате 00.00.0000
        if ($this->isDateValue($value)) {
            return $this->normalizeDate($value);
        }

        // Обработка чисел (если нужно)
        if (is_numeric($value)) {
            return $value + 0; // Преобразует строку в int или float
        }

        // Для строковых значений убираем лишние пробелы
        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    /**
     * Проверяет, является ли значение булевым ("Да"/"Нет")
     */
    private function isBooleanValue($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $normalized = mb_strtolower(trim($value));
        return in_array($normalized, ['да', 'нет']);
    }

    /**
     * Проверяет, является ли значение датой в формате 00.00.0000
     */
    private function isDateValue($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        // Проверяем формат 00.00.0000
        return preg_match('/^\d{2}\.\d{2}\.\d{4}$/', trim($value)) === 1;
    }

    /**
     * Нормализует булево значение "Да"/"Нет"
     */
    private function normalizeBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = mb_strtolower(trim($value));
        return $normalized === 'да';
    }

    /**
     * Нормализует дату из формата 00.00.0000 в Y-m-d
     */
    private function normalizeDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $value = trim($value);

        return ExcelDataService::convertDateToDbFormat($value);
    }

}
