<?php

namespace App\Http\Requests\Wallet;

use App\Models\WalletRfidCard;
use App\Services\Wallet\WalletRfidService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ubah status pelayanan satu kartu RFID: aktif, nonaktif, blokir, atau hilang (§6).
 *
 * `replaced` tidak ada dalam daftar pilihan: status itu akibat dari penggantian
 * kartu (`WalletRfidService::replace()`), bukan tombol. Bila boleh diminta
 * langsung, kartu bisa ditandai "sudah diganti" tanpa penggantinya dan riwayat
 * kartu putus.
 */
class WalletRfidStatusRequest extends FormRequest
{
    /** Otorisasi ditegakkan per rute lewat `permission:wallet.rfid.manage`. */
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
            'status' => ['required', 'string', Rule::in(WalletRfidService::SETTABLE_STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => 'status kartu',
            'note' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Status kartu wajib dipilih.',
            'status.in' => 'Status kartu harus salah satu dari: '
                .implode(', ', WalletRfidService::SETTABLE_STATUSES)
                .'. Status "'.WalletRfidCard::STATUS_REPLACED.'" ditetapkan otomatis saat kartu diganti.',
            'note.max' => 'Catatan maksimal :max karakter.',
        ];
    }
}
