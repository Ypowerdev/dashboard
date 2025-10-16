<?php

namespace App\Exports\Sheets;

use App\DTO\OivEWDTO;
use App\Models\Contractor;
use App\Models\Customer;
use App\Models\Developer;
use App\Models\FinSource;
use App\Models\Library\DistrictLibrary;
use App\Models\Library\FnoLevelLibrary;
use App\Models\Library\FnoLibrary;
use App\Models\Library\OksStatusLibrary;
use App\Models\Library\RegionLibrary;
use App\Models\User;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class OivEWSheet extends BaseSheet
{
    private array $data;
    private array $dates;

    public function __construct(array $data = [], array $dates = [])
    {
        $this->data = $data;
        $this->dates = $dates;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function setDates(array $dates): void
    {
        $this->dates = $dates;
    }

    /**
     * Группирует даты по месяцам с русскими названиями
     * @return array
     */
    private function groupDatesByMonth(): array
    {
        $grouped = [];
        $russianMonths = [
            'January' => 'Январь',
            'February' => 'Февраль',
            'March' => 'Март',
            'April' => 'Апрель',
            'May' => 'Май',
            'June' => 'Июнь',
            'July' => 'Июль',
            'August' => 'Август',
            'September' => 'Сентябрь',
            'October' => 'Октябрь',
            'November' => 'Ноябрь',
            'December' => 'Декабрь'
        ];

        foreach ($this->dates as $date) {
            $englishMonth = $date->format('F'); // Например: "January"
            $russianMonth = $russianMonths[$englishMonth] ?? $englishMonth;
            $year = $date->format('Y');
            $monthYear = $russianMonth . ' ' . $year; // Например: "Январь 2025"

            if (!isset($grouped[$monthYear])) {
                $grouped[$monthYear] = [];
            }
            $grouped[$monthYear][] = $date;
        }

        return $grouped;
    }

    /**
     * Генерирует полный массив заголовков для всех 3 строк
     */
    private function generateFullHeaders(): array
    {
        // Группируем даты по месяцам
        $groupedDates = $this->groupDatesByMonth();

        // Строка 1: Месяцы (первые 6 ячеек пустые)
        $row1 = array_fill(0, 6, ''); // Пустые для первых 6 колонок

        foreach ($groupedDates as $monthYear => $monthDates) {
            $colSpan = count($monthDates) * 4; // 4 колонки на каждый день
            for ($i = 0; $i < $colSpan; $i++) {
                $row1[] = $monthYear;
            }
        }

        // Строка 2: Дни месяца (первые 6 ячеек пустые)
        $row2 = array_fill(0, 6, ''); // Пустые для первых 6 колонок

        foreach ($groupedDates as $monthYear => $monthDates) {
            foreach ($monthDates as $date) {
                // Каждый день занимает 4 колонки
                for ($i = 0; $i < 4; $i++) {
                    $row2[] = $date->format('d.m.Y');
                }
            }
        }

        // Строка 3: Заголовки столбцов
        $row3 = [
            'user АНО СТРОИНФОРМ',
            'УИН',
            'Наименование',
            'Мастер код ФР',
            'Мастер код ДС',
            'Адрес объекта'
        ];

        // Добавляем заголовки для дат (по 4 колонки на дату)
        foreach ($groupedDates as $monthYear => $monthDates) {
            foreach ($monthDates as $date) {
                $row3 = array_merge($row3, [
                    "Количество техники (план)",
                    "Количество техники (факт)",
                    "Количество рабочих (план)",
                    "Количество рабочих (факт)"
                ]);
            }
        }

        // Строка 4: Типы данных
        $row4 = [
            "ФИО",
            "уин1",
            "text",
            "text",
            "text",
            "text"
        ];

        // Типы для дат (по 4 колонки на дату)
        $dateTypes = [];
        foreach ($this->dates as $date) {
            $dateTypes = array_merge($dateTypes, [
                "int", "int", "int", "int"
            ]);
        }

        $row4 = array_merge($row4, $dateTypes);

        return [$row1, $row2, $row3, $row4];
    }

    public function array(): array
    {
        $headerRows = $this->generateFullHeaders();

        if (empty($this->data)) {
            $columnCount = count($headerRows[2]);
            $emptyDataRow = array_fill(0, $columnCount, '');
            return array_merge($headerRows, [$emptyDataRow]);
        }

        $dataRows = [];
        foreach ($this->data as $item) {
            if ($item instanceof OivEWDTO) {
                $dataRow = $item->toArray($this->dates);
                $dataRows[] = $dataRow;
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
                ];
                $sheet->getStyle('A1:' . $lastColumn . $lastRow)->applyFromArray($borderStyle);

                // --- Стилизация ---

                // 1. Строка 1: Месяцы - объединяем и стилизуем
                $currentCol = 7; // Начинаем с колонки 11 (K)
                $groupedDates = $this->groupDatesByMonth();

                foreach ($groupedDates as $monthYear => $monthDates) {
                    $colSpan = count($monthDates) * 4;
                    if ($colSpan > 0) {
                        $startCol = Coordinate::stringFromColumnIndex($currentCol);
                        $endCol = Coordinate::stringFromColumnIndex($currentCol + $colSpan - 1);

                        $sheet->mergeCells($startCol . '1:' . $endCol . '1');
                        $sheet->getStyle($startCol . '1:' . $endCol . '1')->applyFromArray([
                            'font' => [
                                'bold' => true,
                                'color' => ['rgb' => '3f3f3f']
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
                        ]);
                        $sheet->setCellValue($startCol . '1', $monthYear);
                    }
                    $currentCol += $colSpan;
                }

                // 2. Строка 2: Дни месяца - объединяем по 4 колонки
                $currentCol = 7;
                foreach ($groupedDates as $monthYear => $monthDates) {
                    foreach ($monthDates as $date) {
                        $startCol = Coordinate::stringFromColumnIndex($currentCol);
                        $endCol = Coordinate::stringFromColumnIndex($currentCol + 3);

                        $sheet->mergeCells($startCol . '2:' . $endCol . '2');
                        $sheet->getStyle($startCol . '2:' . $endCol . '2')->applyFromArray([
                            'font' => [
                                'bold' => true,
                                'color' => ['rgb' => '3f3f3f']
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
                        ]);
                        $sheet->setCellValue($startCol . '2', $date->format('d.m.Y'));
                        $currentCol += 4;
                    }
                }

                // 3. Строка 3: Основные заголовки - применяем стиль ко ВСЕМ ячейкам
                $sheet->getStyle('A3:' . $lastColumn . '3')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '3f3f3f']
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
                ]);

                // 4. Стиль для типов данных (строка 4) - серый фон
                $sheet->getStyle('A4:' . $lastColumn . '4')->applyFromArray([
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
                ]);

                // 4. Стиль для всех строк данных
                $sheet->getStyle('A5:' . $lastColumn . $lastRow)->applyFromArray([
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]);

                // Заморозить первые 3 строки и первые 10 столбцов
                $sheet->freezePane('D4');

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
            'A' => 30, 'B' => 20, 'C' => 45, 'D' => 20, 'E' => 20, 'F' => 45,
        ];

        // Устанавливаем ширину для всех столбцов с датами (начиная с колонки 7/G)
        $currentColIndex = 7;
        $groupedDates = $this->groupDatesByMonth();

        foreach ($groupedDates as $monthYear => $monthDates) {
            foreach ($monthDates as $date) {
                for ($i = 0; $i < 4; $i++) {
                    $colLetter = Coordinate::stringFromColumnIndex($currentColIndex);
                    $widths[$colLetter] = 15;
                    $currentColIndex++;
                }
            }
        }

        return $widths;
    }

    public function title(): string
    {
        return '3 - ОИВ ресурсы план факт';
    }

    /**
     * Возвращает допустимые значения для валидации по номеру столбца
     * @param string $column
     * @return array
     * @throws Exception
     */
    private function getValidationValues(string $column): array
    {
        $colIndex = Coordinate::columnIndexFromString($column);

        // Базовые колонки
        if ($colIndex === 1) {
            return ['ФИО'];
        }elseif ($colIndex === 2) {
            return ['уин1', 'уин2', 'уин3'];
        } elseif ($colIndex >= 3 && $colIndex <= 6) {
            return ['text'];
        }

        return ['int', 'text', 'numeric', 'ДД.ММ.ГГГГ', '%'];
    }

    /**
     * Определяет правила для раскрывающихся списков.
     * Эти правила используются в applySelectLists и createHiddenOptionSheets.
     */
    protected function getColumnValidationRules(): array
    {
        $UserNamesOptions = User::all()->pluck('name')->toArray();
        $DistrictsLibraryOptions = DistrictLibrary::all()->pluck('name')->toArray();
        $RegionsLibraryOptions = RegionLibrary::all()->pluck('name')->toArray();
        $FnoLibraryOptions = FnoLibrary::all()
            ->pluck('group_name')
            ->map(fn($item) => trim($item))  //_trim для каждой строки
            ->filter()                       //_убираем пустые значения (если нужно)
            ->unique()                       //_только уникальные
            ->values()                       //_переиндексация массива (не обязательно, но полезно)
            ->toArray();
        $FnoTypeCodesOptions = FnoLevelLibrary::all()->pluck('type_code')->toArray();
        $oksStatusLibraryOptions = OksStatusLibrary::all()->pluck('name')->toArray();
        $FinSourceOptions = FinSource::all()->pluck('name')->toArray();

        $developerINNOptions = Developer::all()->pluck('inn')->toArray();
        $developerOptions = Developer::all()->pluck('name')->toArray();
        $customerINNOptions = Customer::all()->pluck('inn')->toArray();
        $customerOptions = Customer::all()->pluck('name')->toArray();
        $contractorINNOptions = Contractor::all()->pluck('inn')->toArray();
        $contractorOptions = Contractor::all()->pluck('name')->toArray();

        return [
            [
                'column' => 'A',
                'hidden_column' => 'HiddenOptions_E',
                'options' => array_merge($UserNamesOptions, ['Другое...']),
                'allow_blank' => true,
                'other_option' => 'Другое...',
            ],
            [
                'column' => 'M',
                'hidden_column' => 'HiddenOptions_M',
                'options' => array_merge($DistrictsLibraryOptions, ['Другое...']),
                'allow_blank' => true,
                'other_option' => 'Другое...',
            ],
            [
                'column' => 'N',
                'hidden_column' => 'HiddenOptions_N',
                'options' => array_merge($RegionsLibraryOptions, ['Другое...']),
                'allow_blank' => true,
                'other_option' => 'Другое...',
            ],
            [
                'column' => 'L',
                'hidden_column' => 'HiddenOptions_L',
                'options' => array_merge($FnoLibraryOptions, ['Другое...']),
                'allow_blank' => true,
                'other_option' => 'Другое...',
            ],
            [
                'column' => 'H',
                'hidden_column' => 'HiddenOptions_H',
                'options' => array_merge($FnoTypeCodesOptions, ['Другое...']),
                'allow_blank' => true,
                'other_option' => 'Другое...',
            ],

            [
                'column' => 'P',
                'hidden_column' => 'HiddenOptions_P',
                'options' => array_merge($oksStatusLibraryOptions, ['Другое...']),
                'allow_blank' => true,
                'other_option' => 'Другое...',
            ],
            [
                'column' => 'O',
                'hidden_column' => 'HiddenOptions_O',
                'options' => array_merge($FinSourceOptions, ['Другое...']),
                'allow_blank' => true,
                'other_option' => 'Другое...',
            ],

            [
                'column' => 'V',
                'hidden_column' => 'HiddenOptions_V',
                'options' => array_merge($developerINNOptions, ['Другое...']),
                'allow_blank' => true,
                'other_option' => 'Другое...',
            ],
            [
                'column' => 'W',
                'hidden_column' => 'HiddenOptions_W',
                'options' => array_merge($developerOptions, ['Другое...']),
                'allow_blank' => true,
                'other_option' => 'Другое...',
            ],
            [
                'column' => 'X',
                'hidden_column' => 'HiddenOptions_X',
                'options' => array_merge($customerINNOptions, ['Другое...']),
                'allow_blank' => true,
                'other_option' => 'Другое...',
            ],
            [
                'column' => 'Y',
                'hidden_column' => 'HiddenOptions_Y',
                'options' => array_merge($customerOptions, ['Другое...']),
                'allow_blank' => true,
                'other_option' => 'Другое...',
            ],
            [
                'column' => 'Z',
                'hidden_column' => 'HiddenOptions_Z',
                'options' => array_merge($contractorINNOptions, ['Другое...']),
                'allow_blank' => true,
                'other_option' => 'Другое...',
            ],
            [
                'column' => 'AA',
                'hidden_column' => 'HiddenOptions_AA',
                'options' => array_merge($contractorOptions, ['Другое...']),
                'allow_blank' => true,
                'other_option' => 'Другое...',
            ],
        ];
    }

    /**
     * Метод для определения строки заголовков (если отлична от 1).
     * Можно переопределить в дочернем классе.
     *
     * @return int
     */
    protected function getHeaderRowIndex(): int
    {
        return 4;
    }
}
