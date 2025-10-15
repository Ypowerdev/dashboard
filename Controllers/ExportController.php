<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function download(ExportFile $export)
    {
        abort_unless(auth()->id() === $export->user_id || auth()->user()?->is_admin, 403);
        abort_unless($export->status === 'ready', 409, 'Файл ещё не готов');
        abort_if($export->expires_at && now()->greaterThan($export->expires_at), 410, 'Ссылка истекла');
        abort_unless(Storage::disk($export->disk)->exists($export->path), 404);

        // S3 вариант:
        // return redirect(Storage::disk($export->disk)->temporaryUrl($export->path, now()->addMinutes(15)));

        // Локальный/общий вариант:
        return Storage::disk($export->disk)->download($export->path, $export->filename);
    }
}
