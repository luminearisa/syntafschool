<?php

namespace App\Http\Requests\Tahfiz;

use App\Models\TahfizExam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Ujian Tahfiz — hafalan, sambung ayat, ayat acak, tasmi', tahsin,
 * atau kenaikan level.
 */
class TahfizExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'unique:tahfiz_exams,code,'.((int) $this->route('exam')?->id ?? 0)],
            'name' => ['required', 'string', 'max:150'],
            'program_id' => ['nullable', 'integer', 'exists:tahfiz_programs,id'],
            'halaqah_id' => ['nullable', 'integer', 'exists:tahfiz_halaqahs,id'],
            'type' => ['required', 'string', 'in:'.implode(',', TahfizExam::TYPES)],
            'rubric_id' => ['nullable', 'integer', 'exists:tahfiz_rubrics,id'],
            'exam_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'examiner_id' => ['nullable', 'integer', 'exists:employees,id'],
            'level_id' => ['nullable', 'integer', 'exists:tahfiz_levels,id'],
            'max_score' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'passing_score' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'string', 'in:'.implode(',', TahfizExam::STATUSES)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $max = (float) ($this->input('max_score') ?: 100);
                $passing = (float) ($this->input('passing_score') ?: 75);

                if ($passing > $max) {
                    $validator->errors()->add('passing_score', 'Batas kelulusan tidak boleh melebihi skor maksimum.');
                }

                // Kenaikan level wajib menentukan level tujuan.
                if ($this->input('type') === 'kenaikan_level' && empty($this->input('level_id'))) {
                    $validator->errors()->add('level_id', 'Level tujuan wajib dipilih untuk ujian kenaikan level.');
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'kode ujian',
            'name' => 'nama ujian',
            'program_id' => 'program',
            'halaqah_id' => 'halaqah',
            'type' => 'jenis ujian',
            'rubric_id' => 'rubrik',
            'exam_date' => 'tanggal ujian',
            'start_time' => 'jam mulai',
            'duration_minutes' => 'durasi (menit)',
            'examiner_id' => 'penguji',
            'level_id' => 'level tujuan',
            'max_score' => 'skor maksimum',
            'passing_score' => 'batas kelulusan',
            'description' => 'deskripsi',
            'status' => 'status',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Kode ujian wajib diisi.',
            'code.unique' => 'Kode ujian sudah dipakai.',
            'name.required' => 'Nama ujian wajib diisi.',
            'type.required' => 'Jenis ujian wajib dipilih.',
            'type.in' => 'Jenis ujian tidak valid.',
            'exam_date.required' => 'Tanggal ujian wajib diisi.',
        ];
    }
}
