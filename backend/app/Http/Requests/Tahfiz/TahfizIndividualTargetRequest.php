<?php

namespace App\Http\Requests\Tahfiz;

use App\Models\TahfizTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Target hafalan per peserta.
 *
 * Kecepatan hafalan bervariasi — setiap peserta boleh punya target sendiri
 * tanpa memaksa satu halaqah memakai target yang sama.
 */
class TahfizIndividualTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enrollment_id' => ['nullable', 'integer', 'exists:tahfiz_enrollments,id'],
            'participant_id' => ['required', 'integer', 'exists:tahfiz_participants,id'],
            'period' => ['required', 'string', 'in:'.implode(',', TahfizTarget::PERIODS)],
            'target_type' => ['required', 'string', 'in:'.implode(',', TahfizTarget::TARGET_TYPES)],
            'surah_id' => ['nullable', 'integer', 'exists:quran_surahs,id'],
            'ayah_start' => ['nullable', 'integer', 'min:1', 'max:6236'],
            'ayah_end' => ['nullable', 'integer', 'min:1', 'max:6236'],
            'juz' => ['nullable', 'integer', 'min:1', 'max:30'],
            'page_start' => ['nullable', 'integer', 'min:1', 'max:604'],
            'page_end' => ['nullable', 'integer', 'min:1', 'max:604'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:20'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ([['ayah_start', 'ayah_end'], ['page_start', 'page_end']] as [$start, $end]) {
                    $a = $this->input($start);
                    $b = $this->input($end);
                    if ($a !== null && $b !== null && (int) $b < (int) $a) {
                        $validator->errors()->add($end, ucfirst($end).' tidak boleh sebelum '.$start.'.');
                    }
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'enrollment_id' => 'enrollment',
            'participant_id' => 'peserta',
            'period' => 'periode',
            'target_type' => 'satuan target',
            'surah_id' => 'surah',
            'ayah_start' => 'ayat awal',
            'ayah_end' => 'ayat akhir',
            'juz' => 'juz',
            'page_start' => 'halaman awal',
            'page_end' => 'halaman akhir',
            'value' => 'nilai target',
            'unit' => 'satuan',
            'effective_from' => 'berlaku mulai',
            'effective_until' => 'berlaku hingga',
            'notes' => 'catatan',
            'is_active' => 'aktif',
        ];
    }

    public function messages(): array
    {
        return [
            'participant_id.required' => 'Peserta wajib dipilih.',
            'participant_id.exists' => 'Peserta tidak ditemukan.',
            'period.required' => 'Periode target wajib dipilih.',
            'period.in' => 'Periode target tidak valid.',
            'target_type.required' => 'Satuan target wajib dipilih.',
            'target_type.in' => 'Satuan target tidak valid.',
            'effective_until.after_or_equal' => 'Tanggal berakhir tidak boleh sebelum tanggal mulai.',
        ];
    }
}
