<?php

namespace App\Http\Requests\Api\V1\Reports\Esptpd;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RekapInstansiMakanMinumRequest extends FormRequest
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
            'mode' => ['prohibited'],
            'as_of_date' => ['prohibited'],
            'search' => ['nullable', 'string', 'max:200'],
            'sort_by' => ['nullable', Rule::in([
                'no_npwpd', 'nama_instansi', 'alamat_instansi',
                'januari', 'februari', 'maret', 'april', 'mei', 'juni',
                'juli', 'agustus', 'september', 'oktober', 'november', 'desember', 'total',
            ])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'id_user' => ['prohibited'],
            'id_opd' => ['prohibited'],
            'akses' => ['prohibited'],
        ];
    }
}
