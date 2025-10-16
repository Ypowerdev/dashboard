<?php

namespace App\Exports\Sheets;

use App\DTO\SmgActionsDTO;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class SmgActionsSheet implements FromArray, WithTitle, WithColumnWidths, WithEvents
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
        $row1 = ['УИН', 'link', 'Этап работ', 'Причина срыва сроков', 'Мероприятия по устранению', 'Предпринятые меры', 'Срок (план)', 'Срок (факт)', 'Мастер код ДС'];

        // Строка типов данных
        $row2 = ['уин1', 'text', 'Получение ГПЗУ, Получение ТУ от РСО и т.п.', 'text', 'text', 'text', 'ДД.ММ.ГГГГ', 'ДД.ММ.ГГГГ', 'text'];

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
            if ($item instanceof SmgActionsDTO) {
                $dataRows[] = $item->toArray();
            } elseif (is_array($item)) {
                $dataRows[] = array_values($item);
            }
        }

        return array_merge($headerRows, $dataRows);
    }

    public function columnWidths(): array
    {
        // Устанавливаем ширину всех столбцов в 30
        return [
            'A' => 30, 'B' => 30, 'C' => 30, 'D' => 30,
            'E' => 30, 'F' => 30, 'G' => 30, 'H' => 30, 'I' => 30
        ];
    }

    public function title(): string
    {
        return '2.1 - СМГ срывы и действия';
    }

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
                    ],
                ];

                // Применить стиль к первой строке заголовков
                $event->sheet->getStyle('A1:I1')->applyFromArray($headerStyle);

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
                    ],
                ];

                // Применить стиль ко второй строке
                $event->sheet->getStyle('A2:I2')->applyFromArray($typeRowStyle);

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
                $event->sheet->getStyle('A1:I3')->applyFromArray($borderStyle);

                // Заморозить первую строку
                $event->sheet->freezePane('A2');

                // Установить автофильтр на строку с типами данных (строка 2)
                $event->sheet->setAutoFilter('A2:I2');

                // Добавить выпадающие списки
                $validationValues = [
                    'A' => ['уин1', 'уин2', 'уин3'],
                    'B' => ['text'],
                    'C' => ['Получение ГПЗУ', 'Получение ТУ от РСО и т.п.'],
                    'D' => ['text'],
                    'E' => ['text'],
                    'F' => ['text'],
                    'G' => ['ДД.ММ.ГГГГ'],
                    'H' => ['ДД.ММ.ГГГГ'],
                    'I' => ['text']
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
