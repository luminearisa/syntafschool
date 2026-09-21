<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbRegistration;
use App\Models\PpdbSelectionParticipant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Penilaian satu peserta seleksi.
 *
 * `result` boleh dikosongkan: bila ada, service menurunkannya dari
 * `passing_score` tahap sehingga panitia cukup memasukkan nilai.
 */
class PpdbSelectionScoreRequest extends FormRequest
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
        return [
            'score' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'result' => ['nullable', 'string', Rule::in([
                PpdbRegistration::STATUS_PASSED,
                PpdbRegistration::STATUS_FAILED,
                PpdbRegistration::STATUS_WAITLISTED,
            ])],
            'status' => ['nullable', 'string', Rule::in(PpdbSelectionParticipant::STATUSES)],
            'attended_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'score.numeric' => 'Nilai harus berupa angka.',
            'score.min' => 'Nilai tidak boleh negatif.',
            'result.in' => 'Hasil tahap harus passed, failed, atau waitlisted.',
            'status.in' => 'Status peserta tidak dikenali.',
            'attended_at.date' => 'Waktu kehadiran harus berupa tanggal & jam yang sah.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'score' => 'nilai',
            'result' => 'hasil',
            'attended_at' => 'waktu hadir',
        ];
    }
}
