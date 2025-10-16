<?php

namespace App\Exports\Sheets;

use App\DTO\SmgCultureDTO;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class SmgCultureSheet implements FromArray, WithTitle, WithColumnWidths, WithEvents
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
        $row1 = ['УИН', 'link', 'Содержание замечания к культуре производства', 'Срок устранения (план)', 'Срок устранения (факт)', 'Мастер код ДС', 'Комментарий', 'ID замечания в EXON'];

        // Строка типов данных
        $row2 = ['уин1', 'text', 'text', 'ДД.ММ.ГГГГ', 'ДД.ММ.ГГГГ', 'text', 'text', ''];

        return [$row1, $row2];
    }

    public function array(): array
    {
        $headerRows = $this->getHeaderRows();

        if (empty($this->data)) {
            $emptyDataRow = array_fill(0, count($headerRows[0]), '');
            return array_merge($headerRows, [$emptyDataRow]);
        }

        $dataRows = [];
        foreach ($this->data as $item) {
            if ($item instanceof SmgCultureDTO) {
                $dataRows[] = $item->toArray();
            } elseif (is_array($item)) {
                $dataRows[] = array_values($item);
            }
        }

        return array_merge($headerRows, $dataRows);
    }

    public function columnWidths(): array
    {
        // Установка ширины всех столбцов в 30
        return [
            'A' => 30, 'B' => 60, 'C' => 60, 'D' => 15,
            'E' => 15, 'F' => 10, 'G' => 60, 'H' => 15
        ];
    }

    public function title(): string
    {
        return '2.2 - СМГ культура произв-ва';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                // Стиль для большинства заголовков (первая строка) - с голубым фоном
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
                        'wrapText' => true, // Включаем перенос текста
                    ],
                ];

                // Применить стиль к первой строке заголовков, кроме ячеек G1 и H1
                $event->sheet->getStyle('A1:F1')->applyFromArray($headerStyle);


                // Для ячеек G1 и H1 применяем стиль без фона
                $headerStyleNoFill = [
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '000000']
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true, // Включаем перенос текста
                    ],
                ];

                $event->sheet->getStyle('G1:H1')->applyFromArray($headerStyleNoFill);

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
                        'wrapText' => true, // Включаем перенос текста
                    ],
                ];

                // Применить стиль ко второй строке
                $event->sheet->getStyle('A2:H2')->applyFromArray($typeRowStyle);

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
                $event->sheet->getStyle('A1:H3')->applyFromArray($borderStyle);

                // Заморозить первую строку
                $event->sheet->freezePane('A2');

                // Установить автофильтр на строку с типами данных (строка 2)
                $event->sheet->setAutoFilter('A2:H2');

                // Добавить выпадающие списки
                $validationValues = [
                    'A' => ['уин1', 'уин2', 'уин3'],
                    'B' => ['text'],
                    'C' => ['text'],
                    'D' => ['ДД.ММ.ГГГГ'],
                    'E' => ['ДД.ММ.ГГГГ'],
                    'F' => ['text'],
                    'G' => ['text'],
                    'H' => ['text']
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
