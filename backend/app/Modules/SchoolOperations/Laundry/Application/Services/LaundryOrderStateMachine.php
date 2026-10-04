<?php

namespace App\Modules\SchoolOperations\Laundry\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Laundry\Domain\Exceptions\LaundryDomainException;
use App\Modules\SchoolOperations\Laundry\Domain\Support\OrderStatus;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrder;
use App\Modules\SchoolOperations\Laundry\Models\LaundryProcessingLog;

/**
 * Mesin transisi status order (Rule 16/26/28).
 *
 * Satu-satunya tempat yang memindahkan `laundry_orders.status`: validasi peta
 * transisi, tulis `laundry_processing_logs`, audit, dan publikasikan event
 * domain yang sesuai. Service lain (order/payment/processing/delivery) memakai
 * kelas ini sehingga jejak status selalu konsisten.
 */
class LaundryOrderStateMachine
{
    /** Event domain per status tujuan. */
    private const EVENT_MAP = [
        OrderStatus::RECEIVED => \App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryReceived::class,
        OrderStatus::WEIGHED => \App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryWeighed::class,
        OrderStatus::PAYMENT_REQUIRED => \App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryPaymentRequired::class,
        OrderStatus::QUEUED => \App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryQueued::class,
        OrderStatus::WASHING => \App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryWashingStarted::class,
        OrderStatus::DRYING => \App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryDryingStarted::class,
        OrderStatus::IRONING => \App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryIroningStarted::class,
        OrderStatus::PACKAGED => \App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryPackaged::class,
        OrderStatus::READY => \App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryReady::class,
        OrderStatus::DELIVERED => \App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryDelivered::class,
        OrderStatus::COMPLETED => \App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryCompleted::class,
    ];

    public function __construct(private readonly LaundryAuditService $audit)
    {
    }

    public function transitionTo(
        LaundryOrder $order,
        string $to,
        ?User $actor = null,
        ?string $reason = null,
        ?string $reference = null,
    ): LaundryOrder {
        $from = (string) $order->status;

        if ($from === $to) {
            return $order;
        }

        if (! OrderStatus::can($from, $to)) {
            throw new LaundryDomainException(
                "Transisi status tidak diizinkan: {$from} → {$to} (order {$order->order_number}).",
                ['order_id' => (int) $order->id, 'from' => $from, 'to' => $to]
            );
        }

        $order->status = $to;
        if ($to === OrderStatus::COMPLETED) {
            $order->completed_at = now();
        }
        if ($to === OrderStatus::CANCELLED) {
            $order->cancelled_at = now();
            $order->cancellation_reason = $reason;
        }
        $order->save();

        LaundryProcessingLog::create([
            'laundry_order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor?->id,
            'reason' => $reason,
            'reference' => $reference,
        ]);

        $this->audit->record(
            'ORDER_STATUS_CHANGED',
            $order,
            $actor,
            ['status' => $from],
            ['status' => $to],
            $reason,
            $reference ?? $order->order_number,
        );

        $this->publish($order, $from, $to);

        return $order;
    }

    private function publish(LaundryOrder $order, string $from, string $to): void
    {
        // Pelipatan selesai ditandai saat meninggalkan FOLDING ke QC.
        if ($from === OrderStatus::FOLDING && $to === OrderStatus::QUALITY_CHECK) {
            event(new \App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryFoldingCompleted((int) $order->id, [
                'order_number' => $order->order_number,
            ]));
        }

        $eventClass = self::EVENT_MAP[$to] ?? null;
        if ($eventClass !== null) {
            event(new $eventClass((int) $order->id, [
                'order_number' => $order->order_number,
                'student_id' => (int) $order->student_id,
                'from' => $from,
            ]));
        }
    }
}
