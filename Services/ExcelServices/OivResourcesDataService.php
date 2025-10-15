<?php

namespace App\Services\ExcelServices;

use App\DTO\OivResourcesDTO;


class OivResourcesDataService
{
    public function getResourcesData(): array
    {
        $results = collect();

        return $results->map(function ($item) {
            return OivResourcesDTO::fromArray((array)$item);
        })->toArray();
    }
}
