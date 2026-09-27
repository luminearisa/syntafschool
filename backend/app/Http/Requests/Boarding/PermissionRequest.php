<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat permintaan izin keluar santri (§28).
 *
 * `expected_return_at` diverifikasi `after_or_equal:start_at` di sini supaya
 * petugas langsung melihat kolom mana yang salah; service menolak pula kasus
 * "sama persis" (422) karena jendela nol menit tidak bisa dipakai gerbang.
 *
 * Aturan yang lebih berdata — santri harus punya penempatan aktif, tidak boleh
 * ada izin terbuka lain pada rentang yang sama — sengaja TIDAK ditaruh di sini:
 * keduanya butuh baris lain di dalam transaksi, dan pemanggil non-HTTP (perintah
 * harian, portal orang tua nanti) wajib melewati penjaga yang sama.
 */
class PermissionRequest extends FormRequest
{
    /** Otorisasi ditegakkan per rute lewat `permission:boarding.permission.*`. */
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
            'student_id' => ['required', 'integer', 'min:1', 'exists:students,id'],
            'permission_type' => ['required', 'string', Rule::in(BoardingPermission::TYPES)],
            'destination' => ['nullable', 'string', 'max:150'],
            'reason' => ['required', 'string', 'max:500'],
            'start_at' => ['required', 'date'],
            'expected_return_at' => ['required', 'date', 'after_or_equal:start_at'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
            // `submit=1` langsung memasukkan permintaan ke antrean persetujuan.
            'submit' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_id' => 'santri',
            'permission_type' => 'jenis izin',
            'destination' => 'tujuan',
            'reason' => 'alasan',
            'start_at' => 'jam mulai izin',
            'expected_return_at' => 'perkiraan waktu kembali',
            'notes' => 'catatan',
            'attachment_path' => 'lampiran',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_id.required' => 'Santri yang akan diizinkan keluar wajib dipilih.',
            'student_id.exists' => 'Santri tidak ditemukan pada data siswa sekolah ini.',
            'permission_type.required' => 'Jenis izin wajib dipilih (sakit, kunjungan keluarga, kegiatan sekolah, keluar biasa, atau istimewa).',
            'permission_type.in' => 'Jenis izin tidak dikenali. Pilih salah satu: '
                .implode(', ', BoardingPermission::TYPES).'.',
            'reason.required' => 'Alasan izin wajib diisi — petugas gerbang dan penyetuju membaca kalimat ini, bukan nama kolom.',
            'reason.max' => 'Alasan izin maksimal :max karakter.',
            'start_at.required' => 'Jam mulai izin wajib diisi.',
            'start_at.date' => 'Format jam mulai izin tidak dikenali. Contoh: 2026-09-25 13:00.',
            'expected_return_at.required' => 'Perkiraan waktu kembali wajib diisi supaya keterlambatan dapat dihitung (§29).',
            'expected_return_at.date' => 'Format perkiraan waktu kembali tidak dikenali. Contoh: 2026-09-25 17:00.',
            'expected_return_at.after_or_equal' => 'Perkiraan waktu kembali tidak boleh sebelum jam mulai izin.',
            'destination.max' => 'Tujuan maksimal :max karakter.',
            'notes.max' => 'Catatan maksimal :max karakter.',
        ];
    }
}
