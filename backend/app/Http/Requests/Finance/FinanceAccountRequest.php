<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat/perbarui akun COA (§5, §6). `code` unik global (baris soft-deleted tetap
 * dihitung karena index database tidak mengenal soft delete). `normal_balance`
 * diturunkan dari `type` bila tidak dikirim, agar konsisten dengan §47.
 */
class FinanceAccountRequest extends FormRequest
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

        // Saldo normal mengikuti tipe akun bila tidak dinyatakan eksplisit.
        $type = $this->input('type');
        if (! $this->filled('normal_balance') && is_string($type) && isset(FinanceAccount::NORMAL_BALANCE_FOR_TYPE[$type])) {
            $this->merge(['normal_balance' => FinanceAccount::NORMAL_BALANCE_FOR_TYPE[$type]]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var FinanceAccount|null $current */
        $current = $this->route('account');

        return [
            'code' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string', 'max:20', 'regex:/^[A-Z0-9\-_.]+$/',
                Rule::unique(FinanceAccount::class, 'code')
                    ->where(fn ($query) => $query->whereNull('deleted_at'))
                    ->ignore($current?->id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:150'],
            'type' => [$this->isMethod('post') ? 'required' : 'nullable', Rule::in(FinanceAccount::TYPES)],
            'parent_id' => ['nullable', 'integer', Rule::exists('finance_accounts', 'id')->whereNull('deleted_at')],
            'normal_balance' => ['nullable', Rule::in(['debit', 'credit'])],
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
            'code' => 'kode akun',
            'name' => 'nama akun',
            'type' => 'tipe akun',
            'parent_id' => 'akun induk',
            'normal_balance' => 'saldo normal',
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
            'code.required' => 'Kode akun wajib diisi, misalnya 4100.',
            'code.regex' => 'Kode akun hanya boleh berisi huruf, angka, tanda hubung, titik, atau garis bawah.',
            'code.unique' => 'Kode akun ini sudah dipakai.',
            'type.required' => 'Tipe akun wajib dipilih.',
            'type.in' => 'Tipe akun harus salah satu dari: asset, liability, equity, revenue, expense.',
            'parent_id.exists' => 'Akun induk tidak ditemukan.',
        ];
    }
}
