<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Catatan tilawah — membaca Al-Quran (bukan hafalan).
 */
class TahfizTilawahRecordRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'surah_id' => ['nullable', 'integer', 'exists:quran_surahs,id'],
            'ayah_start' => ['nullable', 'integer', 'min:1', 'max:6236'],
            'ayah_end' => ['nullable', 'integer', 'min:1', 'max:6236'],
            'juz' => ['nullable', 'integer', 'min:1', 'max:30'],
            'page_count' => ['nullable', 'integer', 'min:0', 'max:604'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'quality' => ['nullable', 'integer', 'min:1', 'max:5'],
            'note' => ['nullable', 'string', 'max:2000'],
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
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'enrollment_id' => 'enrollment',
            'participant_id' => 'peserta',
            'date' => 'tanggal',
            'surah_id' => 'surah',
            'ayah_start' => 'ayat awal',
            'ayah_end' => 'ayat akhir',
            'juz' => 'juz',
            'page_count' => 'jumlah halaman',
            'duration_minutes' => 'durasi (menit)',
            'quality' => 'kualitas bacaan',
            'note' => 'catatan',
        ];
    }

    public function messages(): array
    {
        return [
            'participant_id.required' => 'Peserta wajib dipilih.',
            'participant_id.exists' => 'Peserta tidak ditemukan.',
            'date.required' => 'Tanggal tilawah wajib diisi.',
        ];
    }
}
