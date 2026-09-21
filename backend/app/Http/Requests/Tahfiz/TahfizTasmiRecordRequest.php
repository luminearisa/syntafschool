<?php

namespace App\Http\Requests\Tahfiz;

use App\Models\TahfizTasmiRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Catatan tasmi' — setoran hafalan dalam jumlah besar di hadapan penguji.
 */
class TahfizTasmiRecordRequest extends FormRequest
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
            'juz_from' => ['required', 'integer', 'min:1', 'max:30'],
            'juz_to' => ['required', 'integer', 'min:1', 'max:30'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'mistakes_count' => ['nullable', 'integer', 'min:0'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'examiner_id' => ['nullable', 'integer', 'exists:employees,id'],
            'result' => ['nullable', 'string', 'in:'.implode(',', TahfizTasmiRecord::RESULTS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $from = $this->input('juz_from');
                $to = $this->input('juz_to');
                if ($from !== null && $to !== null && (int) $to < (int) $from) {
                    $validator->errors()->add('juz_to', 'Juz akhir harus lebih besar atau sama dengan juz awal.');
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
            'juz_from' => 'juz awal',
            'juz_to' => 'juz akhir',
            'duration_minutes' => 'durasi (menit)',
            'mistakes_count' => 'jumlah kesalahan',
            'score' => 'skor',
            'examiner_id' => 'penguji',
            'result' => 'hasil tasmi\'',
            'notes' => 'catatan',
        ];
    }

    public function messages(): array
    {
        return [
            'participant_id.required' => 'Peserta wajib dipilih.',
            'participant_id.exists' => 'Peserta tidak ditemukan.',
            'date.required' => 'Tanggal tasmi\' wajib diisi.',
            'juz_from.required' => 'Juz awal wajib diisi.',
            'juz_to.required' => 'Juz akhir wajib diisi.',
            'result.in' => 'Hasil tasmi\' tidak valid.',
        ];
    }
}
