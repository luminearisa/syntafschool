<?php

namespace App\Http\Requests\Academic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SchoolClassRequest extends FormRequest
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
        $class = $this->route('class');
        $classId = $class instanceof Model ? $class->getKey() : $class;

        return [
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'education_level_id' => ['nullable', 'exists:education_levels,id'],
            'room_id' => ['nullable', 'exists:rooms,id'],
            'school_id' => ['nullable', 'exists:schools,id'],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:30', Rule::unique('school_classes', 'code')->ignore($classId)],
            'homeroom_teacher_id' => ['nullable', 'exists:employees,id'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'academic_year_id' => 'tahun ajaran',
            'education_level_id' => 'tingkat',
            'room_id' => 'ruangan',
            'school_id' => 'sekolah',
            'name' => 'nama kelas',
            'code' => 'kode kelas',
            'homeroom_teacher_id' => 'wali kelas',
            'capacity' => 'kapasitas',
            'is_active' => 'status aktif',
        ];
    }
}
