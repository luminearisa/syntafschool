<?php

namespace App\Http\Requests\Teaching;

use App\Models\TeachingJournal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi jurnal mengajar.
 *
 * Aturan lintas-tabel (guru mengampu mapel, tanggal di dalam semester,
 * realisasi JP, jurnal ganda) diperiksa di controller agar pesan validasi
 * mengarah ke field yang tepat.
 */
class TeachingJournalRequest extends FormRequest
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
            'schedule_id' => ['nullable', 'integer', 'exists:schedules,id'],
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'teacher_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where('is_teacher', true),
            ],
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'time_slot_id' => ['nullable', 'integer', 'exists:time_slots,id'],
            'date' => ['required', 'date'],
            'topic' => ['required', 'string', 'max:200'],
            'material' => ['nullable', 'string'],
            'activities' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'student_present_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'realized_jp' => ['nullable', 'integer', 'min:1', 'max:99'],
            'status' => ['nullable', Rule::in(TeachingJournal::STATUSES)],
            'attachment_path' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'schedule_id' => 'jadwal',
            'class_id' => 'kelas',
            'subject_id' => 'mata pelajaran',
            'teacher_id' => 'guru',
            'semester_id' => 'semester',
            'time_slot_id' => 'jam pelajaran',
            'date' => 'tanggal',
            'topic' => 'topik',
            'material' => 'materi',
            'activities' => 'kegiatan',
            'notes' => 'catatan',
            'student_present_count' => 'jumlah siswa hadir',
            'realized_jp' => 'JP terealisasi',
            'status' => 'status',
            'attachment_path' => 'lampiran',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'teacher_id.exists' => 'Guru harus pegawai yang berstatus pengajar.',
            'realized_jp.min' => 'JP terealisasi minimal 1 jam pelajaran.',
        ];
    }
}
