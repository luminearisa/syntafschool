<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingViolationType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Master jenis pelanggaran (§35). `code` unik ditegakkan service
 * (`assertCodeAvailable`) karena pesannya harus bahasa Indonesia dan key-nya
 * tetap `code` walau kolomnya unik di DB.
 */
class BoardingViolationTypeRequest extends FormRequest
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
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:30'],
            'name' => [$required, 'string', 'max:150'],
            'description' => [$creating ? 'nullable' : 'sometimes', 'string', 'max:500'],
            'category' => [$required, 'string', 'in:'.implode(',', BoardingViolationType::CATEGORIES)],
            'severity' => [$required, 'string', 'in:'.implode(',', BoardingViolationType::SEVERITIES)],
            'point' => [$required, 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode',
            'name' => 'nama pelanggaran',
            'category' => 'kategori',
            'severity' => 'tingkat keparahan',
            'point' => 'poin',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Kode jenis pelanggaran wajib diisi.',
            'name.required' => 'Nama jenis pelanggaran wajib diisi.',
            'category.in' => 'Kategori harus salah satu dari: '.implode(', ', BoardingViolationType::CATEGORIES).'.',
            'severity.in' => 'Tingkat keparahan harus salah satu dari: '.implode(', ', BoardingViolationType::SEVERITIES).'.',
            'point.min' => 'Poin tidak boleh negatif.',
            'point.max' => 'Poin maksimal :max.',
        ];
    }
}
