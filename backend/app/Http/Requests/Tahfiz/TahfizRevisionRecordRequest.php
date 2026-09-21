<?php

namespace App\Http\Requests\Tahfiz;

use App\Models\TahfizRevisionRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Catatan murojaah — pengulangan hafalan lama agar mutqin.
 */
class TahfizRevisionRecordRequest extends FormRequest
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
            'session_id' => ['nullable', 'integer', 'exists:tahfiz_sessions,id'],
            'date' => ['required', 'date'],
            'surah_id' => ['nullable', 'integer', 'exists:quran_surahs,id'],
            'ayah_start' => ['nullable', 'integer', 'min:1', 'max:6236'],
            'ayah_end' => ['nullable', 'integer', 'min:1', 'max:6236'],
            'juz' => ['nullable', 'integer', 'min:1', 'max:30'],
            'page_start' => ['nullable', 'integer', 'min:1', 'max:604'],
            'page_end' => ['nullable', 'integer', 'min:1', 'max:604'],
            'method' => ['nullable', 'string', 'in:'.implode(',', TahfizRevisionRecord::METHODS)],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'mistakes_count' => ['nullable', 'integer', 'min:0'],
            'teacher_note' => ['nullable', 'string', 'max:2000'],
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

                $pageStart = $this->input('page_start');
                $pageEnd = $this->input('page_end');
                if ($pageStart !== null && $pageEnd !== null && (int) $pageEnd < (int) $pageStart) {
                    $validator->errors()->add('page_end', 'Halaman akhir tidak boleh sebelum halaman awal.');
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
            'session_id' => 'sesi',
            'date' => 'tanggal',
            'surah_id' => 'surah',
            'ayah_start' => 'ayat awal',
            'ayah_end' => 'ayat akhir',
            'juz' => 'juz',
            'page_start' => 'halaman awal',
            'page_end' => 'halaman akhir',
            'method' => 'metode murojaah',
            'duration_minutes' => 'durasi (menit)',
            'score' => 'skor',
            'mistakes_count' => 'jumlah kesalahan',
            'teacher_note' => 'catatan guru',
        ];
    }

    public function messages(): array
    {
        return [
            'participant_id.required' => 'Peserta wajib dipilih.',
            'participant_id.exists' => 'Peserta tidak ditemukan.',
            'date.required' => 'Tanggal murojaah wajib diisi.',
            'method.in' => 'Metode murojaah tidak valid.',
        ];
    }
}
