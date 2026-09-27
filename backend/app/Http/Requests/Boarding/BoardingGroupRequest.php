<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingDormitory;
use App\Models\BoardingGroup;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kelompok binaan (§14). `code` unik global; `musyrif_employee_id` harus
 * pegawai aktif agar kelompok benar-benar punya pengasuh.
 */
class BoardingGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var BoardingGroup|null $current */
        $current = $this->route('group');

        return [
            'code' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string', 'max:30', 'regex:/^[A-Z0-9\-_.]+$/',
                Rule::unique(BoardingGroup::class, 'code')->ignore($current?->id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:100'],
            'musyrif_employee_id' => ['nullable', 'integer', Rule::exists(Employee::class, 'id')],
            'dormitory_id' => ['nullable', 'integer', Rule::exists(BoardingDormitory::class, 'id')],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(BoardingGroup::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode kelompok',
            'name' => 'nama kelompok',
            'musyrif_employee_id' => 'musyrif',
            'dormitory_id' => 'asrama',
            'status' => 'status kelompok',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Kode kelompok sudah dipakai.',
            'name.required' => 'Nama kelompok wajib diisi.',
        ];
    }
}
