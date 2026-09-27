<?php

namespace App\Http\Requests\Boarding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Catat tamu yang datang ke asrama (§40).
 *
 * `visit_date` dibatasi `after_or_equal:today`: kunjungan mundur akan mengubah
 * daftar "siapa yang berada di dalam area" menjadi tebakan, jadi tamu dicatat
 * pada hari ia benar-benar datang. Layanan memeriksa ulang batas yang sama untuk
 * pemanggil non-HTTP.
 *
 * `identity_number` (KTP/SIM) dan `phone` opsional tapi dibatasi panjangnya —
 * keduanya tertulis tangan di buku tamu gerbang lalu disalin petugas.
 */
class VisitorRequest extends FormRequest
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
            'visitor_name' => ['required', 'string', 'max:100'],
            'identity_number' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'relationship' => ['nullable', 'string', 'max:50'],
            'student_id' => ['required', 'integer', 'min:1', 'exists:students,id'],
            'visit_date' => ['required', 'date', 'after_or_equal:today'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'visitor_name' => 'nama tamu',
            'identity_number' => 'nomor identitas',
            'phone' => 'nomor telepon',
            'relationship' => 'hubungan dengan santri',
            'student_id' => 'santri yang dikunjungi',
            'visit_date' => 'tanggal kunjungan',
            'purpose' => 'keperluan',
            'notes' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'visitor_name.required' => 'Nama tamu wajib diisi — buku kunjungan tanpa nama tidak dapat ditelusuri (§40).',
            'visitor_name.max' => 'Nama tamu maksimal :max karakter.',
            'identity_number.max' => 'Nomor identitas maksimal :max karakter.',
            'phone.max' => 'Nomor telepon maksimal :max karakter.',
            'relationship.max' => 'Hubungan dengan santri maksimal :max karakter.',
            'student_id.required' => 'Santri yang dikunjungi wajib dipilih.',
            'student_id.exists' => 'Santri tidak ditemukan pada data siswa sekolah ini.',
            'visit_date.required' => 'Tanggal kunjungan wajib diisi.',
            'visit_date.date' => 'Format tanggal kunjungan tidak dikenali. Contoh: 2026-09-25.',
            'visit_date.after_or_equal' => 'Tanggal kunjungan tidak boleh di masa lalu. Kunjungan dicatat saat tamu tiba di gerbang (§40).',
            'purpose.max' => 'Keperluan maksimal :max karakter.',
            'notes.max' => 'Catatan maksimal :max karakter.',
        ];
    }
}
