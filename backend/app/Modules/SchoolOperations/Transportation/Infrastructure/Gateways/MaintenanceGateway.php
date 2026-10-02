<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\MaintenanceGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Integrasi Maintenance (§57): kendaraan berstatus MAINTENANCE atau memiliki
 * work order terbuka tidak boleh ditugaskan (kecuali override berwenang).
 */
class MaintenanceGateway implements MaintenanceGatewayInterface
{
    use GatewaySupport;

    protected const OPEN_STATUSES = ['OPEN', 'IN_PROGRESS', 'SCHEDULED', 'PENDING', 'ASSIGNED', 'WAITING_PARTS'];

    public function statusForAsset(?int $assetId, ?int $vehicleId = null): array
    {
        $open = $this->openWorkOrders($assetId, $vehicleId);
        $nextDue = null;

        if ($assetId && $this->has('maintenance_schedules')) {
            $nextDue = DB::table('maintenance_schedules')
                ->where('asset_id', $assetId)
                ->where('is_active', true)
                ->orderBy('next_due_at')
                ->value('next_due_at');
        }

        $asset = $assetId ? $this->row('assets', $assetId) : null;

        return [
            'in_maintenance' => $open !== [],
            'open_work_orders' => $open,
            'open_work_order_count' => count($open),
            'next_maintenance_at' => $nextDue ?? ($asset['next_maintenance_at'] ?? null),
            'last_maintenance_at' => $asset['last_maintenance_at'] ?? null,
            'available' => $this->available(),
        ];
    }

    public function isBlocked(?int $assetId, ?int $vehicleId = null): bool
    {
        return $this->openWorkOrders($assetId, $vehicleId) !== [];
    }

    public function openWorkOrders(?int $assetId, ?int $vehicleId = null): array
    {
        if (! $this->has('maintenance_work_orders')) {
            return [];
        }

        $columns = $this->columns('maintenance_work_orders');
        $query = DB::table('maintenance_work_orders')->whereIn('status', self::OPEN_STATUSES);

        if ($assetId && in_array('asset_id', $columns, true)) {
            $query->where('asset_id', $assetId);
        } elseif ($vehicleId) {
            $reference = $this->pick($columns, ['reference', 'notes', 'description']);
            if ($reference) {
                $query->where($reference, 'like', '%VEHICLE-'.$vehicleId.'%');
            } else {
                return [];
            }
        }

        return array_values(array_map(fn ($row): array => [
            'id' => (int) $row->id,
            'work_order_number' => $row->work_order_number ?? null,
            'status' => $row->status ?? null,
            'type' => $row->type ?? null,
            'priority' => $row->priority ?? null,
            'due_at' => $row->due_at ?? null,
            'title' => $row->title ?? null,
        ], $query->limit(20)->get()->all()));
    }

    /** Mengajukan permintaan pemeliharaan (mis. temuan pasca-trip). */
    public function createRequest(array $payload): ?array
    {
        if (! $this->has('maintenance_requests')) {
            return null;
        }

        $columns = $this->columns('maintenance_requests');
        $data = array_intersect_key($payload, array_flip($columns));
        $data['created_at'] = now();
        $data['updated_at'] = now();
        $data['status'] = $data['status'] ?? 'SUBMITTED';
        if (in_array('reference', $columns, true)) {
            $data['reference'] = $data['reference'] ?? ('TMS-'.now()->format('ymdHis'));
        }
        // Kolom wajib pada skema pemeliharaan diisi wajar agar temuan lapangan tetap terkirim.
        $defaults = [
            'request_number' => 'TMS-MR-'.now()->format('ymdHis'),
            'requested_by' => auth()->id() ?? 1,
            'reported_at' => now(),
            'status' => 'SUBMITTED',
            'priority' => 'NORMAL',
            'type' => 'CORRECTIVE',
        ];
        foreach ($defaults as $column => $value) {
            if (in_array($column, $columns, true) && ! array_key_exists($column, $data)) {
                $data[$column] = $value;
            }
        }

        $id = DB::table('maintenance_requests')->insertGetId($data);

        return ['id' => $id, 'reference' => $data['reference'] ?? ($data['request_number'] ?? null), 'status' => $data['status']];
    }

    public function available(): bool
    {
        return $this->has('maintenance_work_orders') || $this->has('maintenance_requests');
    }
}
