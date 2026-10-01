<?php

namespace App\Modules\Asset\Application\Services;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetAuditLog;

/**
 * Garis waktu aset: satu riwayat yang dapat dibaca manusia.
 *
 * Bagian pentingnya bukan menampilkan banyak tabel, melainkan menyusunnya
 * kronologis dengan pertanyaan pemeriksa sebagai judul: apa yang terjadi, kapan,
 * oleh siapa, dari mana ke mana, dan mengapa. Inilah yang diminta saat audit:
 * "tunjukkan perjalanan lengkap barang ini".
 */
class AssetTimelineService
{
    public function __construct(private readonly AssetAuditService $audit) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forAsset(Asset $asset, int $limit = 200): array
    {
        $logs = AssetAuditLog::query()
            ->where(function ($query) use ($asset): void {
                $query->where(function ($inner) use ($asset): void {
                    $inner->where('asset_id', $asset->id);
                })->orWhere(function ($inner) use ($asset): void {
                    $inner->where('subject_type', $asset->getMorphClass())->where('subject_id', $asset->id);
                });
            })
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $logs->map(fn (AssetAuditLog $log): array => [
            'id' => $log->id,
            'action' => $log->action?->value ?? (string) $log->action,
            'action_label' => $log->action?->label(),
            'occurred_at' => $log->occurred_at?->toDateTimeString(),
            'actor' => $log->actor?->name,
            'actor_role' => $log->actor_role,
            'status_before' => $log->status_before,
            'status_after' => $log->status_after,
            'from' => $log->from_location,
            'to' => $log->to_location,
            'reason' => $log->reason,
            'context' => $log->context,
            'changes' => $log->before !== null || $log->after !== null
                ? ['before' => $log->before, 'after' => $log->after]
                : null,
        ])->all();
    }

    /** Ringkasan per bagian untuk halaman detail aset (tab demi tab). */
    public function summary(Asset $asset): array
    {
        return [
            'identification' => [
                'asset_code' => $asset->asset_code,
                'asset_number' => $asset->asset_number,
                'asset_tag' => $asset->asset_tag,
                'barcode' => $asset->barcode,
                'qr_token' => $asset->qr_token,
                'rfid' => $asset->rfid,
                'serial_number' => $asset->serial_number,
            ],
            'specification' => $asset->specifications()->pluck('value', 'key')->all(),
            'assignments' => $asset->custodianHistory()->with('custodian:id,name')->orderByDesc('assigned_at')->limit(20)->get()->toArray(),
            'transfers' => $asset->transfers()->with('transfer:id,transfer_number,status,to_location_id')->orderByDesc('id')->limit(20)->get()->toArray(),
            'loans' => $asset->loanItems()->with('loan:id,loan_number,status,due_at,borrower_id')->orderByDesc('id')->limit(20)->get()->toArray(),
            'maintenance' => $asset->workOrders()->orderByDesc('id')->limit(20)->get()->toArray(),
            'inspections' => $asset->inspections()->orderByDesc('id')->limit(20)->get()->toArray(),
            'damages' => $asset->damageReports()->orderByDesc('id')->limit(20)->get()->toArray(),
            'disposals' => $asset->disposalRequests()->orderByDesc('id')->limit(10)->get()->toArray(),
            'documents' => $asset->documents()->orderByDesc('id')->limit(30)->get()->toArray(),
            'counts' => [
                'loans' => (int) $asset->loan_count,
                'maintenance' => (int) $asset->maintenance_count,
                'downtime_minutes' => (int) $asset->downtime_minutes,
            ],
        ];
    }
}
