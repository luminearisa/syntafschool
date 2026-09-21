<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbProgram;
use App\Services\Ppdb\PpdbPeriodService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Penawaran program pada sebuah periode PPDB.
 *
 * `program_id` dan `education_level_id` hanyalah tautan opsional ke master yang
 * sudah ada — bila dikirim, keberadaannya dijamin di sini (entitas tidak
 * pernah diduplikasi).
 */
class PpdbProgramRequest extends FormRequest
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
        $program = $this->route('program');
        $periodId = $this->periodId();

        return [
            'period_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', 'exists:ppdb_periods,id'],
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'education_level_id' => ['nullable', 'integer', 'exists:education_levels,id'],
            'code' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'string', 'max:30',
                PpdbPeriodService::uniqueInPeriod('ppdb_programs', 'code', $periodId, $program?->id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:150'],
            'quota' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::in(PpdbProgram::STATUSES)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'period_id' => 'periode',
            'program_id' => 'master program',
            'education_level_id' => 'tingkat pendidikan',
            'sort_order' => 'urutan tampilan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period_id.required' => 'Periode wajib dipilih.',
            'period_id.exists' => 'Periode tidak ditemukan.',
            'program_id.exists' => 'Master program terpilih tidak ada di daftar program sekolah.',
            'education_level_id.exists' => 'Tingkat pendidikan terpilih tidak ada.',
            'code.required' => 'Kode program wajib diisi.',
            'code.unique' => 'Kode program sudah dipakai pada periode ini.',
            'name.required' => 'Nama program wajib diisi.',
            'status.in' => 'Status program harus active atau inactive.',
        ];
    }

    /** Periode efektif baris ini. */
    private function periodId(): ?int
    {
        if (filled($this->input('period_id'))) {
            return (int) $this->input('period_id');
        }

        $program = $this->route('program');

        return $program instanceof PpdbProgram ? (int) $program->period_id : null;
    }
}
