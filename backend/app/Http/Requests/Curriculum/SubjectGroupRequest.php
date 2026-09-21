<?php

namespace App\Http\Requests\Curriculum;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubjectGroupRequest extends FormRequest
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
        $subjectGroup = $this->route('subjectGroup');
        $id = $subjectGroup instanceof Model ? $subjectGroup->getKey() : $subjectGroup;

        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('subject_groups', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode kelompok mapel',
            'name' => 'nama kelompok mapel',
            'description' => 'keterangan',
            'sort_order' => 'urutan',
            'is_active' => 'status aktif',
        ];
    }
}
