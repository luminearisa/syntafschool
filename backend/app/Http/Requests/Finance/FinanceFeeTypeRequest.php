<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceFeeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat/perbarui jenis tagihan (§10). `account_id` memetakan ke akun pendapatan
 * COA dan `receivable_account_id` ke akun piutangnya (§20) — keduanya harus
 * akun daun agar ledger bisa mempostingnya.
 */
class FinanceFeeTypeRequest extends FormRequest
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
        /** @var FinanceFeeType|null $current */
        $current = $this->route('feeType');

        return [
            'code' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string', 'max:30', 'regex:/^[A-Z0-9\-_.]+$/',
                Rule::unique(FinanceFeeType::class, 'code')
                    ->where(fn ($query) => $query->whereNull('deleted_at'))
                    ->ignore($current?->id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'account_id' => ['nullable', 'integer', Rule::exists('finance_accounts', 'id')->whereNull('deleted_at')],
            'receivable_account_id' => ['nullable', 'integer', Rule::exists('finance_accounts', 'id')->whereNull('deleted_at')],
            'default_amount' => ['nullable', 'numeric', 'min:0'],
            'frequency' => ['nullable', Rule::in(FinanceFeeType::FREQUENCIES)],
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
            'code' => 'kode jenis tagihan',
            'name' => 'nama jenis tagihan',
            'category' => 'kategori',
            'account_id' => 'akun pendapatan',
            'receivable_account_id' => 'akun piutang',
            'default_amount' => 'nominal default',
            'frequency' => 'frekuensi',
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
            'code.required' => 'Kode jenis tagihan wajib diisi, misalnya SPP.',
            'code.regex' => 'Kode hanya boleh berisi huruf, angka, tanda hubung, titik, atau garis bawah.',
            'code.unique' => 'Kode jenis tagihan ini sudah dipakai.',
            'name.required' => 'Nama jenis tagihan wajib diisi.',
            'account_id.exists' => 'Akun pendapatan tidak ditemukan.',
            'receivable_account_id.exists' => 'Akun piutang tidak ditemukan.',
            'frequency.in' => 'Frekuensi harus salah satu dari: once, monthly, semesterly, yearly, custom.',
        ];
    }
}
