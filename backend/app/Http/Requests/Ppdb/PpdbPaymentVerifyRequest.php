<?php

namespace App\Http\Requests\Ppdb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Persetujuan pembayaran manual oleh petugas.
 *
 * `paid_at` boleh disetel karena transfer yang diverifikasi sering terjadi pada
 * hari berbeda dengan hari penyetoran; bila kosong memakai waktu sekarang.
 */
class PpdbPaymentVerifyRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paid_at.date' => 'Tanggal pembayaran harus berupa tanggal yang sah.',
            'note.max' => 'Catatan verifikasi maksimal 500 karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'note' => 'catatan',
            'paid_at' => 'tanggal bayar',
        ];
    }
}
