<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
//todo: переименовать
/**
 * Запрос передающий настройки фильтра
 */
class FiltersDataHomePageRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filter_data' => 'required',
            'filter_data.object_or_rating' => 'nullable',
            'filter_data.aip_flag' => 'required',
            'filter_data.days_before' => 'nullable',
            'filter_data.aip_years' => 'nullable|array',
            'filter_data.aip_id' => 'nullable|integer',
            'filter_data.podved_inns' => 'nullable|array',
            'filter_data.oiv_id' => 'nullable|array',

            'filter_data.lvl1_id' => 'nullable|numeric',
            'filter_data.lvl2_id' => 'nullable|numeric',
            'filter_data.lvl3_id' => 'nullable|numeric',
            'filter_data.lvl4_ids' => 'nullable|array',
            'filter_data.is_object_directive' => 'nullable|boolean',
        ];
    }
}
