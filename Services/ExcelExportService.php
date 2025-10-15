<?php

namespace App\Services;

use App\Exports\ExcelExport;
use App\Models\ExportFile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ExcelExportService
{
    public function generateExcelReport(?int $userId = null): string
    {
        $user = $userId ? User::find($userId) : Auth::user();

        if (!$user) {
            throw new \RuntimeException('User is not resolved for export.');
        }

        // Генерируем уникальное имя файла
        $filename = $user->name . Carbon::now()->format('d.m.Y_H-i-s') . '.xlsx';
        $path = $filename;

        $safeUser = Str::slug($user->name ?: 'user-'.$user->id);
        $subdir   = $user->id.'/exports/'.now()->format('Y/m/d');
        $filename = $safeUser.'_'.now()->format('Y-m-d_H-i-s').'.xlsx';
        $path     = $subdir.'/'.$filename; // ← относительный путь внутри диска

        $export = ExportFile::create([
            'user_id'    => $user->id,
            'type'       => 'oiv_template',
            'disk'       => 'excels',
            'path'       => $path,
            'filename'   => $filename,
            'status'     => 'processing',
            'meta'       => ['requested_at' => now()],
            'expires_at' => now()->addDays(14),
        ]);

        // Создаем директорию при необходимости
        // TODO Вынести создание папок и файлов в FileFolderService
        Storage::disk('excelsExportDisk')->makeDirectory($subdir);

        // TODO Добавить cleaner для удаления старых файлов
        // Генерируем и сохраняем файл
        Excel::store(new ExcelExport(userId: $userId), $path, 'excels');

        // после успешной записи
        $size = Storage::disk('excelsExportDisk')->size($path);
        $export->update([
            'path'   => $path,
            'size'   => $size,
            'status' => 'ready',
        ]);

        // Возвращаем URL для скачивания
        return Storage::disk('excelsExportDisk')->url($path);
    }
}
