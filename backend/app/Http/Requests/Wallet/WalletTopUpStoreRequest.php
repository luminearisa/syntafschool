<?php

namespace App\Http\Requests\Wallet;

use App\Models\WalletTopUp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pengajuan top up — gateway maupun transfer manual.
 *
 * Nominal TIDAK divalidasi rentangnya di sini. Batas minimal/maksimal hidup di
 * pengaturan modul (`wallet.topup_min_amount` / `topup_max_amount`) dan berubah
 * tanpa deploy, jadi angkanya baru diketahui saat service membaca
 * `WalletSettingsService`. Menuliskannya di sini akan membuatnya membeku pada
 * angka hari ini dan diam-diam menandingi aturan service.
 *
 * Manual   : wajib melampirkan bukti transfer supaya petugas punya sesuatu untuk
 *            dicocokkan dengan rekening sekolah.
 * Gateway  : tidak butuh bukti (pembayaran terjadi di sisi provider). `provider`
 *            boleh dikosongkan — manajer gateway memakai penyedia pertama yang
 *            benar-benar terpasang kredensialnya.
 */
class WalletTopUpStoreRequest extends FormRequest
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
        $method = $this->topUpMethod();

        return [
            'method' => ['nullable', 'string', Rule::in(WalletTopUp::METHODS)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'provider' => ['nullable', 'string', 'max:30'],
            'proof' => [
                $method === WalletTopUp::METHOD_MANUAL ? 'required' : 'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:2048',
            ],
            'bank_id' => ['nullable', 'integer', 'exists:banks,id'],
            'source_account_number' => ['nullable', 'string', 'max:50'],
            'source_account_name' => ['nullable', 'string', 'max:100'],
            'transferred_on' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'method' => is_string($this->input('method')) ? trim($this->input('method')) : $this->input('method'),
            'provider' => is_string($this->input('provider')) ? trim($this->input('provider')) : $this->input('provider'),
            'source_account_number' => is_string($this->input('source_account_number'))
                ? trim($this->input('source_account_number'))
                : $this->input('source_account_number'),
            'source_account_name' => is_string($this->input('source_account_name'))
                ? trim($this->input('source_account_name'))
                : $this->input('source_account_name'),
        ], static fn (mixed $value): bool => $value !== null));

        // Nominal sering dikirim sebagai string, dan format Indonesia memakai titik
        // sebagai pemisah ribuan ("1.000.000"). Yang tetap tidak bisa dibaca angka
        // dibiarkan apa adanya agar validasi `numeric` memberi pesan yang jujur.
        $amount = $this->input('amount');

        if (is_string($amount)) {
            $cleaned = str_replace(['.', ','], ['', ','], trim($amount));

            if (is_numeric($cleaned)) {
                $this->merge(['amount' => (float) $cleaned]);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.in' => 'Metode top up tidak dikenali. Gunakan gateway atau manual.',
            'amount.required' => 'Nominal top up wajib diisi.',
            'amount.numeric' => 'Nominal top up harus berupa angka.',
            'amount.gt' => 'Nominal top up harus lebih besar dari nol.',
            'amount.max' => 'Nominal top up terlalu besar untuk dicatat sistem ini.',
            'provider.max' => 'Nama provider terlalu panjang.',
            'proof.required' => 'Bukti transfer wajib diunggah untuk top up manual.',
            'proof.mimes' => 'Bukti transfer harus berupa berkas JPG, PNG, atau PDF.',
            'proof.max' => 'Bukti transfer maksimal 2 MB.',
            'bank_id.exists' => 'Bank asal transfer tidak ada di master data.',
            'source_account_number.max' => 'Nomor rekening asal maksimal 50 karakter.',
            'source_account_name.max' => 'Nama pemilik rekening asal maksimal 100 karakter.',
            'transferred_on.date' => 'Tanggal transfer harus berupa tanggal yang sah.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'method' => 'metode top up',
            'amount' => 'nominal',
            'provider' => 'payment gateway',
            'proof' => 'bukti transfer',
            'bank_id' => 'bank asal',
            'source_account_number' => 'nomor rekening asal',
            'source_account_name' => 'nama rekening asal',
            'transferred_on' => 'tanggal transfer',
        ];
    }

    /** Metode yang dipakai pada permintaan ini (default: manual, sesuai kolom). */
    public function topUpMethod(): string
    {
        $method = $this->input('method');

        return is_string($method) && $method !== '' ? $method : WalletTopUp::METHOD_MANUAL;
    }
}
