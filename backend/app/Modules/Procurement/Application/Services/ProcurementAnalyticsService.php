<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Enums\ExceptionStatus;
use App\Modules\Procurement\Domain\Enums\InvoiceStatus;
use App\Modules\Procurement\Domain\Enums\MatchStatus;
use App\Modules\Procurement\Domain\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Domain\Enums\PurchaseRequestStatus;
use App\Modules\Procurement\Domain\Enums\RfqStatus;
use App\Modules\Procurement\Domain\Enums\VendorStatus;
use App\Modules\Procurement\Models\InvoiceException;
use App\Modules\Procurement\Models\ProcurementIntegration;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Models\Rfq;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Models\VendorInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard, analitik, dan laporan pengadaan (§40–§42, §58).
 *
 * Seluruh angka **dihitung saat diminta** dari data transaksional — tidak ada
 * tabel ringkasan tersimpan yang bisa basi. Angka finansial final tetap milik
 * Accounting; yang di sini adalah gambaran proses pengadaan.
 */
class ProcurementAnalyticsService
{
    /** KPI ringkas untuk dasbor (§40). */
    public function dashboard(): array
    {
        return [
            'kpi' => [
                'open_pr' => PurchaseRequest::query()->whereIn('status', [
                    PurchaseRequestStatus::SUBMITTED->value,
                    PurchaseRequestStatus::UNDER_REVIEW->value,
                    PurchaseRequestStatus::APPROVED->value,
                ])->count(),
                'pending_approval_pr' => PurchaseRequest::query()->where('status', PurchaseRequestStatus::SUBMITTED->value)->count(),
                'open_rfq' => Rfq::query()->whereIn('status', [
                    RfqStatus::ISSUED->value, RfqStatus::OPEN->value, RfqStatus::PARTIALLY_RESPONDED->value,
                ])->count(),
                'open_po' => PurchaseOrder::query()->whereIn('status', [
                    PurchaseOrderStatus::ISSUED->value,
                    PurchaseOrderStatus::VENDOR_CONFIRMED->value,
                    PurchaseOrderStatus::PARTIALLY_RECEIVED->value,
                ])->count(),
                'pending_invoice' => VendorInvoice::query()->whereIn('status', [
                    InvoiceStatus::RECEIVED->value,
                    InvoiceStatus::UNDER_MATCHING->value,
                    InvoiceStatus::EXCEPTION->value,
                ])->count(),
                'matching_exceptions' => InvoiceException::query()->open()->count(),
                'pending_accounting' => ProcurementIntegration::query()
                    ->where('provider', 'ACCOUNTING')
                    ->whereIn('status', ['PENDING', 'RETRYING', 'FAILED'])
                    ->count(),
                'overdue_delivery' => PurchaseOrder::query()
                    ->whereIn('status', [PurchaseOrderStatus::ISSUED->value, PurchaseOrderStatus::VENDOR_CONFIRMED->value, PurchaseOrderStatus::PARTIALLY_RECEIVED->value])
                    ->whereDate('expected_delivery_date', '<', now()->toDateString())
                    ->count(),
                'procurement_spend' => (float) PurchaseOrder::query()
                    ->whereNotIn('status', [PurchaseOrderStatus::CANCELLED->value, PurchaseOrderStatus::REJECTED->value, PurchaseOrderStatus::DRAFT->value])
                    ->sum('grand_total'),
            ],
            'vendors' => [
                'active' => Vendor::query()->where('status', VendorStatus::ACTIVE->value)->count(),
                'total' => Vendor::query()->count(),
                'restricted' => Vendor::query()->whereIn('classification', ['RESTRICTED', 'BLACKLISTED'])->count(),
            ],
            'matching' => [
                'matched' => VendorInvoice::query()->where('match_status', MatchStatus::MATCHED->value)->count(),
                'mismatch' => VendorInvoice::query()->where('match_status', MatchStatus::MISMATCH->value)->count(),
                'match_rate' => $this->matchRate(),
            ],
            'monthly' => $this->monthlySpend(),
        ];
    }

    /** Waktu siklus antar tahap (§41). */
    public function cycleTimes(): array
    {
        $approved = PurchaseRequest::query()
            ->whereNotNull('submitted_at')
            ->whereNotNull('approved_at')
            ->get(['submitted_at', 'approved_at']);

        $prToApproval = $approved->avg(fn ($r) => Carbon::parse($r->submitted_at)->diffInHours(Carbon::parse($r->approved_at)));

        $issued = PurchaseOrder::query()
            ->whereNotNull('issued_at')
            ->whereNotNull('confirmed_at')
            ->get(['issued_at', 'confirmed_at']);

        $poToConfirm = $issued->avg(fn ($o) => Carbon::parse($o->issued_at)->diffInHours(Carbon::parse($o->confirmed_at)));

        return [
            'pr_submit_to_approve_hours' => round((float) $prToApproval, 2),
            'po_issue_to_confirm_hours' => round((float) $poToConfirm, 2),
        ];
    }

    /** Analitik belanja (§42). */
    public function spendAnalytics(array $filters = []): array
    {
        $from = $filters['from'] ?? now()->startOfYear()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        $base = fn () => PurchaseOrder::query()
            ->whereBetween('po_date', [$from, $to])
            ->whereNotIn('status', [PurchaseOrderStatus::CANCELLED->value, PurchaseOrderStatus::REJECTED->value, PurchaseOrderStatus::DRAFT->value]);

        $byVendor = $base()->select('vendor_id', DB::raw('SUM(grand_total) as total'))
            ->groupBy('vendor_id')->with('vendor:id,legal_name')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($row): array => ['vendor' => $row->vendor?->legal_name, 'total' => (float) $row->total])->all();

        $byMethod = $base()->select('status', DB::raw('SUM(grand_total) as total'))
            ->groupBy('status')->get()
            ->map(fn ($row): array => ['status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status, 'total' => (float) $row->total])->all();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'total' => (float) $base()->sum('grand_total'),
            'by_vendor' => $byVendor,
            'by_status' => $byMethod,
            'by_department' => $base()->select('department_id', DB::raw('SUM(grand_total) as total'))
                ->groupBy('department_id')->orderByDesc('total')->get()
                ->map(fn ($row): array => ['department_id' => $row->department_id, 'total' => (float) $row->total])->all(),
        ];
    }

    /** Laporan per modul (§58). */
    public function report(string $type, array $filters = []): array
    {
        $from = $filters['from'] ?? now()->startOfYear()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        return match ($type) {
            'pr' => PurchaseRequest::query()->whereBetween('created_at', [$from, $to])
                ->select('status', DB::raw('COUNT(*) as jumlah'), DB::raw('SUM(estimated_total) as total'))
                ->groupBy('status')->get()->toArray(),
            'rfq' => Rfq::query()->whereBetween('created_at', [$from, $to])
                ->select('status', DB::raw('COUNT(*) as jumlah'))->groupBy('status')->get()->toArray(),
            'po' => PurchaseOrder::query()->whereBetween('po_date', [$from, $to])
                ->select('status', DB::raw('COUNT(*) as jumlah'), DB::raw('SUM(grand_total) as total'))
                ->groupBy('status')->get()->toArray(),
            'invoice' => VendorInvoice::query()->whereBetween('invoice_date', [$from, $to])
                ->select('status', DB::raw('COUNT(*) as jumlah'), DB::raw('SUM(total) as total'))
                ->groupBy('status')->get()->toArray(),
            'vendor' => Vendor::query()
                ->select('status', DB::raw('COUNT(*) as jumlah'))->groupBy('status')->get()->toArray(),
            default => [],
        };
    }

    protected function matchRate(): float
    {
        $total = VendorInvoice::query()->whereNotNull('match_status')->count();

        if ($total === 0) {
            return 0.0;
        }

        $matched = VendorInvoice::query()->where('match_status', MatchStatus::MATCHED->value)->count();

        return round($matched / $total * 100, 2);
    }

    /** @return list<array{month: string, total: float}> */
    protected function monthlySpend(int $months = 6): array
    {
        $rows = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $total = PurchaseOrder::query()
                ->whereYear('po_date', $month->year)
                ->whereMonth('po_date', $month->month)
                ->whereNotIn('status', [PurchaseOrderStatus::CANCELLED->value, PurchaseOrderStatus::REJECTED->value, PurchaseOrderStatus::DRAFT->value])
                ->sum('grand_total');

            $rows[] = ['month' => $month->format('Y-m'), 'total' => (float) $total];
        }

        return $rows;
    }
}
