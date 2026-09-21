<?php

namespace App\Http\Requests\Assignment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pemberian nilai pada sebuah pengumpulan.
 *
 * Batas atas `score` tidak ditegakkan di sini karena bergantung pada
 * `max_score` tugas — diperiksa di AssignmentSubmissionController.
 */
class GradeSubmissionRequest extends FormRequest
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
            'score' => ['required', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'score' => 'nilai',
            'feedback' => 'umpan balik',
        ];
    }
}
