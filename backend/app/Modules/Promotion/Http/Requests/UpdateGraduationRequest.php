<?php

namespace App\Modules\Promotion\Http\Requests;

use App\Modules\Promotion\Models\Graduation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGraduationRequest extends FormRequest
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
            'decision' => ['sometimes', Rule::in(Graduation::DECISIONS)],
            'certificate_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'decision' => 'keputusan',
            'certificate_path' => 'berkas sertifikat',
            'notes' => 'catatan',
        ];
    }
}
