<?php

namespace App\Modules\SchoolOperations\Laundry\Application\Services;

use App\Modules\SchoolOperations\Laundry\Domain\Contracts\StudentProviderInterface;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\WalletProviderInterface;
use App\Modules\SchoolOperations\Laundry\Domain\Support\LaundryMath;
use App\Modules\SchoolOperations\Laundry\Domain\Support\OrderStatus;
use App\Modules\SchoolOperations\Laundry\Models\LaundryEntitlement;
use App\Modules\SchoolOperations\Laundry\Models\LaundryIncident;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrder;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPayment;
use App\Modules\SchoolOperations\Laundry\Models\LaundryProcessingStep;
use App\Modules\SchoolOperations\Laundry\Models\LaundryQualityCheck;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard & laporan laundry (§48–§53, §72, §73).
 *
 * Prinsip §50: laundry gratis TIDAK dihitung sebagai cash revenue — nilainya
 * dilaporkan terpisah sebagai "estimated value" (beban subsidi sekolah).
 * Saldo wallet selalu dibaca dari WalletProvider (Rule 10, §47).
 */
class LaundryAnalyticsService
{
    public function __construct(
        private readonly LaundryEntitlementService $entitlements,
        private readonly LaundryOrderService $orders,
        private readonly StudentProviderInterface $students,
        private readonly WalletProviderInterface $wallet,
    ) {
    }

    // =====================================================================
    // DASHBOARD ADMIN (§48)
    // =====================================================================

    public function dashboard(?string $date = null): array
    {
        $date = $date ?: now()->toDateString();

        $today = LaundryOrder::query()->whereDate('order_date', $date);

        $byStatus = LaundryOrder::query()
            ->whereNotIn('status', OrderStatus::TERMINAL)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'date' => $date,
            'today' => [
                'orders' => (clone $today)->count(),
                'received' => (clone $today)->where('status', OrderStatus::RECEIVED)->count(),
                'weight_kg' => LaundryMath::quantity((clone $today)->sum('net_weight')),
                'payment_required' => (clone $today)->where('payment_status', 'PAYMENT_REQUIRED')->count(),
                'completed' => (clone $today)->where('status', OrderStatus::COMPLETED)->count(),
                'cancelled' => (clone $today)->where('status', OrderStatus::CANCELLED)->count(),
            ],
            'pipeline' => [
                'processing' => (int) ($byStatus[OrderStatus::WASHING] ?? 0)
                    + (int) ($byStatus[OrderStatus::DRYING] ?? 0)
                    + (int) ($byStatus[OrderStatus::IRONING] ?? 0)
                    + (int) ($byStatus[OrderStatus::FOLDING] ?? 0),
                'washing' => (int) ($byStatus[OrderStatus::WASHING] ?? 0),
                'drying' => (int) ($byStatus[OrderStatus::DRYING] ?? 0),
                'ironing' => (int) ($byStatus[OrderStatus::IRONING] ?? 0),
                'folding' => (int) ($byStatus[OrderStatus::FOLDING] ?? 0),
                'quality_check' => (int) ($byStatus[OrderStatus::QUALITY_CHECK] ?? 0),
                'packaged' => (int) ($byStatus[OrderStatus::PACKAGED] ?? 0),
                'ready' => (int) ($byStatus[OrderStatus::READY] ?? 0),
                'queued' => (int) ($byStatus[OrderStatus::QUEUED] ?? 0),
                'delivered' => (int) ($byStatus[OrderStatus::DELIVERED] ?? 0),
            ],
            'incidents_open' => LaundryIncident::query()->whereIn('status', ['OPEN', 'INVESTIGATING'])->count(),
            'delayed' => LaundryOrder::query()
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->whereNotIn('status', OrderStatus::TERMINAL)
                ->count(),
        ];
    }

    // =====================================================================
    // OPERASIONAL HARIAN (§49)
    // =====================================================================

    public function dailyOperations(?string $date = null): array
    {
        $date = $date ?: now()->toDateString();

        $orders = LaundryOrder::query()->whereDate('order_date', $date)->get();

        $freeKg = 0.0;
        $paidKg = 0.0;
        foreach ($orders as $order) {
            if (($order->quota_type ?? 'WEIGHT') === 'WEIGHT') {
                $freeKg += (float) $order->free_quantity;
                $paidKg += (float) $order->paid_quantity;
            }
        }

        $revenue = LaundryPayment::query()
            ->where('status', 'PAID')
            ->whereDate('paid_at', $date)
            ->sum('amount');

        return [
            'date' => $date,
            'total_orders' => $orders->count(),
            'total_kg' => LaundryMath::quantity($orders->sum('net_weight')),
            'free_kg' => LaundryMath::quantity($freeKg),
            'paid_kg' => LaundryMath::quantity($paidKg),
            'paid_revenue' => LaundryMath::money($revenue),
            'wallet_transactions' => LaundryPayment::query()->whereDate('paid_at', $date)->count(),
            'completed' => $orders->where('status', OrderStatus::COMPLETED)->count(),
            'pending' => $orders->reject(fn (LaundryOrder $o) => OrderStatus::isTerminal((string) $o->status))->count(),
            'incidents' => LaundryIncident::query()->whereDate('created_at', $date)->count(),
        ];
    }

    // =====================================================================
    // FREE VS PAID (§50)
    // =====================================================================

    public function freeVsPaid(?string $from = null, ?string $to = null): array
    {
        $query = fn () => LaundryOrder::query()
            ->when($from, fn ($q) => $q->whereDate('order_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('order_date', '<=', $to))
            ->where('status', '!=', OrderStatus::CANCELLED);

        $orders = $query()->get();

        $freeKg = 0.0;
        $paidKg = 0.0;
        $freeValue = 0.0;
        foreach ($orders as $order) {
            if (($order->quota_type ?? 'WEIGHT') !== 'WEIGHT') {
                continue;
            }
            $freeKg += (float) $order->free_quantity;
            $paidKg += (float) $order->paid_quantity;

            // Estimasi nilai subsidi: porsi gratis × harga snapshot per kg.
            $snapshot = (array) ($order->pricing_snapshot ?? []);
            $unitPrice = 0.0;
            foreach ((array) ($snapshot['lines'] ?? []) as $line) {
                if (($line['kind'] ?? null) === 'WEIGHT') {
                    $unitPrice = (float) ($line['unit_price'] ?? 0);
                    break;
                }
            }
            $freeValue += (float) $order->free_quantity * $unitPrice;
        }

        $revenue = LaundryPayment::query()
            ->where('status', 'PAID')
            ->when($from, fn ($q) => $q->whereDate('paid_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('paid_at', '<=', $to))
            ->sum('amount');

        return [
            'period' => ['from' => $from, 'to' => $to],
            'free' => [
                'total_orders' => $orders->filter(fn (LaundryOrder $o) => (float) $o->free_quantity > 0)->count(),
                'total_kg' => LaundryMath::quantity($freeKg),
                // BUKAN cash revenue — nilai subsidi sekolah (§50).
                'estimated_value' => LaundryMath::money($freeValue),
            ],
            'paid' => [
                'total_orders' => $orders->filter(fn (LaundryOrder $o) => (float) $o->total_amount > 0)->count(),
                'total_kg' => LaundryMath::quantity($paidKg),
                'revenue' => LaundryMath::money($revenue),
            ],
        ];
    }

    // =====================================================================
    // QUOTA REPORT (§51)
    // =====================================================================

    public function quotaReport(?string $periodKey = null, array $filters = []): array
    {
        $rows = LaundryEntitlement::query()
            ->when($periodKey, fn ($q) => $q->where('period_key', $periodKey))
            ->when($filters['dormitory_id'] ?? null, function ($q, $dormitoryId): void {
                $q->whereIn('student_id', DB::table('boarding_placements')
                    ->where('dormitory_id', $dormitoryId)
                    ->where('status', 'active')
                    ->pluck('student_id'));
            })
            ->orderByDesc('period_start')
            ->orderBy('student_id')
            ->limit(1000)
            ->get();

        $snapshots = $this->students->snapshots($rows->pluck('student_id')->all());

        return $rows->map(function (LaundryEntitlement $e) use ($snapshots): array {
            $student = $snapshots[(int) $e->student_id] ?? [];

            return [
                'student_id' => (int) $e->student_id,
                'student_name' => $student['full_name'] ?? null,
                'nis' => $student['nis'] ?? null,
                'class_name' => $student['class_name'] ?? null,
                'level_name' => $student['level_name'] ?? null,
                'period_key' => (string) $e->period_key,
                'quota_type' => (string) $e->quota_type,
                'allocated' => LaundryMath::quantity($e->allocated_quota),
                'carry_in' => LaundryMath::quantity($e->carry_in_quota),
                'adjusted' => LaundryMath::quantity($e->adjusted_quota),
                'used' => LaundryMath::quantity($e->used_quota),
                'remaining' => $e->remaining(),
                'expired' => $e->status === 'EXPIRED',
                'status' => (string) $e->status,
            ];
        })->all();
    }

    // =====================================================================
    // STUDENT STATEMENT (§52, §72)
    // =====================================================================

    /**
     * Statement laundry satu santri untuk satu periode: entitlement +
     * transaksi order (free/paid + wallet ref) + total.
     */
    public function studentStatement(int $studentId, ?string $periodKey = null): array
    {
        $periodKey = $periodKey ?: $this->entitlements->currentState($studentId)['period']['key'] ?? now()->format('Y-m');

        $entitlement = LaundryEntitlement::query()
            ->where('student_id', $studentId)
            ->where('period_key', $periodKey)
            ->first();

        $orders = LaundryOrder::query()
            ->where('student_id', $studentId)
            ->where(fn ($q) => $entitlement === null
                ? $q->whereBetween('order_date', [now()->startOfMonth(), now()->endOfMonth()])
                : $q->whereBetween('order_date', [$entitlement->period_start, $entitlement->period_end]))
            ->orderBy('order_date')
            ->orderBy('id')
            ->get();

        $student = $this->students->snapshot($studentId);

        $transactions = $orders->map(function (LaundryOrder $order): array {
            $payment = LaundryPayment::query()
                ->where('laundry_order_id', $order->id)
                ->where('status', 'PAID')
                ->orderByDesc('id')
                ->first();

            return [
                'date' => $order->order_date?->toDateString(),
                'order_number' => (string) $order->order_number,
                'net_weight' => $order->net_weight !== null ? LaundryMath::quantity($order->net_weight) : null,
                'free' => LaundryMath::quantity($order->free_quantity),
                'paid' => LaundryMath::quantity($order->paid_quantity),
                'charge' => LaundryMath::money($order->payableAmount()),
                'wallet_ref' => $payment?->wallet_transaction_number,
                'wallet_before' => $payment?->balance_before !== null ? LaundryMath::money($payment->balance_before) : null,
                'wallet_after' => $payment?->balance_after !== null ? LaundryMath::money($payment->balance_after) : null,
                'status' => (string) $order->status,
                'payment_status' => (string) $order->payment_status,
            ];
        })->all();

        return [
            'student' => $student !== null ? [
                'id' => $student['id'], 'name' => $student['full_name'], 'nis' => $student['nis'],
            ] : ['id' => $studentId],
            'period' => $periodKey,
            'entitlement' => $entitlement !== null ? [
                'quota_type' => (string) $entitlement->quota_type,
                'allocated' => LaundryMath::quantity($entitlement->allocated_quota),
                'carry_in' => LaundryMath::quantity($entitlement->carry_in_quota),
                'adjusted' => LaundryMath::quantity($entitlement->adjusted_quota),
                'used' => LaundryMath::quantity($entitlement->used_quota),
                'remaining' => $entitlement->remaining(),
            ] : null,
            'transactions' => $transactions,
            'totals' => [
                'free_quantity' => LaundryMath::quantity(array_sum(array_column($transactions, 'free'))),
                'paid_quantity' => LaundryMath::quantity(array_sum(array_column($transactions, 'paid'))),
                'paid_amount' => LaundryMath::money(array_sum(array_column($transactions, 'charge'))),
                'remaining_quota' => $entitlement?->remaining() ?? 0.0,
            ],
            // §53/§47: saldo wallet dari provider, bukan dari tabel laundry.
            'wallet' => $this->wallet->accountFor($studentId),
        ];
    }

    // =====================================================================
    // WALLET & OPERATIONAL REPORT (§73)
    // =====================================================================

    public function walletReport(?string $from = null, ?string $to = null): array
    {
        return LaundryPayment::query()
            ->with('order:id,order_number,student_id')
            ->when($from, fn ($q) => $q->whereDate('paid_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('paid_at', '<=', $to))
            ->orderByDesc('id')
            ->limit(1000)
            ->get()
            ->map(fn (LaundryPayment $p): array => [
                'payment_number' => (string) $p->payment_number,
                'order_number' => $p->order?->order_number,
                'student_id' => $p->order?->student_id,
                'amount' => LaundryMath::money($p->amount),
                'method' => (string) $p->method,
                'status' => (string) $p->status,
                'wallet_transaction' => $p->wallet_transaction_number,
                'balance_before' => $p->balance_before !== null ? LaundryMath::money($p->balance_before) : null,
                'balance_after' => $p->balance_after !== null ? LaundryMath::money($p->balance_after) : null,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ])->all();
    }

    public function operationalReport(?string $from = null, ?string $to = null): array
    {
        $orders = LaundryOrder::query()
            ->when($from, fn ($q) => $q->whereDate('order_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('order_date', '<=', $to))
            ->get();

        $completed = $orders->filter(fn (LaundryOrder $o) => $o->status === OrderStatus::COMPLETED && $o->received_at && $o->completed_at);
        $avgHours = $completed->isEmpty() ? null : round($completed->avg(fn (LaundryOrder $o) => $o->received_at->diffInMinutes($o->completed_at) / 60), 2);

        $ordersPerOperatorRows = LaundryProcessingStep::query()
            ->when($from, fn ($q) => $q->whereDate('started_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('started_at', '<=', $to))
            ->whereNotNull('operator_id')
            ->select('operator_id', DB::raw('count(*) as steps'))
            ->groupBy('operator_id')
            ->get();

        $operatorNames = \App\Models\User::query()
            ->whereIn('id', $ordersPerOperatorRows->pluck('operator_id'))
            ->pluck('name', 'id');

        $ordersPerOperator = $ordersPerOperatorRows
            ->mapWithKeys(fn ($row) => [
                (string) ($operatorNames[$row->operator_id] ?? ('user#'.$row->operator_id)) => (int) $row->steps,
            ])
            ->all();

        $kgPerMachine = LaundryProcessingStep::query()
            ->when($from, fn ($q) => $q->whereDate('started_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('started_at', '<=', $to))
            ->whereNotNull('laundry_machine_id')
            ->join('laundry_orders', 'laundry_orders.id', '=', 'laundry_processing_steps.laundry_order_id')
            ->select('laundry_machine_id', DB::raw('sum(laundry_orders.net_weight) as kg'))
            ->groupBy('laundry_machine_id')
            ->pluck('kg', 'laundry_machine_id')
            ->mapWithKeys(fn ($kg, $machineId) => [(string) $machineId => LaundryMath::quantity($kg)])
            ->all();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'average_processing_hours' => $avgHours,
            'orders_per_operator' => $ordersPerOperator,
            'kg_per_machine' => $kgPerMachine,
            'delayed_orders' => $orders->filter(fn (LaundryOrder $o) => $o->due_at !== null
                && ! OrderStatus::isTerminal((string) $o->status)
                && $o->due_at->isPast())->count(),
            'qc_failures' => LaundryQualityCheck::query()
                ->whereIn('result', ['FAILED', 'REWORK'])
                ->when($from, fn ($q) => $q->whereDate('checked_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('checked_at', '<=', $to))
                ->count(),
            'lost_items' => LaundryIncident::query()->where('incident_type', 'MISSING')
                ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
                ->count(),
            'damaged_items' => LaundryIncident::query()->where('incident_type', 'DAMAGED')
                ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
                ->count(),
        ];
    }

    // =====================================================================
    // PORTAL (§25–§27, §71)
    // =====================================================================

    /**
     * Konteks portal wali/santri: jatah + pemakaian + sisa + saldo wallet
     * (dari provider) + ringkasan order terakhir.
     */
    public function portalContext(int $studentId): array
    {
        $quota = $this->entitlements->currentState($studentId);

        $recent = LaundryOrder::query()
            ->where('student_id', $studentId)
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (LaundryOrder $o): array => $this->orders->presentSummary($o))
            ->all();

        $paidTotal = LaundryPayment::query()
            ->whereHas('order', fn ($q) => $q->where('student_id', $studentId))
            ->where('status', 'PAID')
            ->sum('amount');

        return [
            'student' => $this->students->snapshot($studentId),
            'quota' => $quota,
            // §47: saldo dibaca dari WalletProvider, bukan tabel laundry.
            'wallet' => $this->wallet->accountFor($studentId),
            'paid_total' => LaundryMath::money($paidTotal),
            'recent_orders' => $recent,
        ];
    }
}
