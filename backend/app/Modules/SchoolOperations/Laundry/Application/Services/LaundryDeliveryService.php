<?php

namespace App\Modules\SchoolOperations\Laundry\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\RfidProviderInterface;
use App\Modules\SchoolOperations\Laundry\Domain\Exceptions\LaundryDomainException;
use App\Modules\SchoolOperations\Laundry\Domain\Support\OrderStatus;
use App\Modules\SchoolOperations\Laundry\Models\LaundryBag;
use App\Modules\SchoolOperations\Laundry\Models\LaundryDelivery;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrder;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPickup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Serah terima laundry (§43, §44, Rule 19/26).
 *
 * READY → DELIVERED → COMPLETED. Setiap serah terima wajib punya verifikasi
 * penerima (RFID/QR/PIN/STUDENT_ID/AUTHORIZED_PERSON) dan mencatat siapa,
 * kapan, metode, dan petugas — sehingga pakaian dapat ditelusuri sampai
 * kembali ke santri.
 */
class LaundryDeliveryService
{
    public function __construct(
        private readonly RfidProviderInterface $rfid,
        private readonly LaundryOrderStateMachine $state,
        private readonly LaundryNotificationService $notifications,
        private readonly LaundryAuditService $audit,
    ) {
    }

    /** PACKAGED → READY + notifikasi wali (§74). */
    public function markReady(LaundryOrder $order, ?User $actor = null): LaundryOrder
    {
        $this->state->transitionTo($order, OrderStatus::READY, $actor, null, $order->order_number);
        $this->notifications->notifyOrder($order, 'READY');

        return $order;
    }

    /**
     * Pengambilan di counter (§43 PICKUP). Verifikasi penerima wajib; setelah
     * terkonfirmasi order langsung COMPLETED (status final, Rule 26).
     */
    public function pickup(LaundryOrder $order, array $data, ?User $actor = null): array
    {
        $this->assertReadyForHandover($order);

        $method = strtoupper((string) ($data['verification_method'] ?? ''));
        $this->assertMethod($method);

        $value = (string) ($data['verification_value'] ?? '');
        $this->verify($order, $method, $value, (string) ($data['receiver_name'] ?? ''));

        $receiverName = trim((string) ($data['receiver_name'] ?? ''));
        if ($receiverName === '') {
            throw ValidationException::withMessages(['receiver_name' => 'Nama penerima wajib dicatat (Rule 19).']);
        }

        return DB::transaction(function () use ($order, $data, $actor, $method, $value, $receiverName): array {
            $pickup = LaundryPickup::create([
                'laundry_order_id' => $order->id,
                'receiver_name' => $receiverName,
                'verification_method' => $method,
                'verification_value' => $this->mask($method, $value),
                'verified_by' => $actor?->id,
                'picked_up_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->state->transitionTo($order, OrderStatus::DELIVERED, $actor, 'Pengambilan di counter', $pickup->id ? 'pickup#'.$pickup->id : null);
            $this->state->transitionTo($order, OrderStatus::COMPLETED, $actor, null, $order->order_number);

            $this->releaseBag($order);

            $this->audit->record('ORDER_PICKED_UP', $order, $actor, [], [
                'receiver' => $receiverName, 'method' => $method,
            ], null, $order->order_number);

            $this->notifications->notifyOrder($order, 'DELIVERED');

            return ['pickup' => $pickup, 'order' => $order->fresh()];
        });
    }

    /**
     * Pengantaran ke asrama (§43 DORMITORY_DELIVERY) dengan konfirmasi penerima.
     */
    public function deliver(LaundryOrder $order, array $data, ?User $actor = null): array
    {
        $this->assertReadyForHandover($order);

        $method = strtoupper((string) ($data['verification_method'] ?? ''));
        $this->assertMethod($method);

        $value = (string) ($data['verification_value'] ?? '');
        $this->verify($order, $method, $value, (string) ($data['receiver_name'] ?? ''));

        $receiverName = trim((string) ($data['receiver_name'] ?? ''));
        if ($receiverName === '') {
            throw ValidationException::withMessages(['receiver_name' => 'Nama penerima wajib dicatat (Rule 19).']);
        }

        return DB::transaction(function () use ($order, $data, $actor, $method, $value, $receiverName): array {
            $delivery = LaundryDelivery::create([
                'laundry_order_id' => $order->id,
                'dormitory_id' => $data['dormitory_id'] ?? $order->boarding_dormitory_id,
                'courier_id' => $data['courier_id'] ?? $actor?->id,
                'receiver_name' => $receiverName,
                'verification_method' => $method,
                'verification_value' => $this->mask($method, $value),
                'verified_by' => $actor?->id,
                'delivered_at' => now(),
                'confirmed_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->state->transitionTo($order, OrderStatus::DELIVERED, $actor, 'Pengantaran asrama', 'delivery#'.$delivery->id);
            $this->state->transitionTo($order, OrderStatus::COMPLETED, $actor, null, $order->order_number);

            $this->releaseBag($order);

            $this->audit->record('ORDER_DELIVERED', $order, $actor, [], [
                'receiver' => $receiverName, 'method' => $method, 'dormitory_id' => $delivery->dormitory_id,
            ], null, $order->order_number);

            $this->notifications->notifyOrder($order, 'DELIVERED');

            return ['delivery' => $delivery, 'order' => $order->fresh()];
        });
    }

    // =====================================================================
    // VERIFIKASI (§44)
    // =====================================================================

    private function verify(LaundryOrder $order, string $method, string $value, string $receiverName): void
    {
        switch ($method) {
            case 'RFID':
                $resolved = $value !== '' ? $this->rfid->resolve($value) : null;
                $ownerId = isset($resolved['student_id']) ? (int) $resolved['student_id'] : null;
                if ($ownerId !== (int) $order->student_id) {
                    throw ValidationException::withMessages([
                        'verification_value' => 'RFID tidak cocok dengan santri pemilik order.',
                    ]);
                }
                break;

            case 'QR':
                $label = DB::table('laundry_labels')->where('laundry_order_id', $order->id)->first();
                $valid = $value !== '' && ($value === (string) $order->order_number
                    || ($label !== null && ($value === (string) $label->label_number || $value === (string) $label->qr_payload)));
                if (! $valid) {
                    throw ValidationException::withMessages([
                        'verification_value' => 'QR/label tidak cocok dengan order ini.',
                    ]);
                }
                break;

            case 'PIN':
                // PIN default: 4 digit terakhir NIS (dapat diganti explicit pin order).
                $nis = (string) (DB::table('students')->where('id', $order->student_id)->value('nis') ?? '');
                $expected = $nis !== '' ? substr($nis, -4) : null;
                if ($expected === null || ! hash_equals($expected, trim($value))) {
                    throw ValidationException::withMessages(['verification_value' => 'PIN tidak cocok.']);
                }
                break;

            case 'STUDENT_ID':
                $nis = (string) (DB::table('students')->where('id', $order->student_id)->value('nis') ?? '');
                $nisn = (string) (DB::table('students')->where('id', $order->student_id)->value('nisn') ?? '');
                if (! in_array(trim($value), array_filter([$nis, $nisn, (string) $order->student_id]), true)) {
                    throw ValidationException::withMessages(['verification_value' => 'Identitas santri tidak cocok.']);
                }
                break;

            case 'AUTHORIZED_PERSON':
                // Penerima berkuasa (musyrif/wali): nama wajib dicatat; petugas
                // bertanggung jawab atas verifikasi manual.
                if (trim($receiverName) === '') {
                    throw ValidationException::withMessages([
                        'receiver_name' => 'Nama pihak berwenang wajib dicatat.',
                    ]);
                }
                break;

            default:
                $this->assertMethod($method);
        }
    }

    private function assertMethod(string $method): void
    {
        if (! in_array($method, (array) config('laundry.verification_methods', []), true)) {
            throw ValidationException::withMessages([
                'verification_method' => 'Metode verifikasi tidak dikenal.',
            ]);
        }
    }

    private function assertReadyForHandover(LaundryOrder $order): void
    {
        if ($order->status !== OrderStatus::READY && $order->status !== OrderStatus::DELIVERED) {
            throw new LaundryDomainException(
                "Order {$order->order_number} belum siap diserahterimakan (status {$order->status})."
            );
        }
    }

    /** Nilai verifikasi sensitif disamarkan sebelum disimpan. */
    private function mask(string $method, string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return in_array($method, ['RFID', 'PIN'], true)
            ? substr($value, 0, 2).str_repeat('*', max(0, strlen($value) - 2))
            : $value;
    }

    private function releaseBag(LaundryOrder $order): void
    {
        if ($order->laundry_bag_id === null) {
            return;
        }

        $bag = LaundryBag::query()->find($order->laundry_bag_id);
        if ($bag !== null && $bag->status === 'IN_USE') {
            $hasAssignment = DB::table('laundry_bag_assignments')
                ->where('laundry_bag_id', $bag->id)
                ->where('status', 'ACTIVE')
                ->exists();
            $bag->update(['status' => $hasAssignment ? 'ASSIGNED' : 'AVAILABLE']);
        }
    }
}
