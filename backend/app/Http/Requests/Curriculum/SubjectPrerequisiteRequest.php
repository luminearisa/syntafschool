<?php

namespace App\Http\Requests\Curriculum;

use Illuminate\Foundation\Http\FormRequest;

class SubjectPrerequisiteRequest extends FormRequest
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
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'prerequisite_subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'subject_id' => 'mata pelajaran',
            'prerequisite_subject_id' => 'mata pelajaran prasyarat',
            'notes' => 'catatan',
        ];
    }
}
