<?php

namespace App\Modules\Mutation\Http\Requests;

use App\Modules\Mutation\Models\StudentTransfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pembaruan permohonan mutasi. Hanya berlaku untuk permohonan berstatus draf
 * (ditegakkan di StudentTransferService).
 */
class UpdateStudentTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['sometimes', 'integer', 'exists:students,id'],
            'type' => ['sometimes', Rule::in(StudentTransfer::TYPES)],
            'academic_year_id' => ['sometimes', 'integer', 'exists:academic_years,id'],
            'from_class_id' => ['sometimes', 'nullable', 'integer', 'exists:school_classes,id'],
            'to_class_id' => ['sometimes', 'nullable', 'integer', 'exists:school_classes,id'],
            'from_school_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'to_school_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'request_date' => ['sometimes', 'date'],
            'effective_date' => ['sometimes', 'nullable', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'document_number' => [
                'sometimes', 'nullable', 'string', 'max:50',
                Rule::unique('student_transfers', 'document_number')->ignore($this->route('transfer')?->id),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'student_id' => 'siswa',
            'type' => 'jenis mutasi',
            'academic_year_id' => 'tahun ajaran',
            'from_class_id' => 'kelas asal',
            'to_class_id' => 'kelas tujuan',
            'from_school_name' => 'nama sekolah asal',
            'to_school_name' => 'nama sekolah tujuan',
            'request_date' => 'tanggal permohonan',
            'effective_date' => 'tanggal berlaku',
            'reason' => 'alasan',
            'document_number' => 'nomor dokumen',
            'notes' => 'catatan',
        ];
    }
}
