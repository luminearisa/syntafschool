<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Models\{LostFoundItem, TripPassenger};
use Illuminate\Validation\ValidationException;

/**
 * Barang hilang & temuan (§71):
 * ditemukan → dicatat → diidentifikasi → wali diberi tahu → dikembalikan → ditutup.
 */
class LostFoundService
{
    public function __construct(
        private readonly TransportContext $context,
        private readonly TransportationNotificationService $notifications,
    ) {
    }

    public function record(array $payload): LostFoundItem
    {
        $item = LostFoundItem::query()->create([
            'item_code' => $payload['item_code'] ?? 'LF-'.now()->format('ymd').'-'.str_pad((string) (LostFoundItem::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'trip_id' => $payload['trip_id'] ?? null,
            'vehicle_id' => $payload['vehicle_id'] ?? null,
            'student_id' => $payload['student_id'] ?? null,
            'found_location' => $payload['found_location'] ?? null,
            'stop_id' => $payload['stop_id'] ?? null,
            'found_date' => $payload['found_date'] ?? now()->toDateString(),
            'item_name' => $payload['item_name'],
            'description' => $payload['description'] ?? null,
            'photo_path' => $payload['photo_path'] ?? null,
            'status' => 'FOUND',
            'received_by' => $this->context->actorId(),
        ]);

        $this->context->audit('LostFoundItemRecorded', $item, ['item_code' => $item->item_code]);

        return $item;
    }

    /** Identifikasi pemilik (manual atau dari kandidat penumpang trip). */
    public function identify(LostFoundItem $item, ?int $studentId = null): array
    {
        $candidates = [];
        if (! $studentId && $item->trip_id) {
            $candidates = TripPassenger::query()
                ->where('trip_id', $item->trip_id)
                ->whereNotNull('student_id')
                ->pluck('student_id')->unique()->values()->all();
        }

        if (! $studentId) {
            return [
                'identified' => false,
                'reason' => 'Pemilik belum ditentukan; berikut kandidat penumpang pada trip tersebut.',
                'candidate_student_ids' => $candidates,
            ];
        }

        $item->forceFill([
            'student_id' => $studentId,
            'status' => 'IDENTIFIED',
            'identified_by' => $this->context->actorId(),
            'identified_at' => now(),
        ])->save();

        $this->context->audit('LostFoundItemIdentified', $item, ['student_id' => $studentId]);

        return ['identified' => true, 'item_id' => $item->id, 'student_id' => $studentId, 'status' => $item->status];
    }

    public function notifyOwner(LostFoundItem $item): array
    {
        if (! $item->student_id) {
            throw ValidationException::withMessages(['student_id' => 'Pemilik belum diidentifikasi.']);
        }

        $notification = $this->notifications->toGuardians('LostItemFound', (int) $item->student_id,
            'Barang ditemukan di kendaraan',
            'Barang "'.$item->item_name.'" ditemukan dan diidentifikasi sebagai milik putra/putri Anda. Silakan ambil di ruang transportasi.',
            ['idempotency_key' => 'lostfound-'.$item->id]
        );

        $item->forceFill(['status' => 'NOTIFIED', 'owner_notified' => true])->save();

        return ['notified' => true, 'item_id' => $item->id, 'notification' => $notification];
    }

    public function returnItem(LostFoundItem $item, array $payload): array
    {
        $item->forceFill([
            'status' => 'RETURNED',
            'returned_at' => now(),
            'returned_to' => $payload['returned_to'] ?? $this->context->actorId(),
            'returned_to_name' => $payload['returned_to_name'] ?? null,
        ])->save();

        $this->context->audit('LostFoundItemReturned', $item, ['returned_to' => $item->returned_to_name]);

        return ['returned' => true, 'item_id' => $item->id, 'returned_at' => $item->returned_at];
    }

    public function close(LostFoundItem $item, string $status = 'UNCLAIMED'): array
    {
        $item->forceFill(['status' => strtoupper($status)])->save();
        $this->context->audit('LostFoundItemClosed', $item, ['status' => $item->status]);

        return ['closed' => true, 'item_id' => $item->id, 'status' => $item->status];
    }

    public function items(array $filters = []): array
    {
        return LostFoundItem::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', strtoupper($status)))
            ->when($filters['vehicle_id'] ?? null, fn ($q, $vehicleId) => $q->where('vehicle_id', $vehicleId))
            ->when($filters['trip_id'] ?? null, fn ($q, $tripId) => $q->where('trip_id', $tripId))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('found_date', '>=', $from))
            ->orderByDesc('id')
            ->limit((int) ($filters['limit'] ?? 100))
            ->get()
            ->map(fn (LostFoundItem $item): array => [
                'id' => $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'status' => $item->status,
                'found_date' => $item->found_date?->toDateString(),
                'trip_id' => $item->trip_id,
                'vehicle_id' => $item->vehicle_id,
                'student_id' => $item->student_id,
                'owner_notified' => (bool) $item->owner_notified,
                'returned_at' => $item->returned_at,
            ])
            ->all();
    }
}
