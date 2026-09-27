<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceBudget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat/perbarui anggaran (§8). Status approval (§9) TIDAK diset lewat request
 * ini — submit/approve/reject lewat endpoint aksi terpisah agar pemisahan
 * pengaju vs penyetuju terjaga di `FinanceBudgetService`.
 *
 * `used_amount` juga tidak boleh diinput: ia realisasi yang di-update otomatis
 * saat expense/PO diposting, bukan angka yang diketik manual.
 */
class FinanceBudgetRequest extends FormRequest
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
        /** @var FinanceBudget|null $current */
        $current = $this->route('budget');

        return [
            'fiscal_year' => [$this->isMethod('post') ? 'required' : 'nullable', 'integer', 'min:2000', 'max:2100'],
            'account_id' => [$this->isMethod('post') ? 'required' : 'nullable', 'integer', Rule::exists('finance_accounts', 'id')->whereNull('deleted_at')],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('finance_cost_centers', 'id')->whereNull('deleted_at')],
            'period' => ['nullable', 'string', 'max:20'],
            'budget_amount' => [$this->isMethod('post') ? 'required' : 'nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'fiscal_year' => 'tahun fiskal',
            'account_id' => 'akun',
            'cost_center_id' => 'cost center',
            'period' => 'periode',
            'budget_amount' => 'nilai anggaran',
            'notes' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fiscal_year.required' => 'Tahun fiskal wajib diisi.',
            'account_id.required' => 'Akun anggaran wajib dipilih.',
            'account_id.exists' => 'Akun tidak ditemukan.',
            'cost_center_id.exists' => 'Cost center tidak ditemukan.',
            'budget_amount.required' => 'Nilai anggaran wajib diisi.',
            'budget_amount.min' => 'Nilai anggaran tidak boleh negatif.',
        ];
    }
}
