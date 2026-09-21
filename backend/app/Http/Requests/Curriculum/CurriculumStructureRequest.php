<?php

namespace App\Http\Requests\Curriculum;

use Illuminate\Foundation\Http\FormRequest;

class CurriculumStructureRequest extends FormRequest
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
            'curriculum_id' => ['required', 'integer', 'exists:curricula,id'],
            'education_level_id' => ['required', 'integer', 'exists:education_levels,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'version' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'items.*.subject_group_id' => ['nullable', 'integer', 'exists:subject_groups,id'],
            'items.*.semester' => ['required', 'integer', 'in:1,2'],
            'items.*.weekly_hours' => ['required', 'integer', 'min:1', 'max:12'],
            'items.*.is_required' => ['sometimes', 'boolean'],
            'items.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'curriculum_id' => 'kurikulum',
            'education_level_id' => 'jenjang',
            'academic_year_id' => 'tahun ajaran',
            'version' => 'versi',
            'name' => 'nama struktur',
            'is_active' => 'status aktif',
            'notes' => 'catatan',
            'items' => 'daftar mata pelajaran',
            'items.*.subject_id' => 'mata pelajaran',
            'items.*.subject_group_id' => 'kelompok mapel',
            'items.*.semester' => 'semester',
            'items.*.weekly_hours' => 'jumlah JP per pekan',
            'items.*.is_required' => 'status wajib',
            'items.*.sort_order' => 'urutan',
            'items.*.notes' => 'catatan',
        ];
    }
}
