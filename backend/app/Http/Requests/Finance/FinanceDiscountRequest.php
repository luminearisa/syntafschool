<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceDiscount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat/perbarui aturan diskon (§17). `value` = nominal (fixed) atau persen
 * (percentage). Batasan fee_type/program/santri/tanggal opsional; kosong = berlaku
 * umum. `withValidator` memastikan percentage dalam 0–100 dan rentang tanggal sah.
 */
class FinanceDiscountRequest extends FormRequest
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
            'name' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:120'],
            'type' => [$this->isMethod('post') ? 'required' : 'nullable', Rule::in([FinanceDiscount::TYPE_FIXED, FinanceDiscount::TYPE_PERCENTAGE])],
            'value' => [$this->isMethod('post') ? 'required' : 'nullable', 'numeric', 'min:0'],
            'fee_type_id' => ['nullable', 'integer', Rule::exists('finance_fee_types', 'id')->whereNull('deleted_at')],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')],
            'student_id' => ['nullable', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $type = $this->input('type');
            $value = $this->input('value');

            if ($type === FinanceDiscount::TYPE_PERCENTAGE && $value !== null && (float) $value > 100) {
                $validator->errors()->add('value', 'Diskon persentase maksimum 100%.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama diskon',
            'type' => 'jenis diskon',
            'value' => 'nilai diskon',
            'fee_type_id' => 'jenis tagihan',
            'program_id' => 'program',
            'student_id' => 'santri',
            'start_date' => 'tanggal mulai',
            'end_date' => 'tanggal berakhir',
            'is_active' => 'flag aktif',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama diskon wajib diisi.',
            'type.required' => 'Jenis diskon wajib dipilih (fixed atau percentage).',
            'type.in' => 'Jenis diskon harus fixed atau percentage.',
            'value.required' => 'Nilai diskon wajib diisi.',
            'end_date.after_or_equal' => 'Tanggal berakhir tidak boleh sebelum tanggal mulai.',
        ];
    }
}
