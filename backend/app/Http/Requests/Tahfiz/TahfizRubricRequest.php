<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Data rubrik penilaian beserta kriterianya.
 *
 * Kriteria disertakan dalam satu permintaan (`items`) sehingga rubrik dapat
 * disimpan lengkap dalam satu panggilan. Kriteria lama diganti seluruhnya
 * (sync) agar tidak ada yatim.
 */
class TahfizRubricRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'scope' => ['required', 'string', 'in:'.implode(',', \App\Models\TahfizRubric::SCOPES)],
            'description' => ['nullable', 'string'],
            'max_score' => ['nullable', 'integer', 'min:2', 'max:10'],
            'status' => ['nullable', 'string', 'in:active,archived'],
            'items' => ['nullable', 'array'],
            'items.*.criterion' => ['required_with:items', 'string', 'max:100'],
            'items.*.weight' => ['nullable', 'numeric', 'min:0'],
            'items.*.order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'scope' => 'ranah rubrik',
            'max_score' => 'skor maksimum',
            'items' => 'kriteria penilaian',
            'items.*.criterion' => 'kriteria',
            'items.*.weight' => 'bobot kriteria',
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.criterion.required_with' => 'Setiap kriteria rubrik wajib diberi nama.',
        ];
    }
}
