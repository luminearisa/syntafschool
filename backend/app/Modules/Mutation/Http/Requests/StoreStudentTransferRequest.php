<?php

namespace App\Modules\Mutation\Http\Requests;

use App\Modules\Mutation\Models\StudentTransfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Permohonan mutasi baru. Kelengkapan per jenis mutasi ditegakkan di
 * StudentTransferService agar aturannya satu sumber kebenaran.
 */
class StoreStudentTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'type' => ['required', Rule::in(StudentTransfer::TYPES)],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'from_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'to_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'from_school_name' => ['nullable', 'string', 'max:150'],
            'to_school_name' => ['nullable', 'string', 'max:150'],
            'request_date' => ['required', 'date'],
            'effective_date' => ['nullable', 'date', 'after_or_equal:request_date'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'document_number' => ['nullable', 'string', 'max:50', Rule::unique('student_transfers', 'document_number')],
            'notes' => ['nullable', 'string', 'max:2000'],
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
