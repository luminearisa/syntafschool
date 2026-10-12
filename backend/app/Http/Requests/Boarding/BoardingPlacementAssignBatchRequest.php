<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingBuilding;
use App\Models\BoardingDormitory;
use App\Models\BoardingFloor;
use App\Models\BoardingRoom;
use App\Models\Student;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Penempatan BANYAK santri sekaligus ke satu kamar (§9, §10).
 *
 * Semua santri ditempatkan pada kamar yang sama; memilih satu tempat tidur
 * spesifik hanya masuk akal bila hanya satu santri yang ditempatkan, sehingga
 * `bed_id` ditolak saat `student_ids` berisi lebih dari satu. Aturan keras
 * (kamar aktif, kapasitas, gender, placement ganda) tetap di
 * `BoardingPlacementService::assignMany()`.
 */
class BoardingPlacementAssignBatchRequest extends FormRequest
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
            'student_ids' => ['required', 'array', 'min:1', 'max:200'],
            'student_ids.*' => ['integer', 'distinct', Rule::exists(Student::class, 'id')],
            'dormitory_id' => ['nullable', 'integer', Rule::exists(BoardingDormitory::class, 'id')],
            'building_id' => ['nullable', 'integer', Rule::exists(BoardingBuilding::class, 'id')],
            'floor_id' => ['nullable', 'integer', Rule::exists(BoardingFloor::class, 'id')],
            'room_id' => ['required', 'integer', Rule::exists(BoardingRoom::class, 'id')],
            'bed_id' => ['nullable', 'integer', Rule::exists('boarding_beds', 'id')],
            'start_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $count = is_array($this->input('student_ids')) ? count($this->input('student_ids')) : 0;

            if ($count > 1 && filled($this->input('bed_id'))) {
                $validator->errors()->add(
                    'bed_id',
                    'Tempat tidur tertentu hanya dapat dipilih bila menempatkan satu santri. '
                        .'Kosongkan pilihan tempat tidur untuk penempatan massal.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_ids' => 'daftar santri',
            'student_ids.*' => 'santri',
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
            'student_ids.required' => 'Pilih minimal satu santri untuk ditempatkan.',
            'student_ids.min' => 'Pilih minimal satu santri untuk ditempatkan.',
            'room_id.required' => 'Kamar tujuan wajib dipilih.',
        ];
    }
}
