<?php

namespace App\Http\Requests\Tahfiz;

use App\Models\TahfizTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Target hafalan default program atau halaqah.
 *
 * Target individual ada terpisah — jangan memaksa seluruh halaqah
 * memiliki target yang sama.
 */
class TahfizTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', 'in:'.implode(',', TahfizTarget::SCOPES)],
            'program_id' => ['nullable', 'integer', 'exists:tahfiz_programs,id'],
            'halaqah_id' => ['nullable', 'integer', 'exists:tahfiz_halaqahs,id'],
            'surah_id' => ['nullable', 'integer', 'exists:quran_surahs,id'],
            'target_type' => ['required', 'string', 'in:'.implode(',', TahfizTarget::TARGET_TYPES)],
            'value' => ['required', 'numeric', 'min:0'],
            'period' => ['required', 'string', 'in:'.implode(',', TahfizTarget::PERIODS)],
            'is_active' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Hanya satu pemilik yang relevan sesuai scope-nya.
        $scope = $this->input('scope');

        $this->merge([
            'program_id' => $scope === TahfizTarget::SCOPE_PROGRAM ? $this->input('program_id') : null,
            'halaqah_id' => $scope === TahfizTarget::SCOPE_HALAQAH ? $this->input('halaqah_id') : null,
        ]);
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $scope = $this->input('scope');

                if ($scope === TahfizTarget::SCOPE_PROGRAM && empty($this->input('program_id'))) {
                    $validator->errors()->add('program_id', 'Program wajib dipilih untuk scope program.');
                }

                if ($scope === TahfizTarget::SCOPE_HALAQAH && empty($this->input('halaqah_id'))) {
                    $validator->errors()->add('halaqah_id', 'Halaqah wajib dipilih untuk scope halaqah.');
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'scope' => 'cakupan target',
            'program_id' => 'program',
            'halaqah_id' => 'halaqah',
            'surah_id' => 'surah',
            'target_type' => 'satuan target',
            'value' => 'nilai target',
            'period' => 'periode',
            'is_active' => 'aktif',
            'effective_from' => 'berlaku mulai',
            'effective_until' => 'berlaku hingga',
        ];
    }

    public function messages(): array
    {
        return [
            'scope.required' => 'Cakupan target wajib dipilih.',
            'scope.in' => 'Cakupan target tidak valid.',
            'target_type.required' => 'Satuan target wajib dipilih.',
            'target_type.in' => 'Satuan target tidak valid.',
            'value.required' => 'Nilai target wajib diisi.',
            'period.required' => 'Periode target wajib dipilih.',
            'period.in' => 'Periode target tidak valid.',
            'effective_until.after_or_equal' => 'Tanggal berakhir tidak boleh sebelum tanggal mulai.',
        ];
    }
}
