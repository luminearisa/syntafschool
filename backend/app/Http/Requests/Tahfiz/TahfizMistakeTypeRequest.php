<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Data jenis kesalahan (taksonomi).
 */
class TahfizMistakeTypeRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:50', 'unique:tahfiz_mistake_types,code'],
            'label' => ['required', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'in:'.implode(',', \App\Models\TahfizMistakeType::CATEGORIES)],
            'severity_weight' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode kesalahan',
            'severity_weight' => 'bobot keparahan',
        ];
    }
}
