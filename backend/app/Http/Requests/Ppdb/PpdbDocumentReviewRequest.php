<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbApplicantDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Peninjauan dokumen oleh panitia.
 *
 * Status `uploaded` adalah milik calon (hasil unggah), jadi tinjauan hanya boleh
 * menghasilkan under_review / approved / rejected. Alasan wajib saat menolak
 * supaya calon tahu apa yang harus diperbaiki.
 */
class PpdbDocumentReviewRequest extends FormRequest
{
    /** Status hasil tinjauan panitia. */
    public const REVIEW_STATUSES = [
        PpdbApplicantDocument::STATUS_UNDER_REVIEW,
        PpdbApplicantDocument::STATUS_APPROVED,
        PpdbApplicantDocument::STATUS_REJECTED,
    ];

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
            'status' => ['required', 'string', Rule::in(self::REVIEW_STATUSES)],
            'note' => [
                'required_if:status,'.PpdbApplicantDocument::STATUS_REJECTED,
                'nullable',
                'string',
                'min:5',
                'max:1000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => 'hasil tinjauan',
            'note' => 'catatan penolakan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Hasil tinjauan harus salah satu: under_review, approved, atau rejected.',
            'note.required_if' => 'Alasan penolakan wajib diisi agar calon tahu bagian mana yang harus diperbaiki.',
            'note.min' => 'Alasan penolakan terlalu singkat (minimal :min karakter).',
        ];
    }
}
