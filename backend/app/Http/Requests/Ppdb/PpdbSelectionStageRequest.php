<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbSelectionStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tahap seleksi.
 *
 * `program_id` kosong berarti tahap berlaku untuk seluruh program pada periode
 * itu — inilah sebabnya hanya baris bertipe sama yang dibandingkan saat
 * menghitung urutan. `passing_score` menggerakkan penentuan hasil otomatis.
 *
 * `weight` adalah **bobot tahap pada skor gabungan** (audit §26): angkanya
 * disimpan sebagai data, bukan hardcode di kode, sehingga sekolah dapat
 * mengubah porsi akademik/tahfidz/wawancara tanpa mengirim kode.
 */
class PpdbSelectionStageRequest extends FormRequest
{
    /** Bobot default: tahap yang tidak diatur bobotnya dihitung penuh. */
    public const DEFAULT_WEIGHT = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isPost = $this->isMethod('post');
        $required = $isPost ? 'required' : 'sometimes';

        return [
            'period_id' => [$required, 'integer', 'exists:ppdb_periods,id'],
            'program_id' => ['nullable', 'integer', 'exists:ppdb_programs,id'],
            'name' => [$required, 'string', 'max:150'],
            'type' => [$required, 'string', Rule::in(PpdbSelectionStage::TYPES)],
            'scheduled_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:150'],
            'quota' => ['nullable', 'integer', 'min:1'],
            'passing_score' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(PpdbSelectionStage::STATUSES)],
            'order' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Bobot adalah data, bukan angka di dalam kode — tetapi kolomnya tidak boleh
     * kosong, jadi tahap baru yang dikirim tanpa `weight` dihitung penuh (100).
     * Pada `PUT` yang tidak mengirim field ini, nilai lama dibiarkan apa adanya.
     */
    protected function prepareForValidation(): void
    {
        $missing = ! $this->has('weight')
            || $this->input('weight') === ''
            || $this->input('weight') === null;

        if ($missing && $this->isMethod('post')) {
            $this->merge(['weight' => self::DEFAULT_WEIGHT]);
        }
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
            'name.required' => 'Nama tahap wajib diisi.',
            'type.required' => 'Jenis tahap wajib dipilih.',
            'type.in' => 'Jenis tahap tidak dikenali.',
            'scheduled_at.date' => 'Jadwal pelaksanaan harus berupa tanggal & jam yang sah.',
            'location.max' => 'Lokasi maksimal 150 karakter.',
            'quota.min' => 'Kuota minimal 1 peserta.',
            'passing_score.numeric' => 'Nilai kelulusan harus berupa angka.',
            'weight.numeric' => 'Bobot tahap harus berupa angka.',
            'weight.min' => 'Bobot tahap tidak boleh negatif.',
            'weight.max' => 'Bobot tahap maksimal 100.',
            'status.in' => 'Status tahap tidak dikenali.',
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
            'scheduled_at' => 'jadwal',
            'passing_score' => 'nilai kelulusan',
            'weight' => 'bobot tahap',
        ];
    }
}
