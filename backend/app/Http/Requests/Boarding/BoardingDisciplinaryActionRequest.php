<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingDisciplinaryAction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Tindakan pendisiplinan manual (§38). `violation_id` opsional: tindakan bisa
 * menunjuk satu catatan, dan service memastikan catatan itu milik santri yang
 * sama.
 */
class BoardingDisciplinaryActionRequest extends FormRequest
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
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'student_id' => [$required, 'integer', 'min:1', 'exists:students,id'],
            'violation_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:boarding_violations,id'],
            'action' => [$required, 'string', 'in:'.implode(',', BoardingDisciplinaryAction::ACTIONS)],
            'date' => ['sometimes', 'nullable', 'date'],
            'officer' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', BoardingDisciplinaryAction::STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_id' => 'santri',
            'action' => 'jenis tindakan',
            'violation_id' => 'catatan pelanggaran',
            'officer' => 'petugas',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_id.required' => 'Santri yang ditindak wajib dipilih.',
            'action.required' => 'Jenis tindakan wajib dipilih (§38).',
            'action.in' => 'Jenis tindakan harus salah satu dari: '.implode(', ', BoardingDisciplinaryAction::ACTIONS).'.',
            'violation_id.exists' => 'Catatan pelanggaran tidak ditemukan.',
            'status.in' => 'Status tindakan harus salah satu dari: '.implode(', ', BoardingDisciplinaryAction::STATUSES).'.',
        ];
    }
}
