<?php

namespace App\Services;

use App\Exports\UsersExport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ExportUserService
{
    public function exportUsers()
    {
        // return Excel::download(new UsersExport, 'users.xlsx');
        // return Excel::download(new UsersExport, 'users.xlsx');

        $filePath = 'exports/users_' . now()->format('Y-m-d_H-i-s') . '.xlsx';
        // $filePath = 'exports/users_user.xlsx';
        // $filePath = 'user.xlsx';
        Excel::store(new UsersExport, $filePath, 'public');

        // Или store для сохранения на сервере:
        // Excel::store(new UsersExport, 'users.xlsx', 's3'); // если используете диск
    }
}