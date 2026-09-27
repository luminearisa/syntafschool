<?php

namespace App\Http\Requests\Finance;

use App\Services\Finance\FinancePaymentPlanService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Membuat rencana pembayaran / cicilan atas satu invoice (§7).
 *
 * Yang diminta hanya bentuk jadwalnya: berapa bagian tagihan yang dicicil,
 * dipecah jadi berapa, dan berjarak seberapa. Yang TIDAK bisa dikirim dari
 * sini: status angsuran, nominal terbayar, nomor plan. Semuanya hasil hitung
 * service dari pelunasan invoice — kalau tidak, siapa pun yang bisa POST akan
 * bisa membuat cicilan terlihat lunas.
 */
class FinancePaymentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer', 'exists:finance_invoices,id'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'installment_count' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date'],
            'interval' => ['nullable', 'string', Rule::in(array_keys(FinancePaymentPlanService::INTERVALS))],
            'interval_every' => ['nullable', 'integer', 'min:1', 'max:12'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'invoice_id' => 'invoice',
            'total_amount' => 'nilai yang dicicil',
            'installment_count' => 'jumlah angsuran',
            'start_date' => 'tanggal mulai',
            'interval_every' => 'kelipatan interval',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'installment_count.required' => 'Tentukan berapa bagian cicilan yang diminta (mis. 3 untuk contoh Rp6.000.000 → 3 × Rp2.000.000).',
            'start_date.required' => 'Tentukan tanggal jatuh tempo angsuran pertama.',
        ];
    }
}
