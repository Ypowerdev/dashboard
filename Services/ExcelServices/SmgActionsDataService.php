<?php

namespace App\Services\ExcelServices;

use App\DTO\SmgActionsDTO;
use Illuminate\Support\Facades\DB;

class SmgActionsDataService
{
    public function getActionsData(): array
    {
        $results = collect();

        return $results->map(function ($item) {
            return SmgActionsDTO::fromArray((array)$item);
        })->toArray();
    }
}
