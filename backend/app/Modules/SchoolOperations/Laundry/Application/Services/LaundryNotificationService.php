<?php

namespace App\Modules\SchoolOperations\Laundry\Application\Services;

use App\Modules\SchoolOperations\Laundry\Domain\Contracts\NotificationProviderInterface;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\ParentProviderInterface;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\StudentProviderInterface;
use App\Modules\SchoolOperations\Laundry\Models\LaundryNotification;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrder;

/**
 * Notifikasi wali/santri (§45, §46, §74).
 *
 * Semua pesan keluar lewat service ini: template satu bentuk, dedupe per
 * order+tipe (kecuali reminder saldo), tercatat di `laundry_notifications`
 * sehingga jejak komunikasi ke orang tua dapat diaudit.
 */
class LaundryNotificationService
{
    /** Tipe yang boleh berulang untuk order yang sama. */
    private const REPEATABLE = ['INSUFFICIENT_BALANCE', 'LOW_BALANCE', 'PROCESSING'];

    public function __construct(
        private readonly NotificationProviderInterface $notifications,
        private readonly ParentProviderInterface $parents,
        private readonly StudentProviderInterface $students,
    ) {
    }

    /**
     * Kirim notifikasi terkait satu order ke wali santri.
     *
     * @param  array<string, mixed>  $variables
     */
    public function notifyOrder(LaundryOrder $order, string $type, array $variables = []): ?LaundryNotification
    {
        $dedupeKey = 'order:'.$order->id.':'.$type;
        if (! in_array($type, self::REPEATABLE, true)
            && LaundryNotification::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $studentName = $variables['student_name']
            ?? $this->students->snapshot((int) $order->student_id)['full_name']
            ?? 'santri';

        [$title, $body] = $this->template($type, $order, $studentName, $variables);

        $recipients = $this->parents->userIdsFor((int) $order->student_id);
        $result = $recipients !== []
            ? $this->notifications->send($recipients, [
                'type' => 'laundry_'.strtolower($type),
                'title' => $title,
                'body' => $body,
                'event' => $type,
                'payload' => ['order_id' => (int) $order->id, 'order_number' => (string) $order->order_number],
            ])
            : ['sent' => 0, 'skipped' => true, 'reason' => 'Penerima tidak ditemukan.'];

        return LaundryNotification::create([
            'laundry_order_id' => $order->id,
            'student_id' => $order->student_id,
            'notification_type' => $type,
            'title' => $title,
            'body' => $body,
            'recipients' => $recipients,
            'sent' => (int) ($result['sent'] ?? 0) > 0,
            'sent_at' => (int) ($result['sent'] ?? 0) > 0 ? now() : null,
            'provider' => $result['provider'] ?? null,
            'payload' => $variables,
            // Reminder boleh berulang: dedupe_key hanya untuk tipe sekali-kirim.
            'dedupe_key' => in_array($type, self::REPEATABLE, true) ? null : $dedupeKey,
        ]);
    }

    /** Notifikasi tanpa konteks order (mis. saldo rendah tanpa order aktif). */
    public function notifyStudentContext(int $studentId, string $type, string $title, string $body, array $payload = []): ?LaundryNotification
    {
        $recipients = $this->parents->userIdsFor($studentId);
        $result = $recipients !== []
            ? $this->notifications->send($recipients, [
                'type' => 'laundry_'.strtolower($type), 'title' => $title, 'body' => $body,
                'event' => $type, 'payload' => $payload,
            ])
            : ['sent' => 0, 'skipped' => true];

        return LaundryNotification::create([
            'laundry_order_id' => null,
            'student_id' => $studentId,
            'notification_type' => $type,
            'title' => $title,
            'body' => $body,
            'recipients' => $recipients,
            'sent' => (int) ($result['sent'] ?? 0) > 0,
            'sent_at' => (int) ($result['sent'] ?? 0) > 0 ? now() : null,
            'provider' => $result['provider'] ?? null,
            'payload' => $payload,
        ]);
    }

    /** Template pesan berbahasa Indonesia sesuai §74. */
    private function template(string $type, LaundryOrder $order, string $studentName, array $vars): array
    {
        $number = (string) $order->order_number;
        $weight = rtrim(rtrim(number_format((float) $order->net_weight, 3, ',', '.'), '0'), ',');
        $amount = 'Rp '.number_format((float) ($vars['amount'] ?? $order->payableAmount()), 0, ',', '.');

        return match ($type) {
            'ORDER_RECEIVED' => ['Laundry diterima',
                "Laundry ananda {$studentName} telah diterima sebanyak {$weight} kg ({$number})."],
            'PAYMENT_REQUIRED' => ['Laundry membutuhkan pembayaran',
                "Laundry membutuhkan pembayaran {$amount}. Saldo Wallet santri saat ini ".($vars['balance_text'] ?? '-').'.'],
            'PAYMENT_COMPLETED' => ['Pembayaran laundry berhasil',
                "Laundry ananda menggunakan ".($vars['paid_text'] ?? 'layanan berbayar')." dengan biaya {$amount}. Saldo Wallet telah dipotong".(isset($vars['reference']) ? " (ref {$vars['reference']})" : '').'.'],
            'PROCESSING' => ['Laundry sedang diproses', "Laundry ananda {$studentName} ({$number}) sedang diproses."],
            'READY' => ['Laundry siap diambil', "Laundry ananda {$studentName} telah selesai dan siap diambil ({$number})."],
            'DELIVERED' => ['Laundry telah dikembalikan', "Laundry ananda {$studentName} telah dikembalikan kepada santri ({$number})."],
            'INSUFFICIENT_BALANCE' => ['Saldo Wallet tidak mencukupi',
                "Saldo Wallet tidak mencukupi untuk pembayaran laundry {$number} ({$amount}). Saldo saat ini ".($vars['balance_text'] ?? '-').'.'],
            'LOW_BALANCE' => ['Saldo Wallet hampir habis',
                'Saldo Wallet santri hampir habis. Saldo saat ini: '.($vars['balance_text'] ?? '-')],
            'INCIDENT' => ['Insiden laundry', "Terdapat insiden ".($vars['incident_type'] ?? 'laundry')." pada order {$number}. ".($vars['incident_note'] ?? '')],
            default => ['Laundry', "Pembaruan laundry {$number}."],
        };
    }
}
