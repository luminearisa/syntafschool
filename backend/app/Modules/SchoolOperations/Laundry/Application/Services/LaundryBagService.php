<?php

namespace App\Modules\SchoolOperations\Laundry\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Laundry\Domain\Exceptions\LaundryDomainException;
use App\Modules\SchoolOperations\Laundry\Models\LaundryBag;
use App\Modules\SchoolOperations\Laundry\Models\LaundryBagAssignment;
use Illuminate\Validation\ValidationException;

/**
 * Laundry bag (§16): siklus Student → Bag → Order → Processing → Return.
 * Tas punya identitas sendiri (kode/RFID/QR) dan tidak pernah dihapus —
 * hanya berpindah status.
 */
class LaundryBagService
{
    public function __construct(private readonly LaundryAuditService $audit)
    {
    }

    public function create(array $data, ?User $actor = null): LaundryBag
    {
        $bag = LaundryBag::create([
            'bag_code' => $data['bag_code'],
            'rfid_uid' => $data['rfid_uid'] ?? null,
            'qr_code' => $data['qr_code'] ?? null,
            'bag_type' => $data['bag_type'] ?? 'STANDARD',
            'capacity_kg' => $data['capacity_kg'] ?? null,
            'tare_weight' => $data['tare_weight'] ?? 0,
            'status' => 'AVAILABLE',
            'notes' => $data['notes'] ?? null,
        ]);

        $this->audit->record('BAG_CREATED', $bag, $actor, [], ['bag_code' => $bag->bag_code]);

        return $bag;
    }

    public function update(LaundryBag $bag, array $data, ?User $actor = null): LaundryBag
    {
        $before = $bag->only(['bag_code', 'rfid_uid', 'qr_code', 'bag_type', 'capacity_kg', 'tare_weight', 'status']);
        $bag->update(collect($data)->only([
            'bag_code', 'rfid_uid', 'qr_code', 'bag_type', 'capacity_kg', 'tare_weight', 'status', 'notes',
        ])->all());

        $this->audit->record('BAG_UPDATED', $bag, $actor, $before, $bag->only(array_keys($before)));

        return $bag;
    }

    /** Tautkan tas ke santri (satu tas aktif per penautan). */
    public function assign(LaundryBag $bag, int $studentId, ?User $actor = null, ?string $notes = null): LaundryBagAssignment
    {
        if (in_array($bag->status, ['RETIRED', 'REPAIR'], true)) {
            throw ValidationException::withMessages([
                'bag' => "Tas {$bag->bag_code} berstatus {$bag->status}.",
            ]);
        }

        $active = LaundryBagAssignment::query()
            ->where('laundry_bag_id', $bag->id)
            ->where('status', 'ACTIVE')
            ->where('student_id', '!=', $studentId)
            ->first();

        if ($active !== null) {
            throw new LaundryDomainException("Tas {$bag->bag_code} sedang dipegang santri lain (assignment #{$active->id}).");
        }

        $assignment = LaundryBagAssignment::firstOrCreate(
            ['laundry_bag_id' => $bag->id, 'student_id' => $studentId, 'status' => 'ACTIVE'],
            ['assigned_at' => now(), 'assigned_by' => $actor?->id, 'notes' => $notes]
        );

        if ($bag->status === 'AVAILABLE') {
            $bag->update(['status' => 'ASSIGNED']);
        }

        $this->audit->record('BAG_ASSIGNED', $bag, $actor, [], ['student_id' => $studentId]);

        return $assignment;
    }

    /** Kembalikan tas dari santri. */
    public function release(LaundryBag $bag, ?User $actor = null, string $status = 'RETURNED'): LaundryBag
    {
        LaundryBagAssignment::query()
            ->where('laundry_bag_id', $bag->id)
            ->where('status', 'ACTIVE')
            ->update(['status' => $status, 'returned_at' => now(), 'returned_by' => $actor?->id]);

        $bag->update(['status' => $status === 'LOST' ? 'RETIRED' : 'AVAILABLE']);

        $this->audit->record('BAG_RELEASED', $bag, $actor, [], ['status' => $bag->status]);

        return $bag;
    }
}
