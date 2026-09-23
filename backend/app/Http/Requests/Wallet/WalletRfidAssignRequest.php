<?php

namespace App\Http\Requests\Wallet;

use App\Models\WalletRfidCard;
use App\Services\Wallet\WalletRfidService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tautkan satu kartu RFID ke wallet (§7).
 *
 * UID dibersihkan di sini (spasi dihapus, huruf kapital) SEBELUM aturan unik
 * bekerja: kalau pembersihan dilakukan di service, `04:a1:b2` dan `04:A1:B2`
 * lolos validasi lalu bertabrakan di constraint unik database — pesan 500,
 * bukan 422 yang bisa dibaca petugas.
 *
 * Keunikan UID bersifat GLOBAL, bukan per wallet: satu kartu fisik tidak boleh
 * menunjuk dua rekening (§6). `WalletRfidService` memeriksa ulang hal yang sama
 * untuk penelepon non-HTTP.
 */
class WalletRfidAssignRequest extends FormRequest
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
            'uid' => 'UID kartu',
            'serial_number' => 'nomor seri',
            'issuing_batch' => 'batch pencetakan',
            'notes' => 'catatan kartu',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'uid.required' => 'UID kartu wajib diisi. Tempelkan hasil pemindaian atau ketuk nomor di badan kartu.',
            'uid.max' => 'UID kartu maksimal :max karakter.',
            'uid.regex' => 'UID kartu hanya boleh berisi huruf, angka, tanda titik dua, dan tanda hubung.',
            'uid.unique' => 'UID kartu ini sudah terdaftar pada wallet lain. Satu kartu fisik hanya boleh menunjuk satu wallet.',
            'serial_number.max' => 'Nomor seri maksimal :max karakter.',
            'issuing_batch.max' => 'Batch pencetakan maksimal :max karakter.',
            'notes.max' => 'Catatan kartu maksimal :max karakter.',
        ];
    }
}
