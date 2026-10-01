<?php

namespace App\Modules\Bk\Http\Requests;

use App\Modules\Bk\Domain\Enums\CaseType;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Domain\Enums\Priority;
use App\Modules\Bk\Domain\Enums\ReferralCategory;
use App\Modules\Bk\Domain\Enums\Severity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi pembukaan/perubahan kasus.
 *
 * `student_id` dan `case_type` hanya wajib saat membuka kasus baru; perubahan
 * cukup mengirim kolom yang berubah.
 */
class BkCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'student_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:students,id'],
            'case_type' => [$creating ? 'required' : 'sometimes', Rule::in(CaseType::values())],
            'category' => ['nullable', Rule::in(ReferralCategory::values())],
            'title' => ['nullable', 'string', 'max:160'],
            'priority' => ['nullable', Rule::in(Priority::values())],
            'severity' => ['nullable', Rule::in(Severity::values())],
            'confidentiality_level' => ['nullable', Rule::in(ConfidentialityLevel::values())],
            'target_review_date' => ['nullable', 'date'],
            'next_review_date' => ['nullable', 'date'],
            'risk_flag' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
        ];
    }
}
