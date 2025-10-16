<?php

namespace App\Exports\Sheets;

use App\DTO\OivPlanDTO;
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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;


class OivPlanSheet extends BaseSheet
{
    private array $data;
    private array $constructionStages;

    public function __construct(array $data = [], array $constructionStages = [])
    {
        $this->data = $data;
        $this->constructionStages = $constructionStages;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function setConstructionStages(array $stages): void
    {
        $this->constructionStages = $stages;
    }

    /**
     * Возвращает массив строк с заголовками
     * @return array
     */
    private function getHeaderRows(): array
    {
        // Заголовки столбцов
        $baseHeaders = [
            "user АНО СТРОИНФОРМ",
            "дата редактирования строки",
            "На какую дату актуализировано состояник ОКС", // $object->oks_date
            "УИН", // $object->uin
            "Мастер код ФР", // (отсутствует, надо добавить)
            "Мастер код ДС", // $object->master_kod_dc
            "link", // $object->link
            "ФНО", // $object->fno_type_code
            "Наименование", // $object->name
            "Наименование ЖК (коммерческое наименование)",// (отсутствует, надо добавить)
            "Краткое наименование",// (отсутствует, надо добавить)
            "ФНО для аналитики",// $object->fno->group_name
            "Округ",// $object->region->DistrictLibrary->name
            "Район",// $object->region->name
            "Источник финансирования",// $object->finSource->name
            "Статус ОКС",// $object->oksStatusLibrary->name
            "АИП (да/нет)",// $object->aip_flag
            "Дата включения в АИП",// $object->aip_inclusion_date
            "Сумма по АИП, млрд руб",// $object->aip_sum
            "Адрес объекта",// $object->address
            "Признак реновации (да/нет)",// $object->renovation
            "ИНН Застройщика",// $object->developer->inn
            "Застройщик",// $object->developer->name
            "ИНН Заказчика",// $object->customer->inn
            "Заказчик",// $object->customer->name
            "ИНН Генподрядчика", // $object->contractor->inn
            "Генподрядчик", // $object->contractor->name
            "Общая площадь, м2", // $object->area
            "Протяженность", // $object->length
            "Этажность", // $object->floors
            "Плановый ввод по директивному графику", // $object->planned_commissioning_directive_date
            "Дата совещания - изменение даты ввода по директивному графику", // $object->planned_commissioning_directive_think_change_date
            "Плановый ввод по договору", // $object->contract_commissioning_date
            "Прогнозируемый срок ввода", // $object->forecasted_commissioning_date

//            БЛОК С ЭТАПАМИ РЕАЛИЗАЦИИ - ПЛАНОВЫЕ ЗНАЧЕНИЙ.

//            $service = new ObjectEtapiRealizaciiViewService();
//            Здесь используем метод getPlanDateByEtapName   -  PLAN

            "ГПЗУ (план)", // $service->getPlanDateByEtapName($object, "ГПЗУ (план)");
            "ТУ от РСО (план)", // $service->getPlanDateByEtapName($object, "ТУ от РСО (план)");
            "АГР (план)", // $service->getPlanDateByEtapName($object, "АГР (план)");
            "Экспертиза ПСД (план)", // $service->getPlanDateByEtapName($object, "Экспертиза ПСД (план)");
            "РНС (план)", // $service->getPlanDateByEtapName($object, "РНС (план)");
            "СМР (план)", // $service->getPlanDateByEtapName($object, "СМР (план)");
            "Техприс (план)", // $service->getPlanDateByEtapName($object, "Техприс (план)");
            "ЗОС (план)", // $service->getPlanDateByEtapName($object, "ЗОС (план)");
            "РВ (план)", // $service->getPlanDateByEtapName($object, "РВ (план)");
        ];

// Динамические заголовки строительных этапов
        $constructionHeaders = [];
        foreach ($this->constructionStages as $stage) {
            $formattedName = $this->formatStageName($stage);
            $constructionHeaders[] = $formattedName . " (плановая дата начала)";
            $constructionHeaders[] = $formattedName . " (плановая дата завершения)";
        }

        // Финальные заголовки
        $finalHeaders = [
            "Комментарий",
            "Код ДГС (родитель)"
        ];

        // Строка 1: Все заголовки
        $row1 = array_merge($baseHeaders, $constructionHeaders, $finalHeaders);


        // Строка 2: Типы данных
        $baseTypes = [
            "ФИО", "ДД.ММ.ГГГГ", "ДД.ММ.ГГГГ", "уин1", "text", "text", "text", "int", "text", "text", "text", "text",
            "По справочнику округов Москвы", "По справочнику районов Москвы", "text",
            "Планируется/в строительстве и т.д", "text", "ДД.ММ.ГГГГ", "numeric", "text", "text", "text", "text", "text",
            "text", "text", "text", "numeric", "numeric, км", "int",
            "ДД.ММ.ГГГГ", "ДД.ММ.ГГГГ", "ДД.ММ.ГГГГ", "ДД.ММ.ГГГГ", "ДД.ММ.ГГГГ",
            "ДД.ММ.ГГГГ", "ДД.ММ.ГГГГ", "ДД.ММ.ГГГГ", "ДД.ММ.ГГГГ", "ДД.ММ.ГГГГ",
            "ДД.ММ.ГГГГ", "ДД.ММ.ГГГГ", "ДД.ММ.ГГГГ"
        ];

        // Типы для строительных этапов (по 2 колонки на этап)
        $constructionTypes = [];
        $stageCount = count($this->constructionStages);
        for ($i = 0; $i < $stageCount; $i++) {
            $constructionTypes[] = "ДД.ММ.ГГГГ"; // начало
            $constructionTypes[] = "ДД.ММ.ГГГГ"; // завершение
        }

        // Типы для финальных колонок
        $finalTypes = ["text", "text"];

        $row2 = array_merge($baseTypes, $constructionTypes, $finalTypes);

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
            $columnCount = count($headerRows[0]);
            $emptyDataRow = array_fill(0, $columnCount, '');
            return array_merge($headerRows, [$emptyDataRow]);
        }

        $dataRows = [];
        foreach ($this->data as $index => $item) {
            if ($item instanceof OivPlanDTO) {
                // Передаем строительные этапы в toArray
                $dataRow = $item->toArray($this->constructionStages);
                $dataRows[] = $dataRow;
            } elseif (is_array($item)) {
                $dataRows[] = array_values($item);
            }
        }

        return array_merge($headerRows, $dataRows);
    }

    /**
     * Метод добавляет стили к сформированному листу Excel
     * @return mixed
     */
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
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ];
                $sheet->getStyle('A1:' . $lastColumn . $lastRow)->applyFromArray($borderStyle);

                // Стилизация заголовков
                $headerStyle = [
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'ddebf7']],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ];

                $specialStyle = [
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => false,
                    ],
                ];
                $sheet->getStyle('G3:G' . $lastRow)->applyFromArray($specialStyle);
                $sheet->getStyle('I3:I' . $lastRow)->applyFromArray($specialStyle);
                $sheet->getStyle('K3:K' . $lastRow)->applyFromArray($specialStyle);
                $sheet->getStyle('T3:T' . $lastRow)->applyFromArray($specialStyle);
                $sheet->getStyle('Y3:Y' . $lastRow)->applyFromArray($specialStyle);

                // Заголовки (строка 1)
                $sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray($headerStyle);

                // Типы данных (строка 2) - серый фон
                $typeRowStyle = [
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'e7e6e6']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ];
                $sheet->getStyle('A2:' . $lastColumn . '2')->applyFromArray($typeRowStyle);

                // Заморозить первые две строки
                $sheet->freezePane('A3');

                // Установить автофильтр на строку с типами данных
                $sheet->setAutoFilter('A2:' . $lastColumn . '2');

                // Валидация данных
                $lastColumnIndex = Coordinate::columnIndexFromString($lastColumn);
                for ($colIndex = 1; $colIndex <= $lastColumnIndex; $colIndex++) {
                    $col = Coordinate::stringFromColumnIndex($colIndex);
                    $cellCoordinate = $col . '2';

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

                // --- 1. Создание скрытых листов с опциями ---
                $this->createSelectOptions($event);
            },
        ];
    }

    /**
     * Метод устанавливает ширины колонок
     * @return array
     */
    public function columnWidths(): array
    {
        $widths = [
            'A' => 30, 'B' => 22.5, 'C' => 22.5, 'D' => 20, 'E' => 20, 'F' => 20,
            'G' => 22.5, 'H' => 20, 'I' => 45, 'J' => 45, 'K' => 30, 'L' => 22.5,
            'M' => 22.5, 'N' => 22.5, 'O' => 22.5, 'P' => 20, 'Q' => 20, 'R' => 22.5,
            'S' => 22.5, 'T' => 22.5, 'U' => 20, 'V' => 22.5, 'W' => 30, 'X' => 22.5,
            'Y' => 30, 'Z' => 22.5, 'AA' => 30, 'AB' => 20, 'AC' => 20, 'AD' => 20,
            'AE' => 20, 'AF' => 20, 'AG' => 20, 'AH' => 20, 'AI' => 20, 'AJ' => 20,
            'AK' => 20, 'AL' => 20, 'AM' => 20, 'AN' => 20, 'AO' => 20, 'AP' => 20,
            'AQ' => 20
        ];

        // Добавляем ширины для строительных этапов
        $currentColIndex = 44;
        foreach ($this->constructionStages as $stage) {
            $col1 = Coordinate::stringFromColumnIndex($currentColIndex);
            $col2 = Coordinate::stringFromColumnIndex($currentColIndex + 1);
            $widths[$col1] = 30;
            $widths[$col2] = 30;
            $currentColIndex += 2;
        }

        // Добавляем ширины для финальных колонок
        $finalCol1 = Coordinate::stringFromColumnIndex($currentColIndex);
        $finalCol2 = Coordinate::stringFromColumnIndex($currentColIndex + 1);
        $widths[$finalCol1] = 20;
        $widths[$finalCol2] = 20;

        return $widths;
    }

    /**
     * Метод возвращает название листа Excel
     * @return string
     */
    public function title(): string
    {
        return '4 - ОИВ план';
    }

    /**
     * Возвращает допустимые значения для валидации по номеру столбца
     * @param string $column
     * @return array
     */
    private function getValidationValues(string $column): array
    {
        $colIndex = Coordinate::columnIndexFromString($column);

        // Базовые колонки (A-D)
        if ($colIndex === 1) {
            return ['ФИО'];
        } elseif ($colIndex === 2 || $colIndex === 3) {
            return ['ДД.ММ.ГГГГ'];
        } elseif ($colIndex === 4) {
            return ['уин1', 'уин2', 'уин3'];
        } elseif ($colIndex >= 5 && $colIndex <= 13) {
            return ['text'];
        } elseif ($colIndex === 14) {
            return ['По справочнику округов Москвы'];
        } elseif ($colIndex === 15) {
            return ['По справочнику районов Москвы'];
        } elseif ($colIndex === 16) {
            return ['text', '02 Городской бюджет', '03 Федеральный бюджет'];
        } elseif ($colIndex === 17) {
            return ['Планируется', 'В строительстве', 'Сдан', 'Завершен', 'В проектировании'];
        } elseif ($colIndex === 18) {
            return ['Да', 'Нет'];
        } elseif ($colIndex === 19) {
            return ['ДД.ММ.ГГГГ'];
        } elseif ($colIndex === 20) {
            return ['numeric'];
        } elseif ($colIndex >= 21 && $colIndex <= 27) {
            return ['text'];
        } elseif ($colIndex === 28) {
            return ['numeric'];
        } elseif ($colIndex === 29) {
            return ['numeric', 'numeric, км'];
        } elseif ($colIndex === 30) {
            return ['int'];
        } elseif ($colIndex >= 31 && $colIndex <= 44) {
            return ['ДД.ММ.ГГГГ'];
        }

        // Строительные этапы и финальные колонки
        return ['text', 'numeric', 'int', 'ДД.ММ.ГГГГ', '%'];
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
        return 1;
    }
}
