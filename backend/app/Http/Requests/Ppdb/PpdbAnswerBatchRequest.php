<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbRegistration;
use App\Services\Ppdb\PpdbAnswerService;
use App\Services\Ppdb\PpdbScopeService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi batch jawaban pendaftaran (form builder + pertanyaan tambahan).
 *
 * Kontrak input: `fields.{field_key}` dan `questions.{question_id}` — sama
 * persis dengan kunci aturan dari `PpdbScopeService::validationRules()`.
 *
 * Endpoint ini adalah AUTOSAVE draf, sehingga aturan "wajib" dilepas
 * (`PpdbAnswerService::typeOnly()`): tipe data, pilihan, dan panjang tetap
 * diperiksa supaya draf tidak menyimpan nilai yang salah bentuk.
 */
class PpdbAnswerBatchRequest extends FormRequest
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
        $rules = [
            'fields' => ['required_without_all:questions,applicant', 'array'],
            'questions' => ['sometimes', 'array'],
        ];

        foreach ($this->typeRules() as $key => $list) {
            $rules[$key] = $list;
        }

        return $rules;
    }

    /**
     * Aturan bertipe dari scope pendaftaran (field & pertanyaan aktif).
     *
     * @return array<string, list<string>>
     */
    public function typeRules(): array
    {
        $registration = $this->registration();

        if ($registration === null) {
            return [];
        }

        return PpdbAnswerService::typeOnly(app(PpdbScopeService::class)->validationRules(
            (int) $registration->period_id,
            $registration->program_id === null ? null : (int) $registration->program_id,
            $registration->wave_id === null ? null : (int) $registration->wave_id,
        ));
    }

    public function registration(): ?PpdbRegistration
    {
        $registration = $this->route('registration');

        return $registration instanceof PpdbRegistration ? $registration : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fields.array' => 'Format jawaban formulir tidak valid.',
            'questions.array' => 'Format jawaban pertanyaan tidak valid.',
        ];
    }
}
