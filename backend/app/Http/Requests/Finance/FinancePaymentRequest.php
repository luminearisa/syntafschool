<?php

namespace App\Http\Requests\Finance;

use App\Models\FinancePayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Catat pembayaran (§19). Pembayaran harus dialokasikan ke sebuah invoice
 * (langsung atau lewat billing-nya) — `invoice_id` atau `billing_id` wajib ada.
 * Aturan akun kas/bank per metode ditegakkan di `FinancePaymentService` agar
 * pesan errornya berbasis domain, bukan duplikat di dua tempat.
 */
class FinancePaymentRequest extends FormRequest
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
            'invoice_id' => ['nullable', 'integer', Rule::exists('finance_invoices', 'id')],
            'billing_id' => ['nullable', 'integer', Rule::exists('finance_billings', 'id')],
            'allocations' => ['nullable', 'array', 'min:1'],
            'allocations.*.invoice_id' => ['required', 'integer', Rule::exists('finance_invoices', 'id')],
            'allocations.*.amount' => ['required', 'numeric', 'min:0.01'],
            'customer_type' => ['nullable', Rule::in(['student', 'employee', 'guardian', 'vendor', 'other'])],
            'customer_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(FinancePayment::METHODS)],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')],
            'cash_account_id' => ['nullable', 'integer', Rule::exists('finance_cash_accounts', 'id')->whereNull('deleted_at')],
            'bank_account_id' => ['nullable', 'integer', Rule::exists('finance_bank_accounts', 'id')->whereNull('deleted_at')],
            'cashier_session_id' => ['nullable', 'integer', Rule::exists('finance_cashier_sessions', 'id')],
            'wallet_id' => ['nullable', 'integer', Rule::exists('wallets', 'id')],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('finance_cost_centers', 'id')->whereNull('deleted_at')],
            'reference_number' => ['nullable', 'string', 'max:80'],
            'proof_path' => ['nullable', 'string', 'max:255'],
            'gateway' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $hasAllocations = filled($this->input('allocations'));

            if ($hasAllocations) {
                if ($this->filled('invoice_id') || $this->filled('billing_id')) {
                    $validator->errors()->add('allocations', 'Gunakan allocations saja, jangan dicampur dengan invoice_id/billing_id.');
                }

                return;
            }

            if ($this->filled('invoice_id') && $this->filled('billing_id')) {
                $validator->errors()->add('billing_id', 'Isi salah satu saja: invoice_id atau billing_id, bukan keduanya.');
            }

            if (! $this->filled('invoice_id') && ! $this->filled('billing_id')) {
                $validator->errors()->add('invoice_id', 'Pembayaran wajib dialokasikan ke sebuah invoice atau billing.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'invoice_id' => 'invoice',
            'billing_id' => 'billing',
            'customer_id' => 'customer',
            'amount' => 'nominal pembayaran',
            'method' => 'metode pembayaran',
            'cash_account_id' => 'akun kas',
            'bank_account_id' => 'akun bank',
            'wallet_id' => 'wallet',
            'paid_at' => 'tanggal bayar',
            'idempotency_key' => 'kunci idempotensi',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Nominal pembayaran wajib diisi.',
            'amount.min' => 'Nominal pembayaran harus lebih dari nol.',
            'method.required' => 'Metode pembayaran wajib dipilih.',
            'method.in' => 'Metode pembayaran tidak dikenal.',
        ];
    }
}
