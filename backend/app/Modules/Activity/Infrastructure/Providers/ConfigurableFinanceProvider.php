<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Models\FinanceInvoice;
use App\Modules\Activity\Domain\Contracts\FinanceProviderInterface;

/**
 * Permintaan tagihan aktivitas ke modul Keuangan (§62, §97).
 *
 * Dua mode, dipilih lewat konfigurasi:
 *  - `create_invoices = false` (bawaan): modul hanya **mencatat permintaan**
 *    (`activity_charges`) supaya bendahara memutuskan; tidak ada tagihan yang
 *    tiba-tiba muncul di buku besar.
 *  - `create_invoices = true`: permintaan langsung menjadi draf tagihan
 *    (`finance_invoices` + satu baris item) sesuai skema Keuangan yang ada.
 *
 * Kebijakan siswa yang belum membayar dibaca dari konfigurasi `unpaid_policy`,
 * bukan ditulis tetap di kode.
 */
class ConfigurableFinanceProvider implements FinanceProviderInterface
{
    public function charge(array $payload): array
    {
        if (! config('activity.finance.create_invoices', false)) {
            return [
                'status' => 'REQUESTED',
                'reference' => null,
                'mode' => 'queue',
            ];
        }

        $invoice = FinanceInvoice::query()->create([
            'invoice_number' => $this->nextInvoiceNumber(),
            'customer_type' => 'student',
            'student_id' => $payload['student_id'],
            'issue_date' => $payload['issue_date'] ?? now()->toDateString(),
            'due_date' => $payload['due_date'] ?? now()->addDays((int) config('activity.finance.due_days', 14))->toDateString(),
            'subtotal' => $payload['amount'] ?? 0,
            'total' => $payload['amount'] ?? 0,
            'remaining_amount' => $payload['amount'] ?? 0,
            'status' => 'ISSUED',
            'notes' => $payload['description'] ?? null,
        ]);

        return [
            'status' => 'INVOICED',
            'reference' => (string) $invoice->invoice_number,
            'invoice_id' => (int) $invoice->id,
            'mode' => 'invoice',
        ];
    }

    public function chargeStatus(string $reference): ?array
    {
        $invoice = FinanceInvoice::query()->where('invoice_number', $reference)->first();

        if ($invoice === null) {
            return null;
        }

        return [
            'status' => $invoice->status,
            'total' => (float) $invoice->total,
            'paid_amount' => (float) $invoice->paid_amount,
            'remaining_amount' => (float) $invoice->remaining_amount,
            'is_paid' => (float) $invoice->remaining_amount <= 0,
        ];
    }

    public function unpaidPolicy(): array
    {
        return [
            'policy' => config('activity.finance.unpaid_policy', 'WARN'),
            'grace_days' => (int) config('activity.finance.grace_days', 7),
        ];
    }

    protected function nextInvoiceNumber(): string
    {
        $prefix = 'ACT-INV-'.now()->format('Y').'-';

        $last = FinanceInvoice::query()
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('invoice_number');

        $sequence = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
