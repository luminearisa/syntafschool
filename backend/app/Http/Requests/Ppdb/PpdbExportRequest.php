<?php

namespace App\Http\Requests\Ppdb;

use App\Services\Ppdb\PpdbReportService;
use Illuminate\Validation\Rule;

/**
 * Filter + jenis data untuk ekspor CSV PPDB (`GET ppdb/export`).
 *
 * `type` wajib dan dibatasi daftar putih; jenis tak dikenal menghasilkan 422
 * alih-alih 500.
 */
class PpdbExportRequest extends PpdbReportFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'type' => ['required', 'string', Rule::in(PpdbReportService::EXPORT_TYPES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return parent::attributes() + ['type' => 'jenis ekspor'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Pilih jenis ekspor yang diinginkan.',
            'type.in' => 'Jenis ekspor harus salah satu dari: '.implode(', ', PpdbReportService::EXPORT_TYPES).'.',
        ];
    }

    public function type(): string
    {
        return (string) $this->input('type');
    }
}
