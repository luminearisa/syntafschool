<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceBankStatement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Import rekening koran bank (Phase 4 §4).
 *
 * Yang divalidasi di sini bentuk datanya; keseimbangan `opening + Σ mutasi =
 * closing` dan aturan arah debit/credit diperiksa service karena ia juga
 * menghitung `amount` (credit − debit) — nomor yang diubah-ubah di input tidak
 * boleh masuk ke rekonsiliasi.
 */
class FinanceBankStatementRequest extends FormRequest
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
            'bank_account_id' => ['required', 'integer', Rule::exists('finance_bank_accounts', 'id')->whereNull('deleted_at')],
            'statement_date' => ['required', 'date'],
            'opening_balance' => ['required', 'numeric'],
            'closing_balance' => ['required', 'numeric'],
            'source' => ['nullable', Rule::in(FinanceBankStatement::SOURCES)],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'filename' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:500'],
            'auto_match' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_date' => ['nullable', 'date'],
            'lines.*.reference' => ['nullable', 'string', 'max:100'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.external_id' => ['nullable', 'string', 'max:100'],
            'lines.*.running_balance' => ['nullable', 'numeric'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'bank_account_id' => 'rekening bank',
            'statement_date' => 'tanggal rekening koran',
            'opening_balance' => 'saldo awal',
            'closing_balance' => 'saldo akhir',
            'external_reference' => 'referensi bank',
            'lines' => 'baris rekening koran',
        ];
    }
}
