<?php

namespace App\Modules\Promotion\Http\Requests;

use App\Modules\Promotion\Models\Graduation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkStoreGraduationRequest extends FormRequest
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
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'graduations' => ['required', 'array', 'min:1'],
            'graduations.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'graduations.*.decision' => ['required', Rule::in(Graduation::DECISIONS)],
            'graduations.*.school_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'graduations.*.semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
            'graduations.*.notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'academic_year_id' => 'tahun ajaran',
            'graduations' => 'daftar kelulusan',
            'graduations.*.student_id' => 'siswa',
            'graduations.*.decision' => 'keputusan',
            'graduations.*.school_class_id' => 'kelas',
            'graduations.*.semester_id' => 'semester',
            'graduations.*.notes' => 'catatan',
        ];
    }
}
