<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat adjustment / koreksi (§51). `account_id` = COA yang dikoreksi,
 * `contra_account_id` = penyeimbang (service menolak bila sama). Opsional
 * `cash_account_id`/`bank_account_id` bila koreksi menyasar saldo konkret;
 * kecocokan COA-nya dengan account_id divalidasi service. `submit=true`
 * langsung mengajukan untuk approval.
 *
 * `kind=write_off` (§33) membalik aturannya: `invoice_id` wajib dan akun
 * debit/kredit justru TIDAK boleh dikirim — pemetaan akun adalah kebijakan
 * akuntansi, dan service menimpanya dari settings.
 */
class FinanceAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isWriteOff = $this->input('kind') === FinanceAdjustment::KIND_WRITE_OFF;

        return [
            'kind' => ['nullable', Rule::in(FinanceAdjustment::KINDS)],
            'invoice_id' => [
                $isWriteOff ? 'required' : 'nullable',
                'integer',
                // Tanpa `whereNull('deleted_at')`: invoice tidak pernah soft-delete
                // (§33 — riwayat tagihan dibiar berdiri, hanya sisa yang bergerak).
                Rule::exists('finance_invoices', 'id'),
            ],
            'account_id' => [
                $isWriteOff ? 'prohibited' : 'required',
                'integer',
                Rule::exists('finance_accounts', 'id')->whereNull('deleted_at'),
            ],
            'contra_account_id' => [
                $isWriteOff ? 'prohibited' : 'required',
                'integer',
                'different:account_id',
                Rule::exists('finance_accounts', 'id')->whereNull('deleted_at'),
            ],
            'direction' => [
                $isWriteOff ? 'prohibited' : 'required',
                Rule::in([FinanceAdjustment::DIRECTION_INCREASE, FinanceAdjustment::DIRECTION_DECREASE]),
            ],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'adjustment_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:500'],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('finance_cost_centers', 'id')->whereNull('deleted_at')],
            'cash_account_id' => [$isWriteOff ? 'prohibited' : 'nullable', 'integer', Rule::exists('finance_cash_accounts', 'id')->whereNull('deleted_at')],
            'bank_account_id' => [$isWriteOff ? 'prohibited' : 'nullable', 'integer', Rule::exists('finance_bank_accounts', 'id')->whereNull('deleted_at')],
            'submit' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kind' => 'jenis adjustment',
            'invoice_id' => 'invoice',
            'account_id' => 'akun yang dikoreksi',
            'contra_account_id' => 'akun lawan',
            'direction' => 'arah adjustment',
            'amount' => 'nominal',
            'reason' => 'alasan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'invoice_id.required' => 'Write-off piutang wajib menunjuk invoice yang akan dihapus sisa tagihannya.',
            'account_id.prohibited' => 'Akun jurnal write-off diambil dari kebijakan akuntansi, tidak dikirim dari sini.',
            'contra_account_id.prohibited' => 'Akun lawan write-off ditentukan kebijakan (beban write-off vs piutang).',
            'direction.prohibited' => 'Arah write-off selalu menambah beban; tidak perlu dikirim.',
        ];
    }
}
