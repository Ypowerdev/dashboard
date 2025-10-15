<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lvl1_id' => 'nullable|integer',
            'lvl2_id' => 'nullable|integer',
            'lvl3_id' => 'nullable|integer',
            'lvl4_ids' => 'nullable|array',
            'fno_engineering' => 'nullable|boolean',
            'oksStatusArrayName' => 'nullable|string',
            'riskType' => 'nullable|string',
            'violationType' => 'nullable|string',
            'ct_deadline_failure' => 'nullable|boolean',
            'ct_deadline_high_risk' => 'nullable|boolean',
            'objectStatus' => 'nullable|string',
            'searchTEXT' => 'nullable|string',
            'sortType' => 'nullable|string|in:default,date-desc,date-asc',
            'oiv_id' => 'nullable|integer',
            'any_company_id' => 'nullable|integer',
            'contractor_id' => 'nullable|array',
            'aip_flag' => 'nullable|boolean',
            'aip_years' => 'nullable|array',
            'planned_commissioning_directive_date_years' => 'nullable|array',
            'contractSizes' => 'nullable|array',
            'renovation' => 'nullable|boolean',
            'is_object_directive' => 'nullable|boolean',
            'commissioning_years' => 'nullable|array',
            'culture_manufacture' => 'nullable|boolean',
            'currentOksStatusArrayName' => 'nullable',
            'currentViolationType' => 'nullable',
            'currentDeadlineStatus' => 'nullable',
            'currentRiskType' => 'nullable',
            'currentObjectType' => 'nullable',
            'podved_inns' => 'nullable|array',
            'object_list_id' => 'nullable|integer',
        ];
    }

    /**
     * Преобразуем и нормализуем входные данные
     */
    protected function prepareForValidation(): void
    {
        $fieldsToNormalize = [
            'ct_deadline_failure',
            'ct_deadline_high_risk',
            'aip_flag',
            'culture_manufacture',
            'renovation',
            'is_object_directive',
            'fno_engineering',
        ];

        foreach ($fieldsToNormalize as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);

                $normalized = match ($value) {
                    'null' => null,
                    'true' => true,
                    'false' => false,
                    default => $value,
                };

                $this->merge([$field => $normalized]);
            }
        }
    }

}
