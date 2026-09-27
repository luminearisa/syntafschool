<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingBuilding;
use App\Models\BoardingDormitory;
use App\Models\BoardingFloor;
use App\Models\BoardingRoom;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Penempatan santri pada asrama → gedung → lantai → kamar → bed (§9, §10).
 * Aturan keras (kamar aktif, bed terisi, kapasitas, gender, placement ganda)
 * ditegakkan `BoardingPlacementService` — validasi ini hanya lapisan bentuk.
 */
class BoardingPlacementAssignRequest extends FormRequest
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
            'student_id' => ['required', 'integer', Rule::exists(Student::class, 'id')],
            'dormitory_id' => ['nullable', 'integer', Rule::exists(BoardingDormitory::class, 'id')],
            'building_id' => ['nullable', 'integer', Rule::exists(BoardingBuilding::class, 'id')],
            'floor_id' => ['nullable', 'integer', Rule::exists(BoardingFloor::class, 'id')],
            'room_id' => ['required', 'integer', Rule::exists(BoardingRoom::class, 'id')],
            'bed_id' => ['nullable', 'integer', Rule::exists('boarding_beds', 'id')],
            'start_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_id' => 'santri',
            'dormitory_id' => 'asrama',
            'building_id' => 'gedung',
            'floor_id' => 'lantai',
            'room_id' => 'kamar',
            'bed_id' => 'tempat tidur',
            'start_date' => 'tanggal mulai',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_id.required' => 'Santri wajib dipilih.',
            'room_id.required' => 'Kamar tujuan wajib dipilih.',
        ];
    }
}
