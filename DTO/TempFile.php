<?php

namespace App\DTO;

class TempFile
{
    public function __construct(
        public string $disk,
        public string $relativePath, // например uploads-tmp/ab/uuid.jpg
        public string $absolutePath, // полный путь на FS
        public string $originalName,
        public string $extension,
        public string $mime,
        public int    $size,
        public string $sha256,
    ) {}
}
