<?php

namespace App\Modules\Asset\Application\Services;

use App\Modules\Asset\Domain\Enums\AssetCondition;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\BookingStatus;
use App\Modules\Asset\Domain\Enums\FacilityStatus;
use App\Modules\Asset\Domain\Enums\LoanStatus;
use App\Modules\Asset\Domain\Enums\MaintenanceRequestStatus;
use App\Modules\Asset\Domain\Enums\StockOpnameStatus;
use App\Modules\Asset\Domain\Enums\WorkOrderStatus;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetDisposalRequest;
use App\Modules\Asset\Models\AssetLoan;
use App\Modules\Asset\Models\AssetStockOpname;
use App\Modules\Asset\Models\DamageReport;
use App\Modules\Asset\Models\Facility;
use App\Modules\Asset\Models\FacilityBooking;
use App\Modules\Asset\Models\MaintenanceRequest;
use App\Modules\Asset\Models\MaintenanceWorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * KPI & laporan: aset, pemeliharaan, fasilitas, dan stok opname.
 *
 * Semua angka dihitung dari tabel yang sama dengan yang dipakai layar dan
 * layanan — bukan dari tabel ringkasan yang dapat melenceng. Untuk laporan yang
 * memang mahal (pemanfaatan fasilitas harian) agregatnya disimpan, dan itu pun
 * diperbarui pada saat pemakaian selesai.
 */
class AssetKpiService
{
    /** @return array<string, mixed> */
    public function assetDashboard(): array
    {
        $total = Asset::query()->count();

        return [
            'total_assets' => $total,
            'active_assets' => Asset::query()->whereIn('status', [AssetStatus::ACTIVE->value, AssetStatus::ASSIGNED->value])->count(),
            'new_assets_30d' => Asset::query()->where('registered_at', '>=', now()->subDays(30))->count(),
            'by_category' => $this->grouped('asset_categories', 'category_id'),
            'by_location' => $this->grouped('locations', 'location_id'),
            'by_condition' => $this->countsByColumn('condition'),
            'by_status' => $this->countsByColumn('status'),
            'under_maintenance' => Asset::query()->where('status', AssetStatus::IN_MAINTENANCE->value)->count(),
            'lost' => Asset::query()->where('status', AssetStatus::LOST->value)->count(),
            'damaged' => Asset::query()->whereIn('condition', [AssetCondition::DAMAGED->value, AssetCondition::CRITICAL->value, AssetCondition::UNUSABLE->value])->count(),
            'on_loan' => Asset::query()->whereNotNull('active_loan_id')->count(),
            'warranty_expiring_90d' => Asset::query()->whereNotNull('warranty_end')->whereBetween('warranty_end', [now(), now()->addDays(90)])->count(),
            'end_of_life_12m' => Asset::query()->whereNotNull('expected_life_end')->whereBetween('expected_life_end', [now(), now()->addMonths(12)])->count(),
            'utilization' => [
                'loans_open' => AssetLoan::query()->whereIn('status', [LoanStatus::ACTIVE->value, LoanStatus::OVERDUE->value, LoanStatus::HANDED_OVER->value])->count(),
                'loans_total' => AssetLoan::query()->count(),
                'loan_count_sum' => (int) Asset::query()->sum('loan_count'),
                'maintenance_count_sum' => (int) Asset::query()->sum('maintenance_count'),
                'downtime_days_sum' => (int) floor(((int) Asset::query()->sum('downtime_minutes')) / 1440),
            ],
            'maintenance_cost_total' => (string) MaintenanceWorkOrder::query()->sum('total_cost'),
        ];
    }

    /** @return array<string, mixed> */
    public function maintenanceDashboard(): array
    {
        $openRequests = MaintenanceRequest::query()->whereNotIn('status', [
            MaintenanceRequestStatus::CLOSED->value, MaintenanceRequestStatus::CANCELLED->value, MaintenanceRequestStatus::REJECTED->value,
        ])->count();

        $averageHours = MaintenanceWorkOrder::query()
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->get()
            ->avg(fn (MaintenanceWorkOrder $wo) => $wo->started_at?->diffInMinutes($wo->completed_at) / 60);

        return [
            'open_requests' => $openRequests,
            'open_work_orders' => MaintenanceWorkOrder::query()->whereIn('status', [
                WorkOrderStatus::OPEN->value, WorkOrderStatus::ASSIGNED->value, WorkOrderStatus::IN_PROGRESS->value, WorkOrderStatus::ON_HOLD->value,
            ])->count(),
            'overdue_work_orders' => MaintenanceWorkOrder::query()
                ->whereIn('status', [WorkOrderStatus::OPEN->value, WorkOrderStatus::ASSIGNED->value, WorkOrderStatus::IN_PROGRESS->value, WorkOrderStatus::ON_HOLD->value])
                ->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            'overdue_requests' => MaintenanceRequest::query()
                ->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())->whereNull('completed_at')->count(),
            'average_resolution_hours' => $averageHours === null ? null : round((float) $averageHours, 2),
            'maintenance_cost_total' => (string) MaintenanceWorkOrder::query()->sum('total_cost'),
            'cost_by_type' => [
                'labor' => (string) MaintenanceWorkOrder::query()->sum('labor_cost'),
                'parts' => (string) MaintenanceWorkOrder::query()->sum('parts_cost'),
                'external' => (string) MaintenanceWorkOrder::query()->sum('external_cost'),
                'transport' => (string) MaintenanceWorkOrder::query()->sum('transport_cost'),
                'other' => (string) MaintenanceWorkOrder::query()->sum('other_cost'),
            ],
            'preventive_compliance' => $this->preventiveCompliance(),
            'repeat_failures' => MaintenanceWorkOrder::query()
                ->select('asset_id', DB::raw('count(*) as total'))
                ->whereNotNull('asset_id')
                ->groupBy('asset_id')
                ->havingRaw('count(*) > 1')
                ->get()
                ->count(),
            'downtime_days' => (int) floor(((int) MaintenanceWorkOrder::query()->sum('downtime_minutes')) / 1440),
        ];
    }

    /** @return array<string, mixed> */
    public function facilityDashboard(): array
    {
        return [
            'total_facilities' => Facility::query()->count(),
            'active_facilities' => Facility::query()->where('status', FacilityStatus::ACTIVE->value)->count(),
            'maintenance_facilities' => Facility::query()->where('status', FacilityStatus::MAINTENANCE->value)->count(),
            'closed_facilities' => Facility::query()->where('status', FacilityStatus::CLOSED->value)->count(),
            'bookings_total' => FacilityBooking::query()->count(),
            'bookings_open' => FacilityBooking::query()->whereIn('status', [
                BookingStatus::REQUESTED->value, BookingStatus::RESERVED->value, BookingStatus::IN_USE->value,
            ])->count(),
            'bookings_completed_30d' => FacilityBooking::query()->where('status', BookingStatus::COMPLETED->value)->where('completed_at', '>=', now()->subDays(30))->count(),
            'peak_hours' => FacilityBooking::query()
                ->where('status', BookingStatus::COMPLETED->value)
                ->select(DB::raw("strftime('%H', start_at) as hour"), DB::raw('count(*) as total'))
                ->groupBy('hour')
                ->orderByDesc('total')
                ->limit(3)
                ->get()
                ->map(fn ($row): array => ['hour' => $row->hour.':00', 'bookings' => (int) $row->total])
                ->all(),
            'utilization_rate' => round((float) Facility::query()->avg('utilization_rate'), 2),
            'damage_reports_open' => DamageReport::query()->whereNotIn('status', ['RESOLVED', 'REJECTED'])->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function stockOpnameSummary(): array
    {
        $completed = AssetStockOpname::query()->where('status', StockOpnameStatus::COMPLETED->value)->count();

        return [
            'sessions_total' => AssetStockOpname::query()->count(),
            'sessions_open' => AssetStockOpname::query()->whereNotIn('status', [StockOpnameStatus::COMPLETED->value, StockOpnameStatus::CANCELLED->value])->count(),
            'sessions_completed' => $completed,
            'variance_total' => DB::table('asset_stock_opname_variances')->count(),
            'variance_pending' => DB::table('asset_stock_opname_variances')->where('decision', 'PENDING')->count(),
            'missing_total' => DB::table('asset_stock_opname_variances')->where('variance_type', 'MISSING')->count(),
            'wrong_location_total' => DB::table('asset_stock_opname_variances')->where('variance_type', 'WRONG_LOCATION')->count(),
            'wrong_custodian_total' => DB::table('asset_stock_opname_variances')->where('variance_type', 'WRONG_CUSTODIAN')->count(),
            'damaged_total' => DB::table('asset_stock_opname_variances')->where('variance_type', 'DAMAGED')->count(),
            'last_completed_at' => AssetStockOpname::query()->where('status', StockOpnameStatus::COMPLETED->value)->max('finished_at'),
        ];
    }

    /** Rekonsiliasi: penerimaan pengadaan vs aset vs keuangan vs hitung fisik. */
    public function reconciliation(): array
    {
        return [
            'assets_registered' => Asset::query()->count(),
            'assets_without_finance' => Asset::query()->whereNull('finance_asset_id')->where('capitalization_decision', 'ASSET')->count(),
            'assets_without_location' => Asset::query()->whereNull('location_id')->count(),
            'assets_without_custodian' => Asset::query()->whereNull('custodian_id')->count(),
            'assets_on_loan' => Asset::query()->whereNotNull('active_loan_id')->count(),
            'assets_in_maintenance' => Asset::query()->where('status', AssetStatus::IN_MAINTENANCE->value)->count(),
            'pending_integration' => DB::table('asset_integration_outbox')->where('status', 'PENDING')->count(),
            'disposal_pending' => AssetDisposalRequest::query()->whereIn('status', ['SUBMITTED', 'INSPECTED', 'VALUED', 'APPROVED'])->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function report(string $kind, array $filters = []): array
    {
        return match ($kind) {
            'by-category' => $this->reportBy('asset_categories', 'category_id', $filters),
            'by-location' => $this->reportBy('locations', 'location_id', $filters),
            'by-condition' => $this->reportSimple('condition', $filters),
            'by-status' => $this->reportSimple('status', $filters),
            'by-custodian' => $this->reportBy('\App\Models\User', 'custodian_id', $filters),
            'age' => $this->reportAge($filters),
            'value' => $this->reportValue($filters),
            'disposal' => $this->reportDisposal($filters),
            'maintenance-cost' => $this->reportMaintenanceCost($filters),
            'facility-utilization' => $this->reportFacilityUtilization($filters),
            'stock-opname' => $this->reportStockOpname($filters),
            default => ['kind' => $kind, 'rows' => [], 'note' => 'Jenis laporan tidak dikenali.'],
        };
    }

    /** @return list<array<string, mixed>> */
    public function exportRows(string $kind, array $filters = []): array
    {
        return $this->report($kind, $filters)['rows'] ?? [];
    }

    protected function preventiveCompliance(): ?float
    {
        $schedules = DB::table('maintenance_schedules')->where('is_active', true)->count();

        if ($schedules === 0) {
            return null;
        }

        $onTime = DB::table('maintenance_schedules')
            ->where('is_active', true)
            ->where('next_due_at', '>=', now())
            ->count();

        return round($onTime / $schedules * 100, 2);
    }

    protected function countsByColumn(string $column): array
    {
        return Asset::query()
            ->select($column, DB::raw('count(*) as total'))
            ->groupBy($column)
            ->get()
            ->map(fn ($row): array => ['key' => $row->{$column} instanceof \BackedEnum ? $row->{$column}->value : (string) $row->{$column}, 'total' => (int) $row->total])
            ->all();
    }

    protected function grouped(string $table, string $foreignKey): array
    {
        return DB::table('assets')
            ->leftJoin($table, $table.'.id', '=', 'assets.'.$foreignKey)
            ->select(DB::raw("coalesce($table.name, 'Tanpa kategori') as label"), DB::raw('count(*) as total'))
            ->groupBy('label')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'total' => (int) $row->total])
            ->all();
    }

    protected function reportBy(string $table, string $foreignKey, array $filters): array
    {
        // Ekspresi SQL dibangun SEKALI sebagai string, lalu dibungkus DB::raw.
        // Menggabungkan objek Expression dengan string (atau sebaliknya) adalah
        // cara tercepat membuat laporan 500 dengan pesan yang tidak menolong.
        $expression = str_contains($table, 'User')
            ? "coalesce(users.name, 'Tanpa pemegang')"
            : "coalesce($table.name, '-')";

        $query = DB::table('assets')
            ->leftJoin($table, $table.'.id', '=', 'assets.'.$foreignKey)
            ->select(
                DB::raw($expression.' as label'),
                DB::raw('count(*) as total'),
                DB::raw('coalesce(sum(assets.acquisition_cost), 0) as value'),
            )
            ->groupBy(DB::raw($expression));

        $this->applyAssetFilters($query, $filters, 'assets');

        return ['kind' => $foreignKey, 'rows' => $query->get()->map(fn ($row): array => [
            'label' => $row->label, 'total' => (int) $row->total, 'value' => (string) $row->value,
        ])->all()];
    }

    protected function reportSimple(string $column, array $filters): array
    {
        $query = DB::table('assets')
            ->select($column.' as label', DB::raw('count(*) as total'), DB::raw('coalesce(sum(acquisition_cost), 0) as value'))
            ->groupBy($column);

        $this->applyAssetFilters($query, $filters, 'assets');

        return ['kind' => $column, 'rows' => $query->get()->map(fn ($row): array => [
            'label' => $row->label, 'total' => (int) $row->total, 'value' => (string) $row->value,
        ])->all()];
    }

    protected function reportAge(array $filters): array
    {
        $rows = [];

        foreach (['<1 tahun' => 12, '1–3 tahun' => 36, '3–5 tahun' => 60, '>5 tahun' => 1200] as $label => $months) {
            $query = Asset::query()->whereNotNull('purchase_date');

            $query->where('purchase_date', '>=', now()->subMonths($months));

            if ($months === 1200) {
                $query = Asset::query()->whereNotNull('purchase_date')->where('purchase_date', '<', now()->subMonths(60));
            }

            $rows[] = ['label' => $label, 'total' => $query->count()];
        }

        return ['kind' => 'age', 'rows' => $rows];
    }

    protected function reportValue(array $filters): array
    {
        return ['kind' => 'value', 'rows' => [[
            'acquisition_cost' => (string) Asset::query()->sum('acquisition_cost'),
            'accumulated_depreciation' => (string) Asset::query()->sum('accumulated_depreciation'),
            'book_value' => (string) Asset::query()->sum(DB::raw('coalesce(current_book_value, acquisition_cost)')),
            'residual_value' => (string) Asset::query()->sum('residual_value'),
        ]]];
    }

    protected function reportDisposal(array $filters): array
    {
        $query = AssetDisposalRequest::query()
            ->select('reason', 'method', DB::raw('count(*) as total'), DB::raw('sum(coalesce(expected_proceeds, 0)) as proceeds'))
            ->groupBy('reason', 'method');

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        return ['kind' => 'disposal', 'rows' => $query->get()->map(fn ($row): array => [
            'reason' => $row->reason, 'method' => $row->method, 'total' => (int) $row->total, 'proceeds' => (string) $row->proceeds,
        ])->all()];
    }

    protected function reportMaintenanceCost(array $filters): array
    {
        $query = MaintenanceWorkOrder::query()
            ->select(
                DB::raw("coalesce(strftime('%Y-%m', completed_at), 'belum selesai') as period"),
                DB::raw('count(*) as total'),
                DB::raw('sum(total_cost) as cost'),
                DB::raw('sum(downtime_minutes) as downtime'),
            )
            ->groupBy('period')
            ->orderBy('period');

        return ['kind' => 'maintenance-cost', 'rows' => $query->get()->map(fn ($row): array => [
            'period' => $row->period, 'total' => (int) $row->total, 'cost' => (string) $row->cost,
            'downtime_hours' => round((int) $row->downtime / 60, 2),
        ])->all()];
    }

    protected function reportFacilityUtilization(array $filters): array
    {
        $query = FacilityBooking::query()
            ->select('facility_id', DB::raw('count(*) as total'), DB::raw('sum(attendee_count) as attendees'))
            ->where('status', BookingStatus::COMPLETED->value)
            ->groupBy('facility_id');

        if (isset($filters['from'])) {
            $query->where('start_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('start_at', '<=', $filters['to']);
        }

        return ['kind' => 'facility-utilization', 'rows' => $query->get()->map(function ($row): array {
            $facility = Facility::query()->find($row->facility_id);

            return [
                'facility_code' => $facility?->facility_code,
                'facility' => $facility?->name,
                'bookings' => (int) $row->total,
                'attendees' => (int) $row->attendees,
                'capacity' => $facility?->capacity_total,
            ];
        })->all()];
    }

    protected function reportStockOpname(array $filters): array
    {
        $query = DB::table('asset_stock_opname_variances')
            ->select('variance_type', 'decision', DB::raw('count(*) as total'))
            ->groupBy('variance_type', 'decision');

        return ['kind' => 'stock-opname', 'rows' => $query->get()->map(fn ($row): array => [
            'variance_type' => $row->variance_type, 'decision' => $row->decision, 'total' => (int) $row->total,
        ])->all()];
    }

    protected function applyAssetFilters($query, array $filters, string $alias = 'assets'): void
    {
        if (isset($filters['status'])) {
            $query->where($alias.'.status', $filters['status']);
        }

        if (isset($filters['condition'])) {
            $query->where($alias.'.condition', $filters['condition']);
        }

        if (isset($filters['category_id'])) {
            $query->where($alias.'.category_id', (int) $filters['category_id']);
        }

        if (isset($filters['location_id'])) {
            $query->where($alias.'.location_id', (int) $filters['location_id']);
        }
    }
}
