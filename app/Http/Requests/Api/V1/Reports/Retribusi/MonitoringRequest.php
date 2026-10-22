<?php

namespace App\Http\Requests\Api\V1\Reports\Retribusi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MonitoringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:'.(now()->year + 1)],
            'month' => ['nullable', 'regex:/^(0[1-9]|1[0-2])$/'],
            'source' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
            'mode' => ['nullable', Rule::in(['compatibility', 'corrected', 'parity'])],
            'search' => ['nullable', 'string', 'max:200'],
            'sort_by' => ['nullable', Rule::in([
                'number', 'source_record_id', 'transaction_date', 'taxpayer_number', 'document_number',
                'opd_id', 'opd_name', 'tax_period', 'assessment_amount', 'payment_amount', 'payment_date',
                'payment_status', 'status_code', 'payment_match_status', 'retribution_type_id', 'retribution_type_name',
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

    protected function prepareForValidation(): void
    {
        if ($this->input('mode') === 'parity') {
            $this->merge(['mode' => 'compatibility']);
        }
    }
}
