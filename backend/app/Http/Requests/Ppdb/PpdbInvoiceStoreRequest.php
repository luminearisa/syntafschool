<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buatkan tagihan untuk satu pendaftaran.
 *
 * Hanya pendaftaran + jenis tagihan yang diminta; nominal, nomor, dan jatuh
 * tempo diturunkan dari konfigurasi biaya oleh `PpdbInvoiceService` supaya
 * petugas tidak pernah mengetik angka yang bisa salah hitung.
 */
class PpdbInvoiceStoreRequest extends FormRequest
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
            'registration_id' => ['required', 'integer', 'exists:ppdb_registrations,id'],
            'type' => ['nullable', 'string', Rule::in(PpdbInvoice::TYPES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'registration_id.required' => 'Pendaftaran wajib dipilih.',
            'registration_id.exists' => 'Pendaftaran tidak ditemukan.',
            'type.in' => 'Jenis tagihan tidak dikenali.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'registration_id' => 'pendaftaran',
            'type' => 'jenis tagihan',
        ];
    }
}
