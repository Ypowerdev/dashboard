<?php

namespace App\Exports;

use App\Models\Library\ConstructionStagesLibrary;
use App\Models\User;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet; // Для VERYHIDDEN
use PhpOffice\PhpSpreadsheet\Cell\DataType; // Для TYPE_STRING
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class UsersExport implements FromCollection, WithHeadings, WithEvents
{
    protected $selectOptions;
    protected $selectOptionsConstructionStages;

    public function __construct()
    {
        $this->selectOptions = ['Активен', 'Неактивен', 'В ожидании'];
        // Убедимся, что у нас массив строк без ключей
        $this->selectOptionsConstructionStages = array_values(
            array_filter(
                array_map('strval', ConstructionStagesLibrary::pluck('name')->toArray())
            )
        );
    }

    public function collection()
    {
        return User::select('name', 'email')->get();
    }

    public function headings(): array
    {
        return [
            'Имя',
            'Email',
            'Статус', // C
            // Добавьте заголовки для других столбцов с селектами
            '', // Пустой заголовок для столбца F
            '', // Пустой заголовок для столбца G
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                // --- 1. Создание скрытых листов с опциями ---
                // Сначала создаем скрытые листы, так как они нужны для валидации
                $validationRules = $this->getColumnValidationRules();
                $this->createHiddenOptionSheets($event, $validationRules);

                // --- 2. Применение раскрывающихся списков ---
                $this->applySelectLists($event, $validationRules, 2);
            },
        ];
    }

    /**
     * Создаёт скрытые листы в рабочей книге и заполняет их опциями.
     *
     * @param AfterSheet $event Событие AfterSheet.
     * @param array $validationRules Массив правил валидации, как возвращается getColumnValidationRules().
     *                               Используется 'hidden_column' (теперь это имя листа) и 'options'.
     */
    protected function createHiddenOptionSheets(AfterSheet $event, array $validationRules): void
    {
        $workbook = $event->sheet->getDelegate()->getParent(); // Получаем объект PhpOffice\PhpSpreadsheet\Spreadsheet
        $processedSheets = []; // Отслеживаем уже созданные листы

        foreach ($validationRules as $rule) {
            if (!is_array($rule) || !isset($rule['hidden_column']) || !isset($rule['options'])) {
                continue;
            }

            $sheetName = $rule['hidden_column']; // Теперь 'hidden_column' - это имя скрытого листа
            $options = is_array($rule['options']) ? $rule['options'] : [];
            $otherOptionLabel = $rule['other_option'] ?? null;

            // Проверяем, обрабатывали ли мы уже этот лист
            if (in_array($sheetName, $processedSheets, true)) {
                // Предполагаем, что опции для одного листа определяются первым правилом, которое его использует.
                // Если нужно объединять опции из разных правил - логика усложняется.
                continue;
            }

            // Подготавливаем полный список опций для скрытого листа
            $finalOptions = $options;

            // Если указана опция "Другое..." и она еще не в списке, добавляем её
            if ($otherOptionLabel &&
                is_string($otherOptionLabel) &&
                !in_array($otherOptionLabel, $finalOptions, true)) {
                $finalOptions[] = $otherOptionLabel;
            }

            // Создаем новый лист
            $hiddenSheet = $workbook->createSheet();
            $hiddenSheet->setTitle($sheetName);

            // Заполняем лист опциями
            foreach ($finalOptions as $index => $option) {
                // Используем setCellValueExplicit для предотвращения автоформатирования
                $hiddenSheet->setCellValueExplicit(
                    'A' . ($index + 1), // Заполняем столбец A
                    $option,
                    DataType::TYPE_STRING
                );
            }

            // Скрываем лист
            $hiddenSheet->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

            // --- Дополнительно: Защита скрытого листа ---
            // Это поможет действительно скрыть данные
            // $protection = $hiddenSheet->getProtection();
            // $protection->setSheet(true);
            // $protection->setPassword(env('EXCEL_OPTIONS_SHEET_PASSWORD', 'SecureHiddenSheetPass123!'));

            $processedSheets[] = $sheetName;
        }
    }


    /**
     * Применяет настройки раскрывающихся списков к листу.
     *
     * @param AfterSheet $event Событие AfterSheet.
     * @param array $columnRules Массив правил валидации.
     *                           Каждый элемент массива - ассоциативный массив с ключами:
     *                           - 'column' (string): Буква столбца Excel, к которому применяется валидация (например, 'C').
     *                           - 'hidden_column' (string): Имя скрытого листа с опциями (например, 'HiddenOptions_D').
     *                           - 'options' (array): Массив опций (используется для создания скрытого листа, передается из getColumnValidationRules).
     *                           - 'allow_blank' (bool, опционально): Разрешить пустые значения. По умолчанию true.
     *                           - 'other_option' (string, опционально): Метка для опции "Другое". Если указана, должна быть в 'options'.
     * @param int $startRow Номер строки, с которой начинаются данные (где применять валидацию).
     */
    protected function applySelectLists(AfterSheet $event, array $columnRules, int $startRow = 2): void
    {
        $sheet = $event->sheet->getDelegate();
        $highestRow = $sheet->getHighestRow();

        if ($highestRow < $startRow) {
            return; // Нет строк для применения валидации
        }

        foreach ($columnRules as $ruleDefinition) {
            // Проверяем, что ruleDefinition - это массив с необходимыми ключами
            if (!is_array($ruleDefinition) || !isset($ruleDefinition['column']) || !isset($ruleDefinition['hidden_column']) || !isset($ruleDefinition['options'])) {
                \Log::warning('Invalid validation rule in UsersExport', ['rule' => $ruleDefinition]);
                continue; // Пропускаем некорректные правила
            }

            $targetColumn = $ruleDefinition['column'];       // Столбец для применения валидации, например, 'C'
            $hiddenSheetName = $ruleDefinition['hidden_column']; // Имя скрытого листа с опциями, например, 'HiddenOptions_D'
            $allowBlank = $ruleDefinition['allow_blank'] ?? true; // Значение по умолчанию
            // $otherOptionLabel = $ruleDefinition['other_option'] ?? null; // Не используется напрямую в applySelectLists

            // Создаем объект валидации
            $validation = new DataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
            // Используем allow_blank из правила
            $validation->setAllowBlank($allowBlank);
            $validation->setShowInputMessage(true);
            $validation->setShowErrorMessage(false);
            $validation->setShowDropDown(true);
            // Можно добавить сообщения
            $validation->setErrorTitle('Ошибка ввода');
            $validation->setError('Выбранное значение отсутствует в списке.');
            $validation->setPromptTitle('Выберите из списка');
            $validation->setPrompt('Пожалуйста, выберите значение из выпадающего списка.');

            // Формируем ссылку на диапазон в скрытом листе
            // Предполагаем, что опции заполняют столбец A с 1-й строки
            $optionCount = count($ruleDefinition['options']);
            // Имя листа в формуле Excel должно быть в одинарных кавычках, если содержит пробелы или специальные символы
            $formula = "'{$hiddenSheetName}'!\$A\$1:\$A\${$optionCount}";
            $validation->setFormula1($formula);

            // Определяем диапазон для применения валидации на основном листе
            $range = $targetColumn . $startRow . ':' . $targetColumn . $highestRow;

            // Применяем валидацию
            $sheet->setDataValidation($range, $validation);

            // --- 2. Условное форматирование ---
            $conditional = new Conditional();
            $conditional->setConditionType(Conditional::CONDITION_EXPRESSION);
            $conditional->setOperatorType(Conditional::OPERATOR_NONE);

            // Формула: подсветка только если ячейка НЕ пустая и значение НЕ из списка
            // ISNA(MATCH(...)) → TRUE если нет совпадений в списке
            $conditional->addCondition("=AND({$targetColumn}{$startRow}<>\"\",ISNA(MATCH({$targetColumn}{$startRow},{$formula},0)))");

            // Стиль подсветки (светло-серый фон + курсив)
            $conditional->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFEFEFEF'); // очень светло-серый
            $conditional->getStyle()->getFont()->setItalic(true);

            $conditionalStyles = $sheet->getStyle($range)->getConditionalStyles();
            $conditionalStyles[] = $conditional;
            $sheet->getStyle($range)->setConditionalStyles($conditionalStyles);
        }
    }

    /**
     * Определяет правила для раскрывающихся списков.
     * Эти правила используются в applySelectLists и createHiddenOptionSheets.
     */
    protected function getColumnValidationRules(): array
    {
        // dd([
        //     '$this->selectOptionsConstructionStages' => $this->selectOptionsConstructionStages,
        //     'count' => count($this->selectOptionsConstructionStages),
        // ]);

        return [
            [
                'column' => 'F',
                // 'hidden_column' теперь имя скрытого листа
                'hidden_column' => 'HiddenOptions_E', 
                'options' => array_merge(['Да', 'Нет'], ['Другое...']),
                'allow_blank' => true,
                'other_option' => 'Другое...',
            ],
            [
                'column' => 'C',
                'hidden_column' => 'HiddenOptions_D',
                'options' => $this->selectOptionsConstructionStages,
                'allow_blank' => true, // Исправлено: было false, но по логике должно быть true для "Другое"
                'other_option' => 'Другое...',
            ],
            [
                'column' => 'G',
                'hidden_column' => 'HiddenOptions_Z',
                'options' => [
                    'Активен',
                    'Неактивен',
                    'В ожидании',
                    'тест1',
                    'тест2',
                    'тест3',
                    'тест4',
                    'тест5',
                    'тест6',
                    'тест7',
                ],
                'allow_blank' => true,
                'other_option' => 'Другое...',
            ],
        ];
    }
}