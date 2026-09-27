<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Permintaan write-off piutang (§32–§34) dari sisi invoice. Pemakai hanya
 * memilih nominal, tanggal, dan alasan — akun jurnal (Beban Write-Off /
 * Piutang) ditentukan `AccountingPolicyService`, dan nominal dibatasi sisa
 * tagihan oleh service. Tidak ada field akun di sini agar tidak ada jalur
 * untuk mengarahkan jurnal ke akun pilihan pemakai.
 */
class FinanceWriteOffRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'adjustment_date' => ['nullable', 'date'],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('finance_cost_centers', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => 'nominal write-off',
            'reason' => 'alasan write-off',
            'adjustment_date' => 'tanggal write-off',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.min' => 'Alasan write-off minimal :attribute karakter (§33: write-off wajib beralasan).',
        ];
    }
}
