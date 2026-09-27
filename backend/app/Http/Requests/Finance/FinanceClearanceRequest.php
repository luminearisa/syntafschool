<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceClearance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Permintaan keberesan finansial (§12 Phase 5).
 *
 * Pemakai hanya memilih santri dan keperluannya — TIDAK ada field nominal,
 * status, atau checklist di sini. Semuanya hasil pemeriksaan dokumen keuangan,
 * jadi memberi ruang bagi pemakai untuk menulisnya berarti membuka jalur
 * "keberesan bersih" yang tidak berdasar apa pun.
 */
class FinanceClearanceRequest extends FormRequest
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
            'student_id' => ['required', 'integer', 'min:1', Rule::exists('students', 'id')],
            'purpose' => ['required', 'string', Rule::in(FinanceClearance::PURPOSES)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_id' => 'santri',
            'purpose' => 'keperluan keberesan',
            'notes' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'purpose.in' => 'Keperluan keberesan harus salah satu: '.implode(', ', FinanceClearance::PURPOSES).'.',
        ];
    }
}
