<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbWave;
use App\Services\Ppdb\PpdbPeriodService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Gelombang PPDB dalam satu periode.
 *
 * Kode gelombang unik **di dalam periodenya** (GW1 periode A boleh dipakai
 * lagi oleh periode B), dan periodenya harus ada.
 */
class PpdbWaveRequest extends FormRequest
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
        $wave = $this->route('wave');
        $periodId = $this->periodId();

        return [
            'period_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', 'exists:ppdb_periods,id'],
            'code' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'string', 'max:30',
                PpdbPeriodService::uniqueInPeriod('ppdb_waves', 'code', $periodId, $wave?->id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:100'],
            'registration_start' => ['nullable', 'date'],
            'registration_end' => ['nullable', 'date', 'after_or_equal:registration_start'],
            'quota' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'status' => ['nullable', 'string', Rule::in(PpdbWave::STATUSES)],
            'description' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'period_id' => 'periode',
            'registration_start' => 'tanggal mulai pendaftaran',
            'registration_end' => 'tanggal akhir pendaftaran',
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
            'code.required' => 'Kode gelombang wajib diisi.',
            'code.unique' => 'Kode gelombang sudah dipakai pada periode ini.',
            'name.required' => 'Nama gelombang wajib diisi.',
            'registration_end.after_or_equal' => 'Akhir pendaftaran gelombang tidak boleh mendahului awalnya.',
            'status.in' => 'Status gelombang harus active atau inactive.',
        ];
    }

    /** Periode efektif baris ini (dikirim eksplisit, atau milik gelombang saat update). */
    private function periodId(): ?int
    {
        if (filled($this->input('period_id'))) {
            return (int) $this->input('period_id');
        }

        $wave = $this->route('wave');

        return $wave instanceof PpdbWave ? (int) $wave->period_id : null;
    }
}
