<?php

namespace App\Http\Requests\Ppdb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Daftar ulang: buka jalur (`open`) atau nyatakan selesai (`complete`).
 *
 * Kedua aksi hanya menerima catatan petugas; nominal tagihan daftar ulang tidak
 * diketik manual karena diturunkan dari konfigurasi biaya oleh
 * `PpdbInvoiceService::ensureForRegistration()`.
 */
class PpdbReRegistrationRequest extends FormRequest
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.max' => 'Catatan maksimal 500 karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'note' => 'catatan',
        ];
    }
}
