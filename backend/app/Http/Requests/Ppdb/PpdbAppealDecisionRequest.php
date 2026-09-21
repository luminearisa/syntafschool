<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbRegistrationAppeal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Keputusan panitia atas sanggahan orang tua.
 *
 * `review_note` **wajib**: keputusan tanpa catatan tidak bisa dipertanggung-
 * jawabkan di jejak audit. Kolom `decision` memakai nilai model
 * (`accepted`/`rejected`) agar tidak ada padanan kata yang perlu ditebak UI.
 */
class PpdbAppealDecisionRequest extends FormRequest
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
            'decision' => ['required', 'string', Rule::in([
                PpdbRegistrationAppeal::STATUS_ACCEPTED,
                PpdbRegistrationAppeal::STATUS_REJECTED,
            ])],
            'review_note' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decision.required' => 'Keputusan sanggahan wajib dipilih.',
            'decision.in' => 'Keputusan harus accepted atau rejected.',
            'review_note.required' => 'Catatan pemeriksaan wajib ditulis sebagai dasar keputusan.',
            'review_note.min' => 'Catatan pemeriksaan minimal :min karakter.',
            'review_note.max' => 'Catatan pemeriksaan maksimal :max karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'decision' => 'keputusan',
            'review_note' => 'catatan pemeriksaan',
        ];
    }
}
