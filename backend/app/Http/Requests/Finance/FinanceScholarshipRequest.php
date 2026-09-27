<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceScholarship;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat/perbarui beasiswa (§18). `type` fixed → `amount`; percentage → `percentage`
 * (0–100). `student_id` wajib; `fee_type_id` opsional (kosong = semua tagihan).
 * Status tidak diset di sini — dibuat draft, lalu lewat approval service.
 */
class FinanceScholarshipRequest extends FormRequest
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
            'student_id' => [$this->isMethod('post') ? 'required' : 'nullable', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],
            'type' => [$this->isMethod('post') ? 'required' : 'nullable', Rule::in([FinanceScholarship::TYPE_FIXED, FinanceScholarship::TYPE_PERCENTAGE])],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fee_type_id' => ['nullable', 'integer', Rule::exists('finance_fee_types', 'id')->whereNull('deleted_at')],
            'start_date' => [$this->isMethod('post') ? 'required' : 'nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $type = $this->input('type');

            if ($type === FinanceScholarship::TYPE_FIXED && (float) $this->input('amount', 0) <= 0) {
                $validator->errors()->add('amount', 'Beasiswa nominal harus lebih dari nol.');
            }

            if ($type === FinanceScholarship::TYPE_PERCENTAGE && (float) $this->input('percentage', 0) <= 0) {
                $validator->errors()->add('percentage', 'Beasiswa persentase harus lebih dari nol.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'student_id' => 'santri',
            'type' => 'jenis beasiswa',
            'amount' => 'nominal beasiswa',
            'percentage' => 'persentase beasiswa',
            'fee_type_id' => 'jenis tagihan',
            'start_date' => 'tanggal mulai',
            'end_date' => 'tanggal berakhir',
            'reason' => 'alasan/keterangan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_id.required' => 'Santri penerima beasiswa wajib dipilih.',
            'student_id.exists' => 'Santri tidak ditemukan.',
            'type.required' => 'Jenis beasiswa wajib dipilih (fixed atau percentage).',
            'start_date.required' => 'Tanggal mulai berlaku wajib diisi.',
            'end_date.after_or_equal' => 'Tanggal berakhir tidak boleh sebelum tanggal mulai.',
        ];
    }
}
