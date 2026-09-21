<?php

namespace App\Http\Requests\Ppdb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filter daftar periode PPDB pada halaman publik.
 *
 * Daftar publik tetap dibatasi pada periode yang boleh dilihat publik
 * (status open/closed) — filter di sini hanya mempersempitnya.
 */
class PpdbPublicPeriodFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:open,closed'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'only_open' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:registration_start,created_at,name,code'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Status periode yang boleh ditampilkan publik hanya open atau closed.',
            'sort.in' => 'Urutan data tidak dikenal.',
            'per_page.max' => 'Jumlah data per halaman maksimal 50.',
        ];
    }
}
