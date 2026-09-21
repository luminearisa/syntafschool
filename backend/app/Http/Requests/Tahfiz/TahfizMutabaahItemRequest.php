<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Data item mutabaah harian.
 */
class TahfizMutabaahItemRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:50', 'unique:tahfiz_mutabaah_items,code'],
            'label' => ['required', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'in:'.implode(',', \App\Models\TahfizMutabaahItem::CATEGORIES)],
            'value_type' => ['nullable', 'string', 'in:boolean,count'],
            'unit' => ['nullable', 'string', 'max:20'],
            'target_value' => ['nullable', 'integer', 'min:0'],
            'order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode item',
            'value_type' => 'jenis nilai',
            'target_value' => 'nilai target',
        ];
    }
}
