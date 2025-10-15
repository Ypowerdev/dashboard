<?php

namespace App\Services;

use App\Models\ObjectModel;
use App\Models\User;
use App\Models\UserRoleRight;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OivStaffImportTemplate
{
    /**
     * Сгенерировать и отдать Excel-шаблон на основе доступных пользователю объектов.
     */
    public function download(): StreamedResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $objects = $this->getUpdatableObjects($user); // id, uin, name

        $spreadsheet = $this->buildSpreadsheet($objects, $user);

        $filename = 'oiv_import_template_' . now()->format('Y-m-d_H-i') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            // Ускоряет запись, отключая предварительный пересчёт формул
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Получаем список объектов, которые пользователь может обновлять (право U).
     * Возвращаем: Collection([ ['id'=>..,'uin'=>..,'name'=>..], ... ])
     */
    protected function getUpdatableObjects(User $user): Collection
    {
        // Если есть готовый хелпер:
        if (method_exists(UserRoleRight::class, 'getAllowedObjectToUpdate')) {
            $ids = UserRoleRight::getAllowedObjectToUpdate($user->id);
            if (empty($ids)) {
                return collect();
            }
            return ObjectModel::query()
                ->whereIn('id', $ids)
                ->select('id', 'uin', 'name')
                ->orderBy('name')
                ->get();
        }

        // Fallback: U по ОИВ → через поле oiv_id (подгони под свою схему)
        $oivIds = $user->getAllowedOrganizationIds('oiv', 'U');
        if (empty($oivIds)) {
            return collect();
        }
        return ObjectModel::query()
            ->whereIn('oiv_id', $oivIds)
            ->select('id', 'uin', 'name')
            ->orderBy('name')
            ->get();
    }

    /**
     * Собираем Excel: Data, Refs (hidden), Instructions.
     */
    protected function buildSpreadsheet(Collection $objects, User $user): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        // ===== Sheet: Data =====
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');

        // Заголовки
        $headers = ['object_uin', 'date', 'count_plan', 'count_fact'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValueByColumnAndRow($i + 1, 1, $h);
        }

        // Стили шапки
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->getStyle('A1:D1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A1:D1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFEFF3F6');

        // Ширина/фокус
        $sheet->getColumnDimension('A')->setWidth(26);
        $sheet->getColumnDimension('B')->setWidth(16);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(14);

        // Freeze header + автофильтр
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:D1');

        // Примерные строки (с датой сегодня для удобства), можно оставить пусто — на твой вкус
        $startRow = 2;
        $prefillRows = max(10, min(50, $objects->count())); // от 10 до 50 строк
        for ($r = 0; $r < $prefillRows; $r++) {
            $row = $startRow + $r;
            // Дата форматом yyyy-mm-dd
            $sheet->setCellValueExplicit("B{$row}", now()->toDateString());
            $sheet->getStyle("B{$row}")
                ->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        }

        // ===== Sheet: Refs (справочники) =====
        $refs = $spreadsheet->createSheet();
        $refs->setTitle('Refs');
        $refs->setCellValue('A1', 'object_uin');
        $refs->setCellValue('B1', 'object_name');

        $i = 2;
        foreach ($objects as $obj) {
            $uin = (string)($obj->uin ?? '');
            $name = (string)($obj->name ?? '');
            $refs->setCellValue("A{$i}", $uin);
            $refs->setCellValue("B{$i}", $name);
            $i++;
        }

        // Скрыть лист Refs (но он будет доступен для валидаций)
        $refs->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

        // Определим именованный диапазон UINs для валидации
        $lastRow = max(2, $i - 1);
        $uinRange = "Refs!A2:A{$lastRow}";
        $namedRange = new NamedRange('UINs', $refs, "A2:A{$lastRow}");
        $spreadsheet->addNamedRange($namedRange);

        // Валидация списка на столбец A (object_uin) в Data
        $maxTemplateRows = max($prefillRows, 500); // дадим запас строк под ручной ввод
        for ($row = 2; $row <= ($startRow + $maxTemplateRows); $row++) {
            $validation = $sheet->getCell("A{$row}")->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            $validation->setAllowBlank(false);
            $validation->setShowDropDown(true);
            $validation->setShowErrorMessage(true);
            $validation->setErrorTitle('Неверное значение');
            $validation->setError('Выберите UIN из списка.');
            $validation->setFormula1('=UINs'); // именованный диапазон
        }

        // Числовые форматы для план/факт
        $sheet->getStyle("C2:D".($startRow + $maxTemplateRows))
            ->getNumberFormat()->setFormatCode('0.00');

        // ===== Sheet: Instructions =====
        $inst = $spreadsheet->createSheet();
        $inst->setTitle('Instructions');
        $tips = [
            'Шаблон предназначен для импорта данных ОИВ.',
            'Заполняйте строки на листе "Data".',
            'object_uin — выбирайте из выпадающего списка. Не вводите произвольные значения.',
            'date — формат YYYY-MM-DD, не будущее.',
            'count_plan / count_fact — числа (можно с дробной частью).',
            'Строки с пустым UIN будут проигнорированы.',
            'Импорт проверяет ваши права на изменение каждого объекта. Без права U строка будет пропущена.',
        ];
        $row = 1;
        foreach ($tips as $t) {
            $inst->setCellValue("A{$row}", '• '.$t);
            $row++;
        }
        $inst->getColumnDimension('A')->setWidth(120);
        $inst->getStyle('A1:A'.$row)->getAlignment()->setWrapText(true);

        // Вернём активным лист Data
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }
}
