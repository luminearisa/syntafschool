<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingActivity;
use App\Models\BoardingAttendanceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kegiatan asrama (§16) + himpunan tipe absensi yang boleh dipakai lewat
 * `allowed_attendance_type_ids` (pivot, §18).
 */
class BoardingActivityRequest extends FormRequest
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
        /** @var BoardingActivity|null $current */
        $current = $this->route('activity');

        return [
            'code' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string', 'max:30', 'regex:/^[A-Z0-9\-_.]+$/',
                Rule::unique(BoardingActivity::class, 'code')->ignore($current?->id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(BoardingActivity::CATEGORIES)],
            'default_attendance_type_id' => ['nullable', 'integer', Rule::exists(BoardingAttendanceType::class, 'id')],
            'late_after_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'allowed_attendance_type_ids' => ['nullable', 'array'],
            'allowed_attendance_type_ids.*' => ['integer', Rule::exists(BoardingAttendanceType::class, 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode kegiatan',
            'name' => 'nama kegiatan',
            'category' => 'kategori',
            'default_attendance_type_id' => 'tipe absensi default',
            'late_after_minutes' => 'batas terlambat (menit)',
            'allowed_attendance_type_ids' => 'tipe absensi yang diizinkan',
        ];
    }

    public function messages(): array
    {
        return [
            'allowed_attendance_type_ids.*.exists' => 'Salah satu tipe absensi yang dipilih tidak dikenal.',
        ];
    }
}
