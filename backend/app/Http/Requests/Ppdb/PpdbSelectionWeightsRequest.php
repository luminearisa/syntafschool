<?php

namespace App\Http\Requests\Ppdb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Grup bobot tahap seleksi satu periode (+ program).
 *
 * Dipakai dua aksi pada satu path: `GET` membaca total grup, `PUT` menyimpannya
 * kembali. Bobot tetap kolom `ppdb_selection_stages.weight` (audit §26) — angka
 * ini tidak pernah datang dari kode.
 *
 * `program_id` kosong = grup tahap yang berlaku untuk semua program pada periode
 * itu (tahap tanpa program), sesuai cakupan `PpdbSelectionService::weightSummary()`.
 */
class PpdbSelectionWeightsRequest extends FormRequest
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
        // Hanya penulisan yang menuntut daftar bobot; pembacaan cukup dengan scope-nya.
        $writing = ! $this->isMethod('get');

        return [
            'period_id' => ['required', 'integer', 'exists:ppdb_periods,id'],
            'program_id' => ['nullable', 'integer', 'exists:ppdb_programs,id'],
            'weights' => [$writing ? 'required' : 'sometimes', 'array', 'min:1', 'max:50'],
            'weights.*.stage_id' => [
                $writing ? 'required' : 'sometimes',
                'integer',
                'distinct',
                'exists:ppdb_selection_stages,id',
            ],
            'weights.*.weight' => [$writing ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * `stage_id` => bobot, siap dipakai `PpdbSelectionService::applyWeights()`.
     *
     * @return array<int, float>
     */
    public function weightMap(): array
    {
        $map = [];

        foreach ((array) $this->validated('weights') as $row) {
            $map[(int) ($row['stage_id'] ?? 0)] = (float) ($row['weight'] ?? 0);
        }

        return $map;
    }

    public function periodId(): int
    {
        return (int) $this->validated('period_id');
    }

    public function programId(): ?int
    {
        $programId = $this->validated('program_id');

        return $programId === null ? null : (int) $programId;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period_id.required' => 'Periode wajib dipilih.',
            'period_id.exists' => 'Periode tidak ditemukan.',
            'program_id.exists' => 'Program PPDB terpilih tidak ada.',
            'weights.required' => 'Daftar bobot tahap wajib diisi.',
            'weights.*.stage_id.required' => 'Setiap baris bobot harus menyebut tahapnya.',
            'weights.*.stage_id.distinct' => 'Satu tahap tidak boleh dikirim lebih dari sekali.',
            'weights.*.stage_id.exists' => 'Ada tahap yang tidak ditemukan.',
            'weights.*.weight.required' => 'Bobot setiap tahap wajib diisi.',
            'weights.*.weight.numeric' => 'Bobot tahap harus berupa angka.',
            'weights.*.weight.min' => 'Bobot tahap tidak boleh negatif.',
            'weights.*.weight.max' => 'Bobot tahap maksimal 100.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'period_id' => 'periode',
            'program_id' => 'program',
            'weights' => 'bobot tahap',
        ];
    }
}
