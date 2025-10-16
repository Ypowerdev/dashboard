<?php

namespace App\Exports\Sheets;

use App\DTO\SmgDailyReportDTO;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;


class SmgDailySheet implements FromArray, WithTitle, WithEvents, WithColumnWidths
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
        // Строка 1: Группы столбцов
        $row1 = [
            '', '', '',
            // Общие (28 столбцов)
            'Общие', 'Общие', 'Общие', 'Общие', 'Общие', 'Общие', 'Общие', 'Общие',
            'Общие', 'Общие', 'Общие', 'Общие', 'Общие', 'Общие', 'Общие', 'Общие',
            'Общие', 'Общие', 'Общие', 'Общие', 'Общие', 'Общие', 'Общие', 'Общие',
            'Общие', 'Общие', 'Общие', 'Общие',
            // Реновация, Дом, Школа, Больница (18 столбцов)
            'Реновация, Дом, Школа, Больница', 'Реновация, Дом, Школа, Больница',
            'Реновация, Дом, Школа, Больница', 'Реновация, Дом, Школа, Больница',
            'Реновация, Дом, Школа, Больница', 'Реновация, Дом, Школа, Больница',
            'Реновация, Дом, Школа, Больница', 'Реновация, Дом, Школа, Больница',
            'Реновация, Дом, Школа, Больница', 'Реновация, Дом, Школа, Больница',
            'Реновация, Дом, Школа, Больница', 'Реновация, Дом, Школа, Больница',
            'Реновация, Дом, Школа, Больница', 'Реновация, Дом, Школа, Больница',
            'Реновация, Дом, Школа, Больница', 'Реновация, Дом, Школа, Больница',
            'Реновация, Дом, Школа, Больница', 'Реновация, Дом, Школа, Больница',
            // Школа, Больница (5 столбцов)
            'Школа, Больница', 'Школа, Больница', 'Школа, Больница', 'Школа, Больница', 'Школа, Больница',
            // Дорога (6 столбцов)
            'Дорога', 'Дорога', 'Дорога', 'Дорога', 'Дорога', 'Дорога'
        ];

        // Строка 2: Заголовки столбцов
        $row2 = [
            'user АНО СТРОИНФОРМ', 'дата редактирования строки', 'На какую дату актуализировано состояник ОКС',
            'УИН', 'Мастер код ФР', 'Мастер код ДС', 'link', 'ФНО', 'Наименование',
            'Наименование ЖК (коммерческое наименование)', 'Краткое наименование', 'ФНО для аналитики',
            'Округ', 'Район', 'Источник финансирования', 'Статус ОКС', 'АИП (да/нет)', 'Дата включения в АИП',
            'Сумма по АИП, млрд руб', 'Адрес объекта', 'Признак реновации (да/нет)', 'ИНН Застройщика',
            'Застройщик', 'ИНН Заказчика', 'Заказчик', 'ИНН Генподрядчика', 'Генподрядчик',
            'Общая площадь, м2', 'Протяженность', 'Этажность',
            'СТРОИТЕЛЬНАЯ ГОТОВНОСТЬ (% выполнения, факт)',
            'КОНСТРУКТИВ (% выполнения, факт)',
            'СТЕНЫ И ПЕРЕГОРОДКИ (% выполнения, факт)',
            'ФАСАД (% выполнения, факт)',
            'УТЕПЛИТЕЛЬ (% выполнения, факт)',
            'ФАСАДНАЯ СИСТЕМА (% выполнения, факт)',
            'ВНУТРЕННЯЯ ОТДЕЛКА (% выполнения, факт)',
            'ЧЕРНОВАЯ ОТДЕЛКА (% выполнения, факт)',
            'ЧИСТОВАЯ ОТДЕЛКА (% выполнения, факт)',
            'ВНУТРЕННИЕ СЕТИ (% выполнения, факт)',
            'ВОДОСНАБЖЕНИЕ И ОТОПЛЕНИЕ (ВЕРТИКАЛЬ) (% выполнения, факт)',
            'ВОДОСНАБЖЕНИЕ И ОТОПЛЕНИЕ ПОЭТАЖНО (ГОРИЗОНТ) (% выполнения, факт)',
            'ВЕНТИЛЯЦИЯ (% выполнения, факт)',
            'ЭЛЕКТРОСНАБЖЕНИЕ И СКС (% выполнения, факт)',
            'НАРУЖНЫЕ СЕТИ (% выполнения, факт)',
            'БЛАГОУСТРОЙСТВО (% выполнения, факт)',
            'ТВЕРДОЕ ПОКРЫТИЕ (% выполнения, факт)',
            'ОЗЕЛЕНЕНИЕ (% выполнения, факт)',
            'МАФ (% выполнения, факт)',
            'ОБОРУДОВАНИЕ ПО ТХЗ (% выполнения, факт)',
            'ПОСТАВЛЕНО НА ОБЪЕКТ (% выполнения, факт)',
            'СМОНТИРОВАНО (% выполнения, факт)',
            'МЕБЕЛЬ (% выполнения, факт)',
            'КЛИНИНГ (% выполнения, факт)',
            'Дорожное покрытие (% выполнения, факт)',
            'Искусственные сооружения (ИССО) (% выполнения, факт)',
            'Наружные инженерные сети (% выполнения, факт)',
            'Средства организации дорожного движения (% выполнения, факт)',
            'Дорожная разметка (% выполнения, факт)',
            'Благоустройство прилегающей территории (% выполнения, факт)',
            'Комментарий'
        ];

        // Строка 3: Типы данных
        $row3 = [
            'ФИО', 'ДД.ММ.ГГГГ', 'ДД.ММ.ГГГГ', 'уин1', 'text', 'text', 'text', 'text', 'text', 'text',
            'text', 'text', 'По справочнику округов Москвы', 'По справочнику районов Москвы', 'text',
            'Планируется/в строительстве и т.д', 'text', 'ДД.ММ.ГГГГ', '', 'text', 'text', 'text',
            'text', 'text', 'text', 'text', 'text', 'numeric', 'numeric, км', 'int', '%', '%', '%', '%', '%', '%',
            '%', '%', '%', '%', '%', '%', '%', '%', '%', '%', '%', '%', '%', '%', '%', '%', '%', '%', '%',
            '%', '%', '%', '%', '%', 'text'
        ];

        return [$row1, $row2, $row3];
    }

    /**
     * Возвращает массив ключей (заголовков) без значений
     * @return array
     */
    private function getDataKeys(): array
    {
        $headerRows = $this->getHeaderRows();
        $keys = [];

        // Используем вторую строку с заголовками столбцов как ключи
        foreach ($headerRows[1] as $index => $header) {
            // Очищаем заголовок от специальных символов для использования в качестве ключа
            $key = preg_replace('/[^a-zA-Z0-9а-яА-Я\s]/u', '', $header);
            $key = preg_replace('/\s+/', '_', $key);
            $key = trim($key, '_');
            $key = mb_strtolower($key, 'UTF-8');

            $keys[] = $key;
        }

        return $keys;
    }

    public function array(): array
    {
        $headerRows = $this->getHeaderRows();

        if (empty($this->data)) {
            $emptyDataRow = array_fill(0, count($headerRows[1]), '');
            return array_merge($headerRows, [$emptyDataRow]);
        }

        $dataRows = [];
        foreach ($this->data as $item) {
            if ($item instanceof SmgDailyReportDTO) {
                $dataRows[] = $item->toArray();
            } elseif (is_array($item)) {
                $dataRows[] = array_values($item);
            }
        }

        return array_merge($headerRows, $dataRows);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                // Общие стили для границ
                $borderStyle = [
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                    'alignment' => [
                        'wrapText' => true,
                    ],
                ];

                // Стили для первых трех столбцов
                // Первая строка - без фона
                // Вторая строка - серый фон, черный текст
                $grayHeaderStyle = [
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '3f3f3f']
                    ],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'f2f2f2']
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ];

                // Добавляем границы к стилю
                $grayHeaderStyle = array_merge($grayHeaderStyle, $borderStyle);

                $event->sheet->getStyle('A1:C1')->applyFromArray($borderStyle);
                $event->sheet->getStyle('A2:C2')->applyFromArray($grayHeaderStyle);

                // Стили для столбцов D-AD (D-30)
                // Первая строка - голубой фон, синий текст
                // Вторая строка - голубой фон, черный текст
                $blueGroupStyle = [
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '3054b8'] // Синий текст
                    ],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'ddebf7'] // Голубой фон
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ];

                // Добавляем границы к стилю
                $blueGroupStyle = array_merge($blueGroupStyle, $borderStyle);

                $blueHeaderStyle = [
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '000000'] // Черный текст
                    ],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'ddebf7'] // Голубой фон
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ];

                // Добавляем границы к стилю
                $blueHeaderStyle = array_merge($blueHeaderStyle, $borderStyle);

                $event->sheet->getStyle('D1:AD1')->applyFromArray($blueGroupStyle);
                $event->sheet->getStyle('D2:AD2')->applyFromArray($blueHeaderStyle);

                // Стиль для столбца AE (31)
                // Желтый фон, коричневый текст
                $yellowStyle = [
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '9c5700'] // Коричневый текст
                    ],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'ffeb9c'] // Желтый фон
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ];

                // Добавляем границы к стилю
                $yellowStyle = array_merge($yellowStyle, $borderStyle);

                $event->sheet->getStyle('AE1:AE1')->applyFromArray($yellowStyle);
                $event->sheet->getStyle('AE2:AE2')->applyFromArray($yellowStyle);

                // Стили для столбцов AF-AW (32-49)
                // Светло-желтый фон, коричневый текст
                $lightYellowStyle = [
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => 'ad5700'] // Коричневый текст
                    ],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'fff2cc'] // Светло-желтый фон
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ];

                // Добавляем границы к стилю
                $lightYellowStyle = array_merge($lightYellowStyle, $borderStyle);

                $event->sheet->getStyle('AF1:AW1')->applyFromArray($lightYellowStyle);
                $event->sheet->getStyle('AF2:AW2')->applyFromArray($lightYellowStyle);

                // Стили для столбцов AX-BB (50-54)
                // Светло-коричневый фон, коричневый текст
                $lightBrownStyle = [
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => 'ad5700'] // Коричневый текст
                    ],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'f8cbad'] // Светло-коричневый фон
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ];

                // Добавляем границы к стилю
                $lightBrownStyle = array_merge($lightBrownStyle, $borderStyle);

                $event->sheet->getStyle('AX1:BB1')->applyFromArray($lightBrownStyle);
                $event->sheet->getStyle('AX2:BB2')->applyFromArray($lightBrownStyle);

                // Стили для столбцов BC-BH (55-60)
                // Голубой фон, синий текст
                $blueGroupStyle2 = [
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '1f4e78'] // Синий текст
                    ],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'd5dbe3'] // Голубой фон
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ];

                // Добавляем границы к стилю
                $blueGroupStyle2 = array_merge($blueGroupStyle2, $borderStyle);

                $blueHeaderStyle2 = [
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '000000'] // Черный текст
                    ],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'ddebf7'] // Голубой фон
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ];

                // Добавляем границы к стилю
                $blueHeaderStyle2 = array_merge($blueHeaderStyle2, $borderStyle);

                $event->sheet->getStyle('BC1:BH1')->applyFromArray($blueGroupStyle2);
                $event->sheet->getStyle('BC2:BH2')->applyFromArray($blueHeaderStyle2);

                // Стиль для третьей строки (типы данных) - только жирный шрифт и границы
                $typeRowStyle = [
                    'font' => [
                        'bold' => true,
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                ];

                $event->sheet->getStyle('A3:BH3')->applyFromArray($typeRowStyle);

                // Заморозить первые три строки
                $event->sheet->freezePane('A4');

                // Установить автофильтр на строку с типами данных (строка 3)
                $lastColumn = $event->sheet->getHighestColumn();
                $event->sheet->setAutoFilter('A3:'.$lastColumn.'3');

                // Добавить выпадающие списки для ячеек с типами данных
                $row = 3;
                for ($col = 'A'; $col <= $lastColumn; $col++) {
                    $cellCoordinate = $col . $row;
                    $validation = $event->sheet->getDataValidation($cellCoordinate);
                    $validation->setType(DataValidation::TYPE_LIST);
                    $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
                    $validation->setAllowBlank(false);
                    $validation->setShowInputMessage(true);
                    $validation->setShowErrorMessage(true);
                    $validation->setShowDropDown(true);
                    $validation->setErrorTitle('Ошибка ввода');
                    $validation->setError('Значение не в списке допустимых');
                    $validation->setPromptTitle('Выберите из списка');
                    $validation->setPrompt('Пожалуйста, выберите значение из выпадающего списка');

                    // Установите возможные значения для каждого столбца
                    $values = $this->getValidationValues($col);
                    $validation->setFormula1('"' . implode(',', $values) . '"');
                }

                // Добавляем границы к ячейкам в столбце BI
                $event->sheet->getStyle('BI1')->applyFromArray($borderStyle);
                $event->sheet->getStyle('BI2')->applyFromArray($borderStyle);
                $event->sheet->getStyle('BI3')->applyFromArray($borderStyle);
            },
        ];
    }

    public function columnWidths(): array
    {
        // Устанавливаем ширины колонок в соответствии с оригинальным файлом
        return [
            'A' => 30, 'B' => 22.5, 'C' => 22.5, 'D' => 20, 'E' => 20, 'F' => 20,
            'G' => 22.5, 'H' => 20, 'I' => 45, 'J' => 45, 'K' => 30, 'L' => 22.5,
            'M' => 22.5, 'N' => 22.5, 'O' => 22.5, 'P' => 20, 'Q' => 20, 'R' => 22.5,
            'S' => 22.5, 'T' => 22.5, 'U' => 20, 'V' => 22.5, 'W' => 30, 'X' => 22.5,
            'Y' => 30, 'Z' => 22.5, 'AA' => 30, 'AB' => 20, 'AC' => 20, 'AD' => 20,
            'AE' => 20, 'AF' => 20, 'AG' => 20, 'AH' => 20, 'AI' => 20, 'AJ' => 20,
            'AK' => 20, 'AL' => 20, 'AM' => 20, 'AN' => 20, 'AO' => 20, 'AP' => 20,
            'AQ' => 20, 'AR' => 20, 'AS' => 20, 'AT' => 20, 'AU' => 20, 'AV' => 20,
            'AW' => 20, 'AX' => 20, 'AY' => 20, 'AZ' => 20, 'BA' => 20, 'BB' => 20,
            'BC' => 20, 'BD' => 20, 'BE' => 20, 'BF' => 20, 'BG' => 20, 'BH' => 20, 'BI' => 20,
        ];
    }

    public function title(): string
    {
        return '1 - СМГ ежедневный';
    }

    private function getValidationValues(string $column): array
    {
        // Возвращаем возможные значения для каждого столбца
        return match ($column) {
            'D' => ['уин1', 'уин2', 'уин3'],
            'E', 'F', 'G', 'H' => ['text'],
            'I', 'J', 'K' => ['text', 'numeric'],
            'L' => ['По справочнику округов Москвы'],
            'M' => ['По справочнику районов Москвы'],
            'N' => ['text', '02 Городской бюджет', '03 Федеральный бюджет'],
            'O' => ['Планируется', 'В строительстве', 'Сдан', 'Завершен'],
            'P' => ['Да', 'Нет'],
            'Q' => ['ДД.ММ.ГГГГ'],
            'R' => ['numeric'],
            'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB' => ['text'],
            'AC', 'AD' => ['numeric'],
            'AE' => ['int'],
            'AF' => ['%'],

            default => ['text', 'numeric', 'int', 'ДД.ММ.ГГГГ', '%'],
        };
    }
}
