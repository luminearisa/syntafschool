<?php

namespace App\Modules\Promotion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReopenPromotionDecisionRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'alasan',
        ];
    }
}
