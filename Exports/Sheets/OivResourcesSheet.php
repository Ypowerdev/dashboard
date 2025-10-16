<?php

namespace App\Exports\Sheets;

use App\DTO\OivResourcesDTO;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class OivResourcesSheet implements FromArray, WithTitle, WithColumnWidths, WithEvents
{
    private array $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    /**
     * Возвращает массив строк с заголовками
     * @return array
     */
    private function getHeaderRows(): array
    {
        // Строка заголовков
        $row1 = ['УИН', 'Месяц плана (ресурсы)', 'Количество людей (план)', 'Количество техники (план)', 'Мастер код ДС'];

        // Строка типов данных
        $row2 = ['уин1', 'text', 'int', 'int', 'text'];

        return [$row1, $row2];
    }

    /**
     * Метод формирует массив заголовков и строк с данными для заполнения excel листа
     *
     * @return array
     */
    public function array(): array
    {
        $headerRows = $this->getHeaderRows();

        if (empty($this->data)) {
            $emptyDataRow = array_fill(0, count($headerRows[0]), '');
            return array_merge($headerRows, [$emptyDataRow]);
        }

        $dataRows = [];
        foreach ($this->data as $item) {
            if ($item instanceof OivResourcesDTO) {
                $dataRows[] = $item->toArray();
            } elseif (is_array($item)) {
                $dataRows[] = array_values($item);
            }
        }

        return array_merge($headerRows, $dataRows);
    }

    /**
     * Метод устанавливает ширины колонок
     * @return array
     */
    public function columnWidths(): array
    {
        // Установка ширины всех столбцов в 30
        return [
            'A' => 30, 'B' => 30, 'C' => 15, 'D' => 15, 'E' => 20
        ];
    }

    /**
     * Метод возвращает название листа Excel
     * @return string
     */
    public function title(): string
    {
        return '3 - ОИВ ресурсы план (мес.)';
    }

    /**
     * Метод добавляет стили к сформированному листу Excel
     * @return mixed
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                // Стиль для заголовков (первая строка)
                $headerStyle = [
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '000000']
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'ddebf7']
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ];

                // Применить стиль к первой строке заголовков
                $event->sheet->getStyle('A1:E1')->applyFromArray($headerStyle);

                // Стиль для второй строки (типы данных) - закраска цветом e7e6e6
                $typeRowStyle = [
                    'font' => [
                        'bold' => true,
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'e7e6e6']
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ];

                // Применить стиль ко второй строке
                $event->sheet->getStyle('A2:E2')->applyFromArray($typeRowStyle);

                // Границы для всех ячеек
                $borderStyle = [
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                    'alignment' => [
                        'wrapText' => true,
                    ],
                ];

                // Применить границы ко всем ячейкам
                $event->sheet->getStyle('A1:E3')->applyFromArray($borderStyle);

                // Заморозить первую строку
                $event->sheet->freezePane('A2');

                // Установить автофильтр на строку с типами данных (строка 2)
                $event->sheet->setAutoFilter('A2:E2');

                // Добавить выпадающие списки
                $validationValues = [
                    'A' => ['уин1', 'уин2', 'уин3'],
                    'B' => ['text'],
                    'C' => ['int'],
                    'D' => ['int'],
                    'E' => ['text']
                ];

                $row = 2;
                foreach ($validationValues as $col => $values) {
                    $validation = $event->sheet->getDataValidation($col . $row);
                    $validation->setType(DataValidation::TYPE_LIST);
                    $validation->setErrorStyle(DataValidation::STYLE_STOP);
                    $validation->setAllowBlank(true);
                    $validation->setShowDropDown(true);
                    $validation->setFormula1('"' . implode(',', $values) . '"');
                }
            },
        ];
    }
}
