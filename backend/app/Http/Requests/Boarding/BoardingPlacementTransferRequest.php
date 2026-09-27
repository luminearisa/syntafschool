<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingBuilding;
use App\Models\BoardingDormitory;
use App\Models\BoardingFloor;
use App\Models\BoardingRoom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mutasi kamar/asrama (§11, §12, §65): placement lama ditutup otomatis oleh
 * service, alasan wajib supaya jejak historinya bisa dibaca orang tua.
 */
class BoardingPlacementTransferRequest extends FormRequest
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
            'dormitory_id' => ['nullable', 'integer', Rule::exists(BoardingDormitory::class, 'id')],
            'building_id' => ['nullable', 'integer', Rule::exists(BoardingBuilding::class, 'id')],
            'floor_id' => ['nullable', 'integer', Rule::exists(BoardingFloor::class, 'id')],
            'room_id' => ['required', 'integer', Rule::exists(BoardingRoom::class, 'id')],
            'bed_id' => ['nullable', 'integer', Rule::exists('boarding_beds', 'id')],
            'reason' => ['required', 'string', 'max:500'],
            'effective_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'room_id' => 'kamar tujuan',
            'bed_id' => 'tempat tidur tujuan',
            'reason' => 'alasan mutasi',
            'effective_date' => 'tanggal berlaku',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_id.required' => 'Kamar tujuan wajib dipilih.',
            'reason.required' => 'Alasan mutasi wajib diisi (kenaikan tingkat, kondisi khusus, dll).',
        ];
    }
}
