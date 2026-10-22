<?php

namespace App\Http\Requests\Api\V1\Reports\Retribusi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RekapOpdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:'.(now()->year + 1)],
            'month' => ['prohibited'],
            'search' => ['nullable', 'string', 'max:200'],
            'sort_by' => ['nullable', Rule::in([
                'opd_id', 'opd_name', 'january', 'february', 'march', 'april', 'may', 'june',
                'july', 'august', 'september', 'october', 'november', 'december', 'annual_total',
            ])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'opd_id' => ['prohibited'],
            'id_opd' => ['prohibited'],
            'id_user' => ['prohibited'],
            'akses' => ['prohibited'],
        ];
    }
}
