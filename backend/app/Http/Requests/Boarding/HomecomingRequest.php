<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingHomecoming;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat kepulangan santri ke rumah (§30).
 *
 * Dimensinya hari, bukan jam — karena itu `home_date`/`return_date` adalah tanggal
 * dan `return_date` diverifikasi `after_or_equal:home_date` (santri tidak bisa
 * kembali sebelum berangkat). Layanan menambahkan penjaga berdata: penempatan
 * aktif dan tidak ada kepulangan terbuka lain pada rentang yang sama.
 *
 * `picked_up_by`/`returned_with` dicatat sejak awal: §30 membedakan siapa yang
 * menjemput dan siapa yang mengantar kembali, dan itu tidak bisa direkonstruksi
 * dari absensi.
 */
class HomecomingRequest extends FormRequest
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
            'student_id' => ['required', 'integer', 'min:1', 'exists:students,id'],
            'type' => ['required', 'string', Rule::in(BoardingHomecoming::TYPES)],
            'home_date' => ['required', 'date'],
            'return_date' => ['required', 'date', 'after_or_equal:home_date'],
            'transport' => ['nullable', 'string', 'max:100'],
            'picked_up_by' => ['nullable', 'string', 'max:100'],
            'returned_with' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
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
            'type' => 'jenis kepulangan',
            'home_date' => 'tanggal pulang',
            'return_date' => 'tanggal kembali',
            'transport' => 'alat transportasi',
            'picked_up_by' => 'penjemput',
            'returned_with' => 'pengantar saat kembali',
            'notes' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_id.required' => 'Santri yang akan dipulangkan wajib dipilih.',
            'student_id.exists' => 'Santri tidak ditemukan pada data siswa sekolah ini.',
            'type.required' => 'Jenis kepulangan wajib dipilih (akhir pekan, libur semester, libur Lebaran, atau khusus).',
            'type.in' => 'Jenis kepulangan tidak dikenali. Pilih salah satu: '.implode(', ', BoardingHomecoming::TYPES).'.',
            'home_date.required' => 'Tanggal pulang wajib diisi.',
            'home_date.date' => 'Format tanggal pulang tidak dikenali. Contoh: 2026-09-25.',
            'return_date.required' => 'Tanggal kembali wajib diisi — ini patokan santri tercatat terlambat (§31).',
            'return_date.date' => 'Format tanggal kembali tidak dikenali. Contoh: 2026-09-27.',
            'return_date.after_or_equal' => 'Tanggal kembali tidak boleh sebelum tanggal pulang (§30).',
            'transport.max' => 'Alat transportasi maksimal :max karakter.',
            'picked_up_by.max' => 'Nama penjemput maksimal :max karakter.',
            'returned_with.max' => 'Nama pengantar maksimal :max karakter.',
            'notes.max' => 'Catatan maksimal :max karakter.',
        ];
    }
}
