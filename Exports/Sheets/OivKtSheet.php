<?php

namespace App\Exports\Sheets;

use App\DTO\OivKtDTO;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class OivKtSheet implements FromArray, WithTitle, WithEvents, WithColumnWidths
{
    private array $data;
    private array $controlPoints;

    public function __construct(array $data = [], array $controlPoints = [])
    {
        $this->data = $data;
        $this->controlPoints = $controlPoints;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function setControlPoints(array $controlPoints): void
    {
        $this->controlPoints = $controlPoints;
    }

    /**
     * Генерирует полный массив заголовков для всех 4 строк на основе контрольных точек
     */
    private function generateFullHeaders(): array
    {
        // Группируем контрольные точки по родителям
        $groupedPoints = $this->groupControlPointsByParent();

        // Строка 1: Групповые заголовки
        $row1 = array_fill(0, 10, ''); // Пустые для первых 10 колонок

        // Добавляем групповые заголовки для контрольных точек
        foreach ($groupedPoints as $groupName => $points) {
            $colSpan = count($points) * 5; // 5 колонок на каждый этап
            $row1 = array_merge($row1, array_fill(0, $colSpan, $groupName));
        }

        // Строка 2: Названия контрольных точек
        $row2 = array_fill(0, 10, ''); // Пустые для первых 10 колонок

        foreach ($groupedPoints as $groupName => $points) {
            foreach ($points as $point) {
                // Каждая точка занимает 5 колонок
                $row2 = array_merge($row2, array_fill(0, 5, $point['name']));
            }
        }

        // Строка 3: Подзаголовки (План/Факт)
        $row3 = [
            'user АНО СТРОИНФОРМ', 'дата редактирования строки', 'На какую дату актуализировано состояние ОКС',
            'УИН', 'Мастер код ФР', 'link', 'Мастер код ДС', 'Округ', 'Район', 'Адрес объекта'
        ];

        foreach ($groupedPoints as $groupName => $points) {
            foreach ($points as $point) {
                $row3 = array_merge($row3, [
                    'План Начало', 'Факт Начало', 'План Завершение', 'Факт Завершение', '% прогресса'
                ]);
            }
        }

        // Строка 4: Типы данных
        $row4 = [
            "ФИО", "ДД.ММ.ГГГГ", "ДД.ММ.ГГГГ", "уин1", "text", "text", "text",
            "По справочнику округов Москвы", "По справочнику районов Москвы", "text"
        ];

        foreach ($groupedPoints as $groupName => $points) {
            foreach ($points as $point) {
                $row4 = array_merge($row4, [
                    "ДД.ММ.ГГГГ", "ДД.ММ.ГГГГ", "ДД.ММ.ГГГГ", "ДД.ММ.ГГГГ", "%"
                ]);
            }
        }

        return [$row1, $row2, $row3, $row4];
    }

    /**
     * Группирует контрольные точки по родительским категориям
     */
    private function groupControlPointsByParent(): array
    {
        if (empty($this->controlPoints)) {
            Log::info('No control points provided');
            return [];
        }
        $grouped = [];

        foreach ($this->controlPoints as $index => $point) {
            $groupName = '';
            if (!empty($point['parent']) && !empty($point['parent']['view_name'])) {
                $groupName = $point['parent']['view_name'];
            }

            if (!isset($grouped[$groupName])) {
                $grouped[$groupName] = [];
            }
            $grouped[$groupName][] = $point;
        }

        return $grouped;
    }

    public function array(): array
    {
        $headerRows = $this->generateFullHeaders();

        if (empty($this->data)) {
            $emptyDataRow = array_fill(0, count($headerRows[3]), '');
            return array_merge($headerRows, [$emptyDataRow]);
        }

        $dataRows = [];
        foreach ($this->data as $item) {
            if ($item instanceof OivKtDTO) {
                $dataRows[] = $item->toArray();
            }
        }

        return array_merge($headerRows, $dataRows);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet;
                $lastColumn = $sheet->getHighestColumn();
                $lastRow = $sheet->getHighestRow();

                // Границы для всех ячеек
                $borderStyle = [
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                    'alignment' => [
                        'wrapText' => false,
                    ],
                ];
                $sheet->getStyle('A1:' . $lastColumn . $lastRow)->applyFromArray($borderStyle);

                // --- Стилизация ---

                // Стиль для групповых заголовков (строка 1)
                $groupHeaderStyle = [
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '3f3f3f'],
                        'size' => 10
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'DDEBF7']
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ];

                // Стиль для названий контрольных точек (строка 2)
                $pointHeaderStyle = [
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '3f3f3f'],
                        'size' => 10
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'DDEBF7']
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ];

                // Стиль для основных заголовков (строка 3)
                $mainHeaderStyle = [
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '3f3f3f'],
                        'size' => 10
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'DDEBF7']
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ];

                // 1. Строка 1: Групповые заголовки - объединяем и стилизуем
                $currentCol = 11; // Начинаем с колонки 11 (K)
                $groupedPoints = $this->groupControlPointsByParent();

                foreach ($groupedPoints as $groupName => $points) {
                    $colSpan = count($points) * 5;
                    if ($colSpan > 0 && !empty($groupName)) {
                        $startCol = Coordinate::stringFromColumnIndex($currentCol);
                        $endCol = Coordinate::stringFromColumnIndex($currentCol + $colSpan - 1);

                        $sheet->mergeCells($startCol . '1:' . $endCol . '1');
                        $sheet->getStyle($startCol . '1:' . $endCol . '1')->applyFromArray($groupHeaderStyle);
                        $sheet->setCellValue($startCol . '1', $groupName);
                    }
                    $currentCol += $colSpan;
                }

                // 2. Строка 2: Названия контрольных точек - объединяем по 5 колонок
                $currentCol = 11;
                foreach ($groupedPoints as $groupName => $points) {
                    foreach ($points as $point) {
                        $startCol = Coordinate::stringFromColumnIndex($currentCol);
                        $endCol = Coordinate::stringFromColumnIndex($currentCol + 4);

                        $sheet->mergeCells($startCol . '2:' . $endCol . '2');
                        $sheet->getStyle($startCol . '2:' . $endCol . '2')->applyFromArray($pointHeaderStyle);
                        $sheet->setCellValue($startCol . '2', $point['name']);
                        $currentCol += 5;
                    }
                }

                // 3. Строка 3: Основные заголовки - применяем стиль ко ВСЕМ ячейкам
                $sheet->getStyle('A3:' . $lastColumn . '3')->applyFromArray($mainHeaderStyle);

                // 4. Строка 4: Типы данных - отдельный стиль
                $typeRowStyle = [
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '7F7F7F'],
                        'size' => 8
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F2F2F2']
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ];
                $sheet->getStyle('A4:' . $lastColumn . '4')->applyFromArray($typeRowStyle);

                // Заморозить первые 4 строки и первые 10 столбцов
                $sheet->freezePane('K5');

                // Установить автофильтр на строку с типами данных
                $sheet->setAutoFilter('A4:' . $lastColumn . '4');

                // Установить высоту строк
                $sheet->getRowDimension(1)->setRowHeight(30);
                $sheet->getRowDimension(2)->setRowHeight(30);
                $sheet->getRowDimension(3)->setRowHeight(30);
                $sheet->getRowDimension(4)->setRowHeight(20);

                // Добавить валидацию данных
                $lastColumnIndex = Coordinate::columnIndexFromString($lastColumn);
                for ($colIndex = 1; $colIndex <= $lastColumnIndex; $colIndex++) {
                    $col = Coordinate::stringFromColumnIndex($colIndex);
                    $cellCoordinate = $col . '4';

                    $validation = $sheet->getDataValidation($cellCoordinate);
                    $validation->setType(DataValidation::TYPE_LIST);
                    $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
                    $validation->setAllowBlank(true);
                    $validation->setShowInputMessage(true);
                    $validation->setShowErrorMessage(true);
                    $validation->setShowDropDown(true);
                    $validation->setErrorTitle('Ошибка ввода');
                    $validation->setError('Значение не в списке допустимых');
                    $validation->setPromptTitle('Выберите из списка');
                    $validation->setPrompt('Пожалуйста, выберите значение из выпадающего списка');

                    $values = $this->getValidationValues($col);
                    $validation->setFormula1('"' . implode(',', $values) . '"');
                }
            },
        ];
    }

    public function columnWidths(): array
    {
        $widths = [
            'A' => 25, 'B' => 22, 'C' => 28, 'D' => 12, 'E' => 15,
            'F' => 12, 'G' => 15, 'H' => 18, 'I' => 18, 'J' => 30
        ];

        // Устанавливаем ширину для всех столбцов с контрольными точками
        $currentCol = 11;
        $groupedPoints = $this->groupControlPointsByParent();

        foreach ($groupedPoints as $groupName => $points) {
            foreach ($points as $point) {
                for ($i = 0; $i < 5; $i++) { // 5 колонок на точку
                    $colLetter = Coordinate::stringFromColumnIndex($currentCol);
                    $widths[$colLetter] = ($i === 4) ? 10 : 15; // Проценты - 10, даты - 15
                    $currentCol++;
                }
            }
        }

        return $widths;
    }

    public function title(): string
    {
        return '6 - ОИВ КТ';
    }

    private function getValidationValues(string $column): array
    {
        $colIndex = Coordinate::columnIndexFromString($column);

        if ($colIndex <= 10) {
            return match ($colIndex) {
                1 => ['ФИО'],
                2, 3 => ['ДД.ММ.ГГГГ'],
                4 => ['уин1', 'уин2', 'уин3'],
                5, 6, 7 => ['text'],
                8 => ['По справочнику округов Москвы'],
                9 => ['По справочнику районов Москвы'],
                10 => ['text'],
                default => ['']
            };
        }

        if (($colIndex - 11) % 5 < 4) {
            return ['ДД.ММ.ГГГГ', ''];
        } else {
            return ['%', ''];
        }
    }
}
