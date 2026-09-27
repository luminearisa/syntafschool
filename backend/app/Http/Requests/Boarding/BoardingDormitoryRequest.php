<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingDormitory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat/perbarui asrama (§6). `code` dipakai sebagai kunci lokasi di label
 * penempatan, jadi diubah huruf besar dan unik global (baris soft-deleted
 * tetap dihitung karena index database tidak mengenal soft delete).
 */
class BoardingDormitoryRequest extends FormRequest
{
    /** Otorisasi ditegakkan per rute lewat `permission:dorm.manage`. */
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
        /** @var BoardingDormitory|null $current */
        $current = $this->route('dormitory');

        return [
            'code' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string', 'max:30', 'regex:/^[A-Z0-9\-_.]+$/',
                Rule::unique(BoardingDormitory::class, 'code')
                    ->where(fn ($query) => $query->whereNull('deleted_at'))
                    ->ignore($current?->id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(BoardingDormitory::GENDERS)],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'location' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(BoardingDormitory::STATUSES)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode asrama',
            'name' => 'nama asrama',
            'gender' => 'jenis penghuni',
            'capacity' => 'kapasitas',
            'location' => 'lokasi',
            'description' => 'deskripsi',
            'status' => 'status asrama',
            'is_active' => 'flag aktif',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Kode asrama wajib diisi, misalnya ASM-01.',
            'code.max' => 'Kode asrama maksimal :max karakter.',
            'code.regex' => 'Kode asrama hanya boleh berisi huruf, angka, tanda hubung, titik, atau garis bawah.',
            'code.unique' => 'Kode asrama ini sudah dipakai. Satu kode hanya boleh menunjuk satu asrama.',
            'name.required' => 'Nama asrama wajib diisi.',
            'name.max' => 'Nama asrama maksimal :max karakter.',
            'gender.in' => 'Jenis penghuni harus salah satu dari: male, female, mixed.',
            'capacity.integer' => 'Kapasitas harus berupa angka bulat.',
            'capacity.min' => 'Kapasitas tidak boleh negatif.',
            'status.in' => 'Status asrama harus salah satu dari: active, inactive, maintenance.',
            'location.max' => 'Lokasi maksimal :max karakter.',
        ];
    }
}
