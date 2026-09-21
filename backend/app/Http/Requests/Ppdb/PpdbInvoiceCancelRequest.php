<?php

namespace App\Http\Requests\Ppdb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pembatalan tagihan oleh panitia.
 *
 * Alasan wajib: tagihan yang dibatalkan menghapus piutang dan menjadi riwayat
 * pendaftaran, jadi keputusan itu harus terbaca oleh siapa pun yang memeriksa
 * bulan berikutnya (dan orang tua menerima alasannya lewat notifikasi).
 */
class PpdbInvoiceCancelRequest extends FormRequest
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
            'reason.required' => 'Alasan pembatalan wajib diisi.',
            'reason.min' => 'Alasan pembatalan terlalu singkat, minimal 5 karakter.',
            'reason.max' => 'Alasan pembatalan maksimal 500 karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'alasan pembatalan',
        ];
    }
}
