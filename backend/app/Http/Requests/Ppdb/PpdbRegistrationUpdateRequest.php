<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbRegistration;
use App\Services\Ppdb\PpdbScopeService;

/**
 * Autosave draf pendaftaran dari portal.
 *
 * Menerima tiga bagian sekaligus (semuanya opsional):
 *   applicant → perbaikan biodata calon (kolom `ppdb_applicants`)
 *   fields    → jawaban form builder   (`fields.{field_key}`)
 *   questions → pertanyaan tambahan    (`questions.{question_id}`)
 *
 * Aturan "wajib" dilepas (lihat `PpdbAnswerBatchRequest`); kelengkapan baru
 * ditegakkan saat `submit`.
 */
class PpdbRegistrationUpdateRequest extends PpdbAnswerBatchRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var PpdbRegistration|null $registration */
        $registration = $this->registration();

        $rules = array_merge(parent::rules(), [
            'applicant' => ['sometimes', 'array'],
        ], PpdbApplicantRequest::nestedRules('applicant.', $registration?->applicant_id));

        // Kolom panitia tidak pernah diterima dari autosave portal.
        foreach (PpdbApplicantRequest::PORTAL_FORBIDDEN as $column) {
            unset($rules['applicant.'.$column]);
        }

        // AUTOSAVE: biodata boleh dikirim sebagian. Aturan kehadiran dilonggarkan
        // menjadi `sometimes` (tipe/panjang/keunikan tetap diperiksa saat nilainya
        // ada), sama seperti `PpdbAnswerService::typeOnly()` untuk jawaban field.
        // Kelengkapan biodata ditegakkan saat `submit` lewat
        // `PpdbRegistrationQueryService::missingApplicantData()`.
        foreach ($rules as $key => $list) {
            if (! str_starts_with((string) $key, 'applicant.')) {
                continue;
            }

            $rules[$key] = ['sometimes', ...array_values(array_filter(
                (array) $list,
                static fn (mixed $rule): bool => $rule !== 'required',
            ))];
        }

        return $rules;
    }

    /**
     * Nama field untuk pesan validasi: label field form builder & teks
     * pertanyaan, supaya pesan error menyebut istilah yang dilihat calon di
     * formulir — bukan `fields.nama_lengkap`.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'applicant.full_name' => 'nama lengkap calon',
            'applicant.nik' => 'NIK calon',
            'applicant.nisn' => 'NISN calon',
            'applicant.birth_date' => 'tanggal lahir calon',
            'fields' => 'jawaban formulir',
            'questions' => 'jawaban pertanyaan',
        ];

        foreach ($this->schemaItems('fields', 'key') as $key => $label) {
            $attributes['fields.'.$key] = $label;
        }

        foreach ($this->schemaItems('questions', 'id') as $id => $label) {
            $attributes['questions.'.$id] = $label;
        }

        return $attributes;
    }

    /**
     * @return array<string|int, string>
     */
    private function schemaItems(string $section, string $keyField): array
    {
        $registration = $this->registration();

        if ($registration === null) {
            return [];
        }

        $schema = app(PpdbScopeService::class)->schemaForRegistration($registration);
        $items = [];

        foreach ($schema[$section] ?? [] as $item) {
            $key = $item[$keyField] ?? null;

            if ($key !== null) {
                $items[$key] = (string) ($item['label'] ?? $item['question'] ?? $key);
            }
        }

        return $items;
    }
}
