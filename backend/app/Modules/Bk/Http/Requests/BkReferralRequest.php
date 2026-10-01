<?php

namespace App\Modules\Bk\Http\Requests;

use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Domain\Enums\Priority;
use App\Modules\Bk\Domain\Enums\ReferralCategory;
use App\Modules\Bk\Domain\Enums\ReferralSource;
use App\Modules\Bk\Domain\Enums\ReferralStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi pengajuan referral.
 *
 * Deskripsi wajib, tetapi detail sensitif **tidak** diwajibkan: pelapor cukup
 * menuliskan apa yang ia amati. Siswa/orang tua hanya boleh merujuk dirinya atau
 * anaknya (diperiksa lagi di layanan).
 */
class BkReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('bk.referral.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'source_type' => ['required', Rule::in(ReferralSource::values())],
            'source_id' => ['nullable', 'integer'],
            'source_label' => ['nullable', 'string', 'max:160'],
            'category' => ['nullable', Rule::in(ReferralCategory::values())],
            'priority' => ['nullable', Rule::in(Priority::values())],
            'description' => ['required', 'string', 'min:10', 'max:4000'],
            'observation' => ['nullable', 'string', 'max:4000'],
            'requested_support' => ['nullable', 'string', 'max:2000'],
            'confidentiality_level' => ['nullable', Rule::in(ConfidentialityLevel::values())],
            'consent_required' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'description.min' => 'Tuliskan setidaknya satu kalimat tentang apa yang Anda amati.',
        ];
    }
}
