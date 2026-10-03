<?php

namespace App\Modules\SchoolOperations\Laundry\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\WalletProviderInterface;
use App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryIncidentCreated;
use App\Modules\SchoolOperations\Laundry\Domain\Exceptions\LaundryDomainException;
use App\Modules\SchoolOperations\Laundry\Domain\Support\LaundryMath;
use App\Modules\SchoolOperations\Laundry\Domain\Support\OrderStatus;
use App\Modules\SchoolOperations\Laundry\Models\LaundryEntitlement;
use App\Modules\SchoolOperations\Laundry\Models\LaundryIncident;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrder;
use Illuminate\Validation\ValidationException;

/**
 * Insiden & kompensasi (§39–§41, Rule 18).
 *
 * Item hilang/rusak WAJIB menghasilkan incident. Kompensasi tidak pernah
 * otomatis memotong/menambah saldo tanpa approval: `approveCompensation()`
 * mengeksekusi WALLET_REFUND (lewat WalletProvider) atau FREE_QUOTA (lewat
 * ledger entitlement) setelah disetujui.
 */
class LaundryIncidentService
{
    public function __construct(
        private readonly LaundryEntitlementService $entitlements,
        private readonly WalletProviderInterface $wallet,
        private readonly LaundryOrderStateMachine $state,
        private readonly LaundryNotificationService $notifications,
        private readonly LaundryAuditService $audit,
    ) {
    }

    /**
     * Laporkan insiden pada order (opsional pada item tertentu).
     * Bila tipe MISSING/DAMAGED, order dapat langsung ditandai LOST/DAMAGED.
     */
    public function report(LaundryOrder $order, array $data, ?User $actor = null): LaundryIncident
    {
        $type = strtoupper((string) ($data['incident_type'] ?? ''));
        if (! in_array($type, (array) config('laundry.incident_types', []), true)) {
            throw ValidationException::withMessages([
                'incident_type' => 'Jenis insiden tidak dikenal.',
            ]);
        }

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages(['description' => 'Deskripsi insiden wajib diisi.']);
        }

        $incident = LaundryIncident::create([
            'incident_number' => 'INC-TMP',
            'laundry_order_id' => $order->id,
            'laundry_order_item_id' => $data['laundry_order_item_id'] ?? null,
            'incident_type' => $type,
            'description' => $description,
            'evidence_path' => $data['evidence_path'] ?? null,
            'responsible_operator_id' => $data['responsible_operator_id'] ?? $actor?->id,
            'status' => 'OPEN',
            'created_by' => $actor?->id,
        ]);
        $incident->update(['incident_number' => 'INC-'.str_pad((string) $incident->id, 6, '0', STR_PAD_LEFT)]);

        // Rule 18 + §28: item hilang/rusak mengubah order ke status exception.
        $markOrder = (bool) ($data['mark_order'] ?? in_array($type, ['MISSING', 'DAMAGED'], true));
        if ($markOrder && ! OrderStatus::isTerminal((string) $order->status)) {
            $target = $type === 'MISSING' ? OrderStatus::LOST : OrderStatus::DAMAGED;
            $this->state->transitionTo($order->fresh(), $target, $actor, $description, $incident->incident_number);
        }

        $this->audit->record('INCIDENT_CREATED', $incident, $actor, [], [
            'order' => $order->order_number, 'type' => $type,
        ], $description, $incident->incident_number);

        event(new LaundryIncidentCreated((int) $order->id, [
            'order_number' => $order->order_number,
            'incident_number' => $incident->incident_number,
            'incident_type' => $type,
        ]));

        $this->notifications->notifyOrder($order, 'INCIDENT', [
            'incident_type' => $type,
            'incident_note' => $description,
        ]);

        return $incident->fresh();
    }

    public function updateStatus(LaundryIncident $incident, string $status, ?string $resolution = null, ?User $actor = null): LaundryIncident
    {
        $status = strtoupper($status);
        if (! in_array($status, (array) config('laundry.incident_statuses', []), true)) {
            throw ValidationException::withMessages(['status' => 'Status insiden tidak dikenal.']);
        }

        $before = ['status' => $incident->status];
        $incident->update([
            'status' => $status,
            'resolution' => $resolution ?? $incident->resolution,
        ]);

        $this->audit->record('INCIDENT_STATUS_CHANGED', $incident, $actor, $before, ['status' => $status], $resolution);

        return $incident;
    }

    /**
     * Usulkan kompensasi (§41) — belum dieksekusi sebelum approval.
     */
    public function proposeCompensation(LaundryIncident $incident, string $type, ?float $amount, ?User $actor = null): LaundryIncident
    {
        $type = strtoupper($type);
        if (! in_array($type, (array) config('laundry.compensation_types', []), true)) {
            throw ValidationException::withMessages(['compensation_type' => 'Jenis kompensasi tidak dikenal.']);
        }

        if (in_array($type, ['REFUND', 'CREDIT', 'WALLET_REFUND'], true) && LaundryMath::money($amount ?? 0) <= 0) {
            throw ValidationException::withMessages(['compensation_amount' => 'Nominal kompensasi finansial wajib diisi.']);
        }

        $incident->update(['compensation_type' => $type, 'compensation_amount' => $amount]);

        $this->audit->record('COMPENSATION_PROPOSED', $incident, $actor, [], [
            'type' => $type, 'amount' => $amount,
        ], 'Menunggu approval');

        return $incident;
    }

    /**
     * Setujui & eksekusi kompensasi. WALLET_REFUND lewat WalletProvider;
     * FREE_QUOTA lewat adjustment ledger entitlement (teraudit).
     */
    public function approveCompensation(LaundryIncident $incident, User $approver, ?string $note = null): LaundryIncident
    {
        if ($incident->compensation_type === null) {
            throw new LaundryDomainException('Insiden belum memiliki usulan kompensasi.');
        }

        if ($incident->status === 'CLOSED') {
            throw new LaundryDomainException('Insiden sudah ditutup.');
        }

        $order = $incident->order;
        $type = (string) $incident->compensation_type;
        $reference = null;

        if ($type === 'WALLET_REFUND' || $type === 'REFUND') {
            $amount = LaundryMath::money($incident->compensation_amount);
            $result = $this->wallet->refund((int) $order->student_id, $amount, 'compensation:incident-'.$incident->id, [
                'order_id' => (int) $order->id,
                'order_number' => (string) $order->order_number,
                'description' => "Kompensasi insiden {$incident->incident_number}",
                'reason' => $note ?? $incident->description,
                'actor_id' => $approver->id,
            ]);

            if (! ($result['success'] ?? false)) {
                throw new LaundryDomainException('Refund kompensasi gagal: '.($result['reason'] ?? 'UNKNOWN'));
            }

            $reference = (string) ($result['transaction_number'] ?? null);
        } elseif ($type === 'FREE_QUOTA') {
            $amount = LaundryMath::quantity($incident->compensation_amount);
            $state = $this->entitlements->adjust(
                (int) $order->student_id,
                $amount,
                "Kompensasi insiden {$incident->incident_number}".($note ? ": {$note}" : ''),
                $approver,
            );
            $reference = 'quota+'.$amount;
            unset($state);
        }

        $incident->update([
            'compensation_reference' => $reference,
            'status' => 'RESOLVED',
            'resolution' => $note ?? $incident->resolution,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        $this->audit->record('COMPENSATION_APPROVED', $incident, $approver, [], [
            'type' => $type, 'amount' => (float) $incident->compensation_amount, 'reference' => $reference,
        ], $note, $incident->incident_number);

        return $incident->fresh();
    }

    /** Daftar insiden (filter status/tipe/rentang) utk layar admin. */
    public function query(array $filters = [])
    {
        return LaundryIncident::query()
            ->with('order:id,order_number,student_id', 'item:id,item_type,quantity')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['incident_type'] ?? null, fn ($q, $v) => $q->where('incident_type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('id');
    }

    /** Entitlement model helper (dipakai approveCompensation FREE_QUOTA). */
    private function entitlementOf(int $studentId): ?LaundryEntitlement
    {
        return $this->entitlements->ensurePeriod($studentId);
    }
}
