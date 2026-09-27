<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingBuilding;
use App\Models\BoardingFloor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat/perbarui lantai (§5). Nomor lantai unik per gedung — meniru index
 * `['building_id', 'floor_number']` — dan TIDAK boleh ditukar dengan nomor
 * lantai lain yang kamarnya sudah terisi; aturan hunian itu domain service
 * penempatan, bukan tugas lantai.
 */
class BoardingFloorRequest extends FormRequest
{
    /** Otorisasi ditegakkan per rute lewat `permission:floor.manage`. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var BoardingFloor|null $current */
        $current = $this->route('floor');
        $buildingId = $this->input('building_id') ?? $current?->building_id;

        return [
            'building_id' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'integer',
                Rule::exists(BoardingBuilding::class, 'id'),
            ],
            'floor_number' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'integer', 'min:1', 'max:127',
                Rule::unique(BoardingFloor::class, 'floor_number')
                    ->where(fn ($query) => $query->where('building_id', (int) $buildingId))
                    ->ignore($current?->id),
            ],
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'building_id' => 'gedung induk',
            'floor_number' => 'nomor lantai',
            'name' => 'nama lantai',
            'description' => 'deskripsi',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'building_id.required' => 'Lantai harus berada di bawah satu gedung.',
            'building_id.exists' => 'Gedung induk tidak ditemukan.',
            'floor_number.required' => 'Nomor lantai wajib diisi.',
            'floor_number.integer' => 'Nomor lantai harus berupa angka bulat.',
            'floor_number.min' => 'Nomor lantai minimal :min.',
            'floor_number.max' => 'Nomor lantai maksimal :max.',
            'floor_number.unique' => 'Nomor lantai ini sudah dipakai pada gedung yang sama.',
            'name.max' => 'Nama lantai maksimal :max karakter.',
        ];
    }
}
