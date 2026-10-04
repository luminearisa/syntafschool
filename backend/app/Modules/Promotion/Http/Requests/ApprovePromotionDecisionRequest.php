<?php

namespace App\Modules\Promotion\Http\Requests;

use App\Modules\Promotion\Models\PromotionApproval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApprovePromotionDecisionRequest extends FormRequest
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
            'approver_role' => ['nullable', Rule::in(PromotionApproval::REQUIRED_ROLES)],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'approver_role' => 'peran penyetuju',
            'note' => 'catatan',
        ];
    }
}
