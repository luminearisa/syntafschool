<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceFeePackage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat/perbarui paket tagihan (§11, §12) beserta baris itemnya.
 *
 * `items` diterima sebagai array bersarang dan disinkronkan controller (bukan
 * di sini) supaya satu paket + itemnya tersimpan atomik. Segmen (program/kelas/
 * boarding/tahun ajaran) menentukan paket mana yang cocok untuk seorang santri.
 */
class FinanceFeePackageRequest extends FormRequest
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
        /** @var FinanceFeePackage|null $current */
        $current = $this->route('feePackage');

        return [
            'code' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string', 'max:30', 'regex:/^[A-Z0-9\-_.]+$/',
                Rule::unique(FinanceFeePackage::class, 'code')
                    ->where(fn ($query) => $query->whereNull('deleted_at'))
                    ->ignore($current?->id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:150'],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')],
            'level' => ['nullable', 'string', 'max:30'],
            'class_id' => ['nullable', 'integer', Rule::exists('school_classes', 'id')],
            'boarding_type' => ['nullable', Rule::in(FinanceFeePackage::BOARDING_TYPES)],
            'academic_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.fee_type_id' => ['required_with:items', 'integer', Rule::exists('finance_fee_types', 'id')->whereNull('deleted_at')],
            'items.*.amount' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.frequency' => ['nullable', Rule::in(\App\Models\FinanceFeeType::FREQUENCIES)],
            'items.*.is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode paket',
            'name' => 'nama paket',
            'program_id' => 'program',
            'level' => 'jenjang',
            'class_id' => 'kelas',
            'boarding_type' => 'jenis asrama',
            'academic_year' => 'tahun ajaran',
            'is_active' => 'flag aktif',
            'items' => 'baris tagihan',
            'items.*.fee_type_id' => 'jenis tagihan',
            'items.*.amount' => 'nominal',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Kode paket wajib diisi, misalnya PKT-SMP-BOARDING.',
            'code.unique' => 'Kode paket ini sudah dipakai.',
            'name.required' => 'Nama paket wajib diisi.',
            'boarding_type.in' => 'Jenis asrama harus salah satu dari: boarding, non_boarding, all.',
            'items.*.fee_type_id.required_with' => 'Setiap baris wajib memilih jenis tagihan.',
            'items.*.amount.required_with' => 'Setiap baris wajib mengisi nominal.',
        ];
    }
}
