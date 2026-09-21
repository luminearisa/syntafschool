<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pengajuan pembayaran tagihan dari portal (atau diinput petugas).
 *
 * Manual  : wajib melampirkan bukti bayar agar bisa diverifikasi panitia.
 * Midtrans: nominal boleh dikosongkan (memakai sisa tagihan) dan tidak butuh bukti.
 */
class PpdbPaymentStoreRequest extends FormRequest
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
        $method = (string) ($this->input('method') ?? PpdbPayment::METHOD_MANUAL);

        return [
            'method' => ['nullable', 'string', Rule::in(PpdbPayment::METHODS)],
            'amount' => [
                $method === PpdbPayment::METHOD_MIDTRANS ? 'nullable' : 'required',
                'numeric',
                'gt:0',
                'max:999999999999.99',
            ],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'proof' => [
                $method === PpdbPayment::METHOD_MANUAL ? 'required' : 'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:2048',
            ],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.in' => 'Metode pembayaran tidak dikenali.',
            'amount.required' => 'Nominal pembayaran wajib diisi.',
            'amount.gt' => 'Nominal pembayaran harus lebih besar dari nol.',
            'payment_method_id.exists' => 'Metode pembayaran terpilih tidak ada di master data.',
            'proof.required' => 'Bukti pembayaran wajib diunggah untuk pembayaran manual.',
            'proof.mimes' => 'Bukti pembayaran harus berupa berkas JPG, PNG, atau PDF.',
            'proof.max' => 'Bukti pembayaran maksimal 2 MB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'method' => 'metode',
            'amount' => 'nominal',
            'payment_method_id' => 'metode pembayaran',
            'proof' => 'bukti pembayaran',
            'reference' => 'referensi transfer',
        ];
    }

    /** Metode yang dipakai pada permintaan ini. */
    public function paymentMethod(): string
    {
        return (string) ($this->input('method') ?? PpdbPayment::METHOD_MANUAL);
    }
}
