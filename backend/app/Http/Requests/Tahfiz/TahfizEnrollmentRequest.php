<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Data enrollment: pendaftaran satu peserta pada satu program.
 *
 * Untuk siswa, konteks akademik (kelas, tahun, semester) diisi agar laporan
 * bisa membedakan peserta per kelas. Halaqah & level boleh dikosongkan dan
 * diisi setelah placement test.
 */
class TahfizEnrollmentRequest extends FormRequest
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
            'participant_id' => ['required', 'integer', 'exists:tahfiz_participants,id'],
            'program_id' => ['required', 'integer', 'exists:tahfiz_programs,id'],
            'level_id' => ['nullable', 'integer', 'exists:tahfiz_levels,id'],
            'halaqah_id' => ['nullable', 'integer', 'exists:tahfiz_halaqahs,id'],
            'school_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
            'start_date' => ['nullable', 'date'],
            'expected_end_date' => ['nullable', 'date'],
            'starting_juz' => ['nullable', 'string', 'max:50'],
            'target_note' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', \App\Models\TahfizEnrollment::STATUSES)],

            // Penempatan langsung (opsional): placement test sudah menentukan level.
            'placement_test_id' => ['nullable', 'integer', 'exists:tahfiz_placement_tests,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'participant_id' => 'peserta',
            'program_id' => 'program',
            'level_id' => 'level',
            'halaqah_id' => 'halaqah',
            'school_class_id' => 'kelas',
            'starting_juz' => 'juz awal',
            'expected_end_date' => 'tanggal selesai rencana',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $start = $this->input('start_date');
                $end = $this->input('expected_end_date');

                if ($start && $end && $start > $end) {
                    $validator->errors()->add(
                        'expected_end_date',
                        'Tanggal selesai tidak boleh mendahului tanggal mulai.'
                    );
                }
            },
        ];
    }
}
