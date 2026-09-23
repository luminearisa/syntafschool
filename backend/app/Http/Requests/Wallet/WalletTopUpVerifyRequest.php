<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Persetujuan top up oleh petugas berpermission `wallet.topup.verify`.
 *
 * `paid_at` boleh disetel karena transfer yang baru dicocokkan hari ini sering
 * terjadi pada hari berbeda; bila kosong, service memakai waktu pembayaran yang
 * sudah tercatat pada permintaan (atau waktu sekarang). Catatan petugas ikut
 * disimpan sebagai metadata baris ledger, bukan sebagai kolom terpisah — keputusan
 * pembukuan dan alasannya harus tetap berada pada baris yang sama.
 */
class WalletTopUpVerifyRequest extends FormRequest
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
            'note.max' => 'Catatan verifikasi maksimal 500 karakter.',
            'paid_at.date' => 'Tanggal pembayaran harus berupa tanggal yang sah.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'note' => 'catatan verifikasi',
            'paid_at' => 'tanggal bayar',
        ];
    }
}
