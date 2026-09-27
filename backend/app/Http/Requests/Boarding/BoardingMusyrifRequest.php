<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingBuilding;
use App\Models\BoardingDormitory;
use App\Models\BoardingMusyrif;
use App\Models\BoardingRoom;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Closure;

/**
 * Penugasan musyrif/pengasuh (§13). Penugasan harus menunjuk sekurang-kurangnya
 * satu area (asrama, gedung, atau kamar) agar scope pengasuhan jelas —
 * `BoardingScopeService` menterjemahkan baris ini menjadi daftar santri binaan.
 */
class BoardingMusyrifRequest extends FormRequest
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
            'employee_id' => ['required', 'integer', Rule::exists(Employee::class, 'id')],
            'dormitory_id' => [
                'nullable',
                'integer',
                Rule::exists(BoardingDormitory::class, 'id'),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (array_filter($this->only(['dormitory_id', 'building_id', 'room_id'])) === []) {
                        $fail('Pilih minimal satu area binaan: asrama, gedung, atau kamar.');
                    }
                },
            ],
            'building_id' => ['nullable', 'integer', Rule::exists(BoardingBuilding::class, 'id')],
            'room_id' => ['nullable', 'integer', Rule::exists(BoardingRoom::class, 'id')],
            'position' => ['required', Rule::in(BoardingMusyrif::POSITIONS)],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(BoardingMusyrif::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.required' => 'Pegawai (ustadz/ustadzah) wajib dipilih.',
            'start_date.required' => 'Tanggal mulai penugasan wajib diisi.',
        ];
    }
}
