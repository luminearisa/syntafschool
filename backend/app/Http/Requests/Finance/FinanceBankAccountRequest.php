<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceBankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat/perbarui rekening bank (§40). `bank_id` menunjuk master `banks` existing
 * (§71 — tidak membuat master bank baru); `finance_account_id` adalah akun COA
 * bank (asset, daun) yang saldonya digerakkan ledger engine.
 *
 * Saldo awal diset lewat endpoint opening-balance khusus (§50), bukan di sini.
 */
class FinanceBankAccountRequest extends FormRequest
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
        /** @var FinanceBankAccount|null $current */
        $current = $this->route('bankAccount');

        return [
            'code' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string', 'max:30', 'regex:/^[A-Z0-9\-_.]+$/',
                Rule::unique(FinanceBankAccount::class, 'code')
                    ->where(fn ($query) => $query->whereNull('deleted_at'))
                    ->ignore($current?->id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:120'],
            'bank_id' => ['nullable', 'integer', Rule::exists('banks', 'id')],
            'account_number' => ['nullable', 'string', 'max:60'],
            'account_name' => ['nullable', 'string', 'max:120'],
            'branch' => ['nullable', 'string', 'max:120'],
            'account_type' => ['nullable', Rule::in(FinanceBankAccount::TYPES)],
            'finance_account_id' => [$this->isMethod('post') ? 'required' : 'nullable', 'integer', Rule::exists('finance_accounts', 'id')->whereNull('deleted_at')],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('finance_cost_centers', 'id')->whereNull('deleted_at')],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode rekening',
            'name' => 'nama rekening',
            'bank_id' => 'bank',
            'account_number' => 'nomor rekening',
            'account_name' => 'nama pemilik rekening',
            'branch' => 'cabang',
            'account_type' => 'jenis rekening',
            'finance_account_id' => 'akun COA',
            'cost_center_id' => 'cost center',
            'is_active' => 'flag aktif',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Kode rekening wajib diisi, misalnya BANK-OPS.',
            'code.unique' => 'Kode rekening ini sudah dipakai.',
            'name.required' => 'Nama rekening wajib diisi.',
            'bank_id.exists' => 'Bank tidak ditemukan pada master.',
            'account_type.in' => 'Jenis rekening harus salah satu dari: operational, payroll, savings, other.',
            'finance_account_id.required' => 'Akun COA bank wajib dipilih.',
            'finance_account_id.exists' => 'Akun COA tidak ditemukan.',
        ];
    }
}
