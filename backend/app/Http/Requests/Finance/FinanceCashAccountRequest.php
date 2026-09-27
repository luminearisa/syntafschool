<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceCashAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat/perbarui akun kas (§39). `account_id` harus akun COA bertipe asset
 * (daun) yang saldonya digerakkan ledger engine.
 *
 * `opening_balance`/`current_balance` TIDAK diinput di sini — saldo awal diset
 * lewat endpoint opening-balance khusus yang memposting jurnal double-entry
 * (§50, §61), bukan menimpa kolom langsung.
 */
class FinanceCashAccountRequest extends FormRequest
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
        /** @var FinanceCashAccount|null $current */
        $current = $this->route('cashAccount');

        return [
            'code' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string', 'max:30', 'regex:/^[A-Z0-9\-_.]+$/',
                Rule::unique(FinanceCashAccount::class, 'code')
                    ->where(fn ($query) => $query->whereNull('deleted_at'))
                    ->ignore($current?->id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:120'],
            'account_id' => [$this->isMethod('post') ? 'required' : 'nullable', 'integer', Rule::exists('finance_accounts', 'id')->whereNull('deleted_at')],
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
            'code' => 'kode kas',
            'name' => 'nama kas',
            'account_id' => 'akun COA',
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
            'code.required' => 'Kode kas wajib diisi, misalnya KAS-SEKOLAH.',
            'code.unique' => 'Kode kas ini sudah dipakai.',
            'name.required' => 'Nama kas wajib diisi.',
            'account_id.required' => 'Akun COA wajib dipilih.',
            'account_id.exists' => 'Akun COA tidak ditemukan.',
        ];
    }
}
