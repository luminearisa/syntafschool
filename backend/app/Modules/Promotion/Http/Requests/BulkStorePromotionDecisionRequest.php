<?php

namespace App\Modules\Promotion\Http\Requests;

use App\Modules\Promotion\Models\PromotionDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkStorePromotionDecisionRequest extends FormRequest
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
            'decisions' => ['required', 'array', 'min:1'],
            'decisions.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'decisions.*.decision' => ['required', Rule::in([PromotionDecision::DECISION_NAIK, PromotionDecision::DECISION_TINGGAL])],
            'decisions.*.target_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'decisions.*.semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
            'decisions.*.reason' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'class_id' => 'kelas',
            'decisions' => 'daftar keputusan',
            'decisions.*.student_id' => 'siswa',
            'decisions.*.decision' => 'keputusan',
            'decisions.*.target_class_id' => 'kelas tujuan',
            'decisions.*.semester_id' => 'semester',
            'decisions.*.reason' => 'alasan',
        ];
    }
}
