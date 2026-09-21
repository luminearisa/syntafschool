<?php

namespace App\Http\Requests\Tahfiz;

use App\Models\TahfizMemorizationRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Penilaian setoran oleh guru.
 *
 * Inilah pintu tunggal yang menetapkan status setoran. Mengubah status di
 * endpoint biasa tidak diizinkan — supaya selalu jelas siapa yang menilai
 * dan kapan.
 */
class TahfizMemorizationReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', [
                TahfizMemorizationRecord::STATUS_REVIEWED,
                TahfizMemorizationRecord::STATUS_ACCEPTED,
                TahfizMemorizationRecord::STATUS_ACCEPTED_WITH_REVISION,
                TahfizMemorizationRecord::STATUS_REPEATED,
                TahfizMemorizationRecord::STATUS_REJECTED,
            ])],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fluency' => ['nullable', 'integer', 'min:1', 'max:5'],
            'tajwid' => ['nullable', 'integer', 'min:1', 'max:5'],
            'makhraj' => ['nullable', 'integer', 'min:1', 'max:5'],
            'revision_due_date' => ['nullable', 'date'],
            'teacher_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // "Diterima dengan revisi" wajib tenggat — jika tidak, peserta
                // tidak tahu kapan harus mengulang.
                if (
                    $this->input('status') === TahfizMemorizationRecord::STATUS_ACCEPTED_WITH_REVISION
                    && empty($this->input('revision_due_date'))
                ) {
                    $validator->errors()->add(
                        'revision_due_date',
                        'Tenggat revisi wajib diisi untuk setoran yang diterima dengan revisi.'
                    );
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'status' => 'status penilaian',
            'score' => 'skor',
            'fluency' => 'kelancaran',
            'tajwid' => 'tajwid',
            'makhraj' => 'makhraj',
            'revision_due_date' => 'tenggat revisi',
            'teacher_note' => 'catatan guru',
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Status penilaian wajib dipilih.',
            'status.in' => 'Status penilaian tidak valid.',
        ];
    }
}
