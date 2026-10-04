<?php

namespace App\Modules\Promotion\Http\Requests;

use App\Modules\Promotion\Models\PromotionDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePromotionDecisionRequest extends FormRequest
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
            'decision' => ['sometimes', 'nullable', Rule::in(PromotionDecision::DECISIONS)],
            'target_class_id' => ['sometimes', 'nullable', 'integer', 'exists:school_classes,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'decision' => 'keputusan',
            'target_class_id' => 'kelas tujuan',
            'reason' => 'alasan',
        ];
    }
}
