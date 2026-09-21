<?php

namespace App\Http\Requests\Ppdb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pencatatan pengembalian dana (refund) tagihan PPDB.
 *
 * Alasan wajib dan tidak boleh bawa nominal sendiri: jumlah yang kembali selalu
 * sebesar pembayaran sah yang tercatat pada tagihan
 * (`PpdbInvoiceService::refund()`), supaya petugas tidak bisa mengetik angka
 * yang tidak pernah masuk ke rekening sekolah.
 */
class PpdbInvoiceRefundRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->isMethod('post')) {
            $this->merge([
                'reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason'),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan pengembalian dana wajib diisi.',
            'reason.min' => 'Alasan pengembalian dana terlalu singkat, minimal 5 karakter.',
            'reason.max' => 'Alasan pengembalian dana maksimal 500 karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'alasan pengembalian dana',
        ];
    }
}
