<?php

namespace App\Http\Requests\Wallet;

use App\Models\WalletRfidCard;
use App\Services\Wallet\WalletRfidService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ganti kartu RFID: terbitkan UID baru pada wallet yang sama (§6, §7).
 *
 * Permintaan ini sengaja TIDAK mengenal wallet tujuan. Kalau pemanggil boleh
 * menentukan wallet, "ganti kartu" berubah menjadi "pindahkan saldo" — padahal
 * §6 melarangnya: kartu lama berhenti melayani, kartu baru menunjuk rekening
 * yang sama, saldo tidak bergerak, dan wallet baru tidak pernah dibuat.
 */
class WalletRfidReplaceRequest extends FormRequest
{
    /** Otorisasi ditegakkan per rute lewat `permission:wallet.rfid.manage`. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('uid')) {
            $this->request->set('uid', WalletRfidService::normalizeUid((string) $this->input('uid')));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'uid' => [
                'required', 'string', 'max:64',
                'regex:/^[A-Z0-9:\-]+$/',
                Rule::unique(WalletRfidCard::class, 'uid'),
            ],
            // Kenapa kartu lama berhenti: hilang, rusak, atau disita.
            'old_status' => ['sometimes', 'nullable', 'string', Rule::in([
                WalletRfidCard::STATUS_REPLACED, WalletRfidCard::STATUS_LOST, WalletRfidCard::STATUS_BLOCKED,
            ])],
            'serial_number' => ['nullable', 'string', 'max:64'],
            'issuing_batch' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'uid' => 'UID kartu pengganti',
            'old_status' => 'nasib kartu lama',
            'serial_number' => 'nomor seri',
            'issuing_batch' => 'batch pencetakan',
            'notes' => 'catatan penggantian',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'uid.required' => 'UID kartu pengganti wajib diisi.',
            'uid.regex' => 'UID kartu hanya boleh berisi huruf, angka, tanda titik dua, dan tanda hubung.',
            'uid.unique' => 'UID kartu pengganti ini sudah terdaftar pada wallet lain. Satu kartu fisik hanya boleh menunjuk satu wallet.',
            'old_status.in' => 'Nasib kartu lama harus salah satu dari: sudah diganti, hilang, atau diblokir.',
            'notes.max' => 'Catatan penggantian maksimal :max karakter.',
        ];
    }
}
