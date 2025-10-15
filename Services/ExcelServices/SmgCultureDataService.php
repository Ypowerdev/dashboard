<?php

namespace App\Services\ExcelServices;

use App\DTO\SmgCultureDTO;


class SmgCultureDataService
{
    public function getCultureData(): array
    {
        $results = collect();

        return $results->map(function ($item) {
            return SmgCultureDTO::fromArray((array)$item);
        })->toArray();
    }
}
