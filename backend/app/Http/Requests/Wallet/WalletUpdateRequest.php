<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /wallet/wallets/{wallet} — hanya catatan internal petugas.
 *
 * Nomor rekening, pemilik, dan saldo tidak dapat diubah dari jalur mana pun:
 * nomor itu abadi (§5), pemilik dipindah berarti uang orang lain dipindah, dan
 * saldo hanyalah akibat ledger (§8). Karena itu permintaan ini hanya mengenali
 * satu kolom, dan menolak permintaan kosong agar tidak ada PATCH yang tampak
 * berhasil padahal tidak mengubah apa pun.
 */
class WalletUpdateRequest extends FormRequest
{
    /** Otorisasi ditegakkan per rute lewat `permission:wallet.update`. */
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
            'notes' => ['present', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'notes' => 'catatan internal',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notes.present' => 'Tidak ada perubahan yang dikirim. Kolom catatan internal wajib disertakan.',
            'notes.string' => 'Catatan internal harus berupa teks.',
            'notes.max' => 'Catatan internal maksimal :max karakter.',
        ];
    }
}
