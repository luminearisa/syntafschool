<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Catatan tahsin — penilaian perbaikan bacaan memakai rubrik.
 *
 * Skor per kriteria disertakan sebagai larik `scores`. Skor akhir dihitung
 * dari bobot item rubrik, bukan dipercaya dari input.
 */
class TahfizTahsinRecordRequest extends FormRequest
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
            'halaqah_id' => ['nullable', 'integer', 'exists:tahfiz_halaqahs,id'],
            'date' => ['required', 'date'],
            'rubric_id' => ['nullable', 'integer', 'exists:tahfiz_rubrics,id'],
            'instructor_id' => ['nullable', 'integer', 'exists:employees,id'],
            'surah_id' => ['nullable', 'integer', 'exists:quran_surahs,id'],
            'ayah_start' => ['nullable', 'integer', 'min:1', 'max:6236'],
            'ayah_end' => ['nullable', 'integer', 'min:1', 'max:6236'],
            'overall_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'scores' => ['nullable', 'array'],
            'scores.*.rubric_item_id' => ['nullable', 'integer', 'exists:tahfiz_rubric_items,id'],
            'scores.*.criterion' => ['nullable', 'string', 'max:100'],
            'scores.*.score' => ['required_with:scores', 'numeric', 'min:0'],
            'scores.*.note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $start = $this->input('ayah_start');
                $end = $this->input('ayah_end');
                if ($start !== null && $end !== null && (int) $end < (int) $start) {
                    $validator->errors()->add('ayah_end', 'Ayat akhir tidak boleh sebelum ayat awal.');
                }

                $scores = (array) $this->input('scores', []);

                // Skor maksimum diambil dari rubrik, bukan per item —
                // `tahfiz_rubric_items` tidak punya kolom max_score sendiri.
                $rubric = \App\Models\TahfizRubric::query()->whereKey($this->input('rubric_id'))->first();
                $maxScore = $rubric !== null ? (float) $rubric->max_score : 100.0;

                foreach ($scores as $i => $score) {
                    if (empty($score['criterion']) && empty($score['rubric_item_id'])) {
                        $validator->errors()->add(
                            "scores.{$i}.criterion",
                            'Kriteria atau item rubrik harus diisi.'
                        );
                    }

                    if (isset($score['score']) && (float) $score['score'] > $maxScore) {
                        $validator->errors()->add(
                            "scores.{$i}.score",
                            "Skor tidak boleh melebihi skor maksimum rubrik ({$maxScore})."
                        );
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
            'halaqah_id' => 'halaqah',
            'date' => 'tanggal',
            'rubric_id' => 'rubrik',
            'instructor_id' => 'instruktur',
            'surah_id' => 'surah',
            'ayah_start' => 'ayat awal',
            'ayah_end' => 'ayat akhir',
            'overall_score' => 'skor keseluruhan',
            'notes' => 'catatan',
            'scores' => 'skor per kriteria',
            'scores.*.rubric_item_id' => 'item rubrik',
            'scores.*.criterion' => 'kriteria',
            'scores.*.score' => 'skor kriteria',
            'scores.*.note' => 'catatan kriteria',
        ];
    }

    public function messages(): array
    {
        return [
            'participant_id.required' => 'Peserta wajib dipilih.',
            'participant_id.exists' => 'Peserta tidak ditemukan.',
            'date.required' => 'Tanggal tahsin wajib diisi.',
        ];
    }
}
