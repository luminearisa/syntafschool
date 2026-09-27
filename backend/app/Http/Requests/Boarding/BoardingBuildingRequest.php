<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingBuilding;
use App\Models\BoardingDormitory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat/perbarui gedung (§5). Kode gedung unik PER ASRAMA — sama seperti
 * index `['dormitory_id', 'code']` di database — sehingga "GED-A" boleh
 * muncul di dua asrama berbeda.
 */
class BoardingBuildingRequest extends FormRequest
{
    /** Otorisasi ditegakkan per rute lewat `permission:building.manage`. */
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
        /** @var BoardingBuilding|null $current */
        $current = $this->route('building');
        $dormitoryId = $this->input('dormitory_id') ?? $current?->dormitory_id;

        return [
            'dormitory_id' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'integer',
                Rule::exists(BoardingDormitory::class, 'id'),
            ],
            'code' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string', 'max:30', 'regex:/^[A-Z0-9\-_.]+$/',
                Rule::unique(BoardingBuilding::class, 'code')
                    ->where(fn ($query) => $query->where('dormitory_id', (int) $dormitoryId)->whereNull('deleted_at'))
                    ->ignore($current?->id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:100'],
            'floor_count' => ['nullable', 'integer', 'min:1', 'max:127'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(BoardingBuilding::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'dormitory_id' => 'asrama induk',
            'code' => 'kode gedung',
            'name' => 'nama gedung',
            'floor_count' => 'jumlah lantai',
            'description' => 'deskripsi',
            'status' => 'status gedung',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dormitory_id.required' => 'Gedung harus berada di bawah satu asrama.',
            'dormitory_id.exists' => 'Asrama induk tidak ditemukan.',
            'code.required' => 'Kode gedung wajib diisi.',
            'code.regex' => 'Kode gedung hanya boleh berisi huruf, angka, tanda hubung, titik, atau garis bawah.',
            'code.unique' => 'Kode gedung ini sudah dipakai pada asrama yang sama.',
            'name.required' => 'Nama gedung wajib diisi.',
            'floor_count.integer' => 'Jumlah lantai harus berupa angka bulat.',
            'floor_count.min' => 'Gedung minimal memiliki satu lantai.',
            'floor_count.max' => 'Jumlah lantai maksimal :max.',
            'status.in' => 'Status gedung harus salah satu dari: active, inactive, maintenance.',
        ];
    }
}
