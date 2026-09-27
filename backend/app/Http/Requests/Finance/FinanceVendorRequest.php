<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceVendor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat/perbarui vendor (§34) — penerima pembayaran pengeluaran/pembelian.
 */
class FinanceVendorRequest extends FormRequest
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
        /** @var FinanceVendor|null $current */
        $current = $this->route('vendor');

        return [
            'code' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string', 'max:30', 'regex:/^[A-Z0-9\-_.]+$/',
                Rule::unique(FinanceVendor::class, 'code')
                    ->where(fn ($query) => $query->whereNull('deleted_at'))
                    ->ignore($current?->id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:150'],
            'vendor_type' => ['nullable', Rule::in(FinanceVendor::TYPES)],
            'tax_id' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'account_number' => ['nullable', 'string', 'max:60'],
            'account_name' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([FinanceVendor::STATUS_ACTIVE, FinanceVendor::STATUS_INACTIVE])],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode vendor',
            'name' => 'nama vendor',
            'vendor_type' => 'jenis vendor',
            'tax_id' => 'NPWP',
            'address' => 'alamat',
            'phone' => 'telepon',
            'email' => 'email',
            'bank_name' => 'nama bank',
            'account_number' => 'nomor rekening',
            'account_name' => 'nama pemilik rekening',
            'status' => 'status',
            'is_active' => 'flag aktif',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Kode vendor wajib diisi, misalnya VDR-001.',
            'code.unique' => 'Kode vendor ini sudah dipakai.',
            'name.required' => 'Nama vendor wajib diisi.',
            'vendor_type.in' => 'Jenis vendor harus salah satu dari: company, individual, supplier, service.',
            'email.email' => 'Format email tidak valid.',
        ];
    }
}
