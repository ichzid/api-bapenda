<?php

namespace App\Http\Requests\Api\V1\Reports\Esptpd;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MonitoringMakanMinumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $columns = [
            'no', 'tanggal_input', 'no_npwpd', 'no_sptpd', 'nama_instansi',
            'alamat_instansi', 'nama_usaha', 'alamat_usaha', 'masa_pajak',
            'nilai_pajak', 'status_bayar', 'tanggal_bayar', 'nomor_sts',
        ];

        return [
            'year' => ['required', 'integer', 'min:2000', 'max:'.(now()->year + 1)],
            'month' => ['nullable', 'regex:/^(0[1-9]|1[0-2])$/'],
            'mode' => ['nullable', Rule::in(['compatibility', 'corrected', 'parity'])],
            'search' => ['nullable', 'string', 'max:200'],
            'sort_by' => ['nullable', Rule::in($columns)],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'id_user' => ['prohibited'],
            'id_opd' => ['prohibited'],
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
