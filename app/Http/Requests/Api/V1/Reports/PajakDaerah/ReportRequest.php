<?php

namespace App\Http\Requests\Api\V1\Reports\PajakDaerah;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isRecap = $this->route('reportType') === 'rekap-ketetapan-per-wp';

        $sortFields = $isRecap
            ? ['number', 'taxpayer_number', 'taxpayer_name', 'address', 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', 'annual_total']
            : ['number', 'document_number', 'taxpayer_number', 'taxpayer_name', 'address', 'tax_period', 'assessment_date', 'payment_date', 'due_date', 'principal_amount', 'penalty_amount', 'total_amount', 'payment_status', 'payment_code'];

        return ['year' => ['required', 'integer', 'min:2000', 'max:'.(now()->year + 1)], 'month' => [Rule::prohibitedIf($isRecap), 'nullable', 'regex:/^(0[1-9]|1[0-2])$/'], 'mode' => [Rule::prohibitedIf($isRecap), 'nullable', Rule::in(['compatibility', 'corrected', 'parity'])], 'as_of_date' => [Rule::prohibitedIf($isRecap), 'nullable', 'date_format:Y-m-d'], 'search' => ['nullable', 'string', 'max:200'], 'sort_by' => ['nullable', Rule::in($sortFields)], 'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:1000']];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('mode') === 'parity') {
            $this->merge(['mode' => 'compatibility']);
        }
    }
}
