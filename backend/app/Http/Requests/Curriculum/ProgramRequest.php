<?php

namespace App\Http\Requests\Curriculum;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProgramRequest extends FormRequest
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
        $program = $this->route('program');
        $id = $program instanceof Model ? $program->getKey() : $program;

        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('programs', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:100'],
            'education_level_id' => ['nullable', 'integer', 'exists:education_levels,id'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode program',
            'name' => 'nama program',
            'education_level_id' => 'jenjang',
            'description' => 'keterangan',
            'is_active' => 'status aktif',
        ];
    }
}
