<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;

//todo: к удалению как контроллера, так и сборщика
class DebugController
{
// app/Http/Controllers/DebugController.php
    public function viewDebugFiles()
    {
        $files = collect(File::files(storage_path('debugbar')))
            ->sortByDesc(fn($file) => $file->getMTime());

        return view('debug-viewer', compact('files'));
    }
}
