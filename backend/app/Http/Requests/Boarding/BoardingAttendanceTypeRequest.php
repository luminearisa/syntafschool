<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingAttendanceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tipe absensi (§16) — dibuat, dibaca, dikoreksi; TIDAK dihapus sembarangan
 * karena baris absensi menunjuknya dengan RESTRICT.
 */
class BoardingAttendanceTypeRequest extends FormRequest
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
        /** @var BoardingAttendanceType|null $current */
        $current = $this->route('attendanceType');

        return [
            'code' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string', 'max:30', 'regex:/^[A-Z0-9\-_.]+$/',
                Rule::unique(BoardingAttendanceType::class, 'code')->ignore($current?->id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(BoardingAttendanceType::CATEGORIES)],
            'is_present' => ['nullable', 'boolean'],
            'is_excused' => ['nullable', 'boolean'],
            'requires_note' => ['nullable', 'boolean'],
            'requires_approval' => ['nullable', 'boolean'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode tipe',
            'name' => 'nama tipe',
            'category' => 'kategori',
            'is_present' => 'hitung hadir',
            'is_excused' => 'kategori izin',
            'requires_note' => 'wajib catatan',
            'requires_approval' => 'butuh persetujuan',
            'color' => 'warna',
        ];
    }
}
