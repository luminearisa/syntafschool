<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat billing manual (§13) — tagihan ad-hoc di luar paket (mis. denda, kegiatan
 * insidental). `customer_type`+`customer_id` wajib; `student_id` diisi bila
 * customer adalah santri. Minimal satu baris item bernominal positif.
 */
class FinanceBillingRequest extends FormRequest
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
            'customer_type' => ['nullable', Rule::in(['student', 'employee', 'guardian', 'vendor', 'other'])],
            'customer_id' => ['required', 'integer'],
            'student_id' => ['nullable', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('finance_cost_centers', 'id')->whereNull('deleted_at')],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.fee_type_id' => ['nullable', 'integer', Rule::exists('finance_fee_types', 'id')->whereNull('deleted_at')],
            'items.*.description' => ['required', 'string', 'max:200'],
            'items.*.amount' => ['required', 'numeric', 'min:0'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'customer_type' => 'jenis customer',
            'customer_id' => 'customer',
            'student_id' => 'santri',
            'cost_center_id' => 'cost center',
            'due_date' => 'jatuh tempo',
            'notes' => 'catatan',
            'items' => 'baris tagihan',
            'items.*.description' => 'deskripsi baris',
            'items.*.amount' => 'nominal baris',
            'items.*.quantity' => 'kuantitas',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'Customer penanggung tagihan wajib diisi.',
            'items.required' => 'Billing minimal punya satu baris tagihan.',
            'items.min' => 'Billing minimal punya satu baris tagihan.',
            'items.*.description.required' => 'Deskripsi setiap baris wajib diisi.',
            'items.*.amount.required' => 'Nominal setiap baris wajib diisi.',
        ];
    }
}
