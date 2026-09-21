<?php

namespace App\Http\Requests\Tahfiz;

use App\Models\TahfizCertificate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Penerbitan sertifikat Tahfiz.
 *
 * Nomor sertifikat & token QR dibangkitkan server-side — keduanya unik dan
 * tidak boleh diisi dari luar.
 */
class TahfizCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'participant_id' => ['required', 'integer', 'exists:tahfiz_participants,id'],
            'enrollment_id' => ['nullable', 'integer', 'exists:tahfiz_enrollments,id'],
            'program_id' => ['nullable', 'integer', 'exists:tahfiz_programs,id'],
            'achievement_type' => ['required', 'string', 'in:'.implode(',', TahfizCertificate::ACHIEVEMENT_TYPES)],
            'juz_count' => ['nullable', 'integer', 'min:0', 'max:30'],
            'title' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'issue_date' => ['required', 'date'],
            'exam_id' => ['nullable', 'integer', 'exists:tahfiz_exams,id'],
            'examiner_id' => ['nullable', 'integer', 'exists:employees,id'],
            'signature_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function attributes(): array
    {
        return [
            'participant_id' => 'peserta',
            'enrollment_id' => 'enrollment',
            'program_id' => 'program',
            'achievement_type' => 'jenis prestasi',
            'juz_count' => 'jumlah juz',
            'title' => 'judul',
            'description' => 'deskripsi',
            'issue_date' => 'tanggal terbit',
            'exam_id' => 'ujian',
            'examiner_id' => 'penguji',
            'signature_name' => 'nama penandatangan',
        ];
    }

    public function messages(): array
    {
        return [
            'participant_id.required' => 'Peserta wajib dipilih.',
            'participant_id.exists' => 'Peserta tidak ditemukan.',
            'achievement_type.required' => 'Jenis prestasi wajib dipilih.',
            'achievement_type.in' => 'Jenis prestasi tidak valid.',
            'issue_date.required' => 'Tanggal terbit wajib diisi.',
        ];
    }
}
