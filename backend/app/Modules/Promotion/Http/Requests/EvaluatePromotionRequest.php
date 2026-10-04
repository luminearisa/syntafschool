<?php

namespace App\Modules\Promotion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EvaluatePromotionRequest extends FormRequest
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
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'class_id' => 'kelas',
            'semester_id' => 'semester',
        ];
    }
}
