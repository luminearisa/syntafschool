<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Perubahan status wallet: blokir, buka blokir, dan tutup permanen (§21, §2).
 *
 * Alasan WAJIB untuk memblokir dan menutup: keduanya mengambil uang orang lain
 * dari circulation tanpa mengubah saldonya, jadi harus ada dokumen "siapa dan
 * kenapa" di belakangnya. Membuka blokir mengembalikan keadaan semula sehingga
 * alasannya hanya anjuran (petugas boleh mencatatnya).
 *
 * Satu permintaan untuk tiga rute; mana yang menuntut alasan dibaca dari nama
 * rute, bukan dari kolom tersembunyi di payload.
 */
class WalletBlockRequest extends FormRequest
{
    /** Otorisasi ditegakkan per rute lewat `permission:wallet.block|unblock|close`. */
    public function authorize(): bool
    {
        return true;
    }

    public function isUnblock(): bool
    {
        return $this->routeIs('wallet.wallets.unblock');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => [$this->isUnblock() ? 'nullable' : 'required', 'string', 'min:3', 'max:500'],
            // §21: "RFID terkait dapat ikut diblokir sesuai policy" — keputusannya
            // tinggal di petugas yang memegang kartu fisiknya.
            'block_rfid_cards' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Kolom yang tidak berlaku pada rute ini, dibuang agar service tidak salah
     * menafsirkan permintaan buka-blokir sebagai permintaan memblokir kartu.
     *
     * @return array<string, mixed>
     */
    protected function prepareForValidation(): void
    {
        if ($this->isUnblock()) {
            $this->request->remove('block_rfid_cards');
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'alasan',
            'block_rfid_cards' => 'ikut blokir kartu RFID',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan wajib diisi supaya tindakan atas wallet orang lain dapat dipertanggungjawabkan.',
            'reason.min' => 'Alasan terlalu singkat, minimal :min karakter.',
            'reason.max' => 'Alasan maksimal :max karakter.',
            'block_rfid_cards.boolean' => 'Ikut blokir kartu RFID hanya boleh ya atau tidak.',
        ];
    }
}
