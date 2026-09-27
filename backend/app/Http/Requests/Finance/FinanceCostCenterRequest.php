<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceCostCenter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat/perbarui cost center (§7). `code` unik global (baris soft-deleted tetap
 * dihitung karena index database tidak mengenal soft delete).
 */
class FinanceCostCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var FinanceCostCenter|null $current */
        $current = $this->route('costCenter');

        return [
            'code' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string', 'max:30', 'regex:/^[A-Z0-9\-_.]+$/',
                Rule::unique(FinanceCostCenter::class, 'code')
                    ->where(fn ($query) => $query->whereNull('deleted_at'))
                    ->ignore($current?->id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', Rule::exists('finance_cost_centers', 'id')->whereNull('deleted_at')],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode cost center',
            'name' => 'nama cost center',
            'parent_id' => 'induk cost center',
            'manager_id' => 'penanggung jawab',
            'is_active' => 'flag aktif',
            'description' => 'deskripsi',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Kode cost center wajib diisi, misalnya CC-SEKOLAH.',
            'code.regex' => 'Kode hanya boleh berisi huruf, angka, tanda hubung, titik, atau garis bawah.',
            'code.unique' => 'Kode cost center ini sudah dipakai.',
            'name.required' => 'Nama cost center wajib diisi.',
            'parent_id.exists' => 'Induk cost center tidak ditemukan.',
            'manager_id.exists' => 'Pengguna penanggung jawab tidak ditemukan.',
        ];
    }
}
