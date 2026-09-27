<?php

namespace App\Http\Requests\Boarding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pulangkan tamu dari area kunjungan (§40).
 *
 * `check_out_at` opsional — petugas gerbang biasanya memulangkan tamu "sekarang".
 * Kalau waktu diketik manual (mis. buku tamu disalin sore hari), jamnya diterima
 * apa adanya dan durasi kunjungan dihitung layanan dari selisih `check_in_at`.
 */
class VisitorCheckOutRequest extends FormRequest
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
            'check_out_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'check_out_at' => 'waktu check out',
            'notes' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'check_out_at.date' => 'Format waktu check out tidak dikenali. Contoh: 2026-09-25 16:30.',
            'notes.max' => 'Catatan maksimal :max karakter.',
        ];
    }
}
