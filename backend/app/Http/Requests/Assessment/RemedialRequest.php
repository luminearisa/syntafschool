<?php

namespace App\Http\Requests\Assessment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Catatan remedial untuk satu siswa pada satu penilaian.
 */
class RemedialRequest extends FormRequest
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
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'score_before' => ['nullable', 'numeric', 'min:0'],
            'score_after' => ['nullable', 'numeric', 'min:0'],
            'date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_id' => 'siswa',
            'score_before' => 'nilai awal',
            'score_after' => 'nilai remedial',
            'date' => 'tanggal remedial',
            'notes' => 'catatan',
        ];
    }
}
