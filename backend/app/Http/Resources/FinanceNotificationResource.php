<?php

namespace App\Http\Resources;

use App\Notifications\FinanceActivityNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notifikasi Keuangan pada kanal `database` bawaan Laravel.
 *
 * Baris `notifications.data` berisi `{type, title, body}` + konteks pengirim
 * (`invoice_id`, `payment_id`, `reference`, …), jadi respons diratakan ke
 * bentuk tetap agar frontend tidak perlu membaca kolom JSON mentah.
 *
 * @property DatabaseNotification $resource
 */
class FinanceNotificationResource extends JsonResource
{
    public const TYPE_LABELS = [
        FinanceActivityNotification::TYPE_INVOICE_ISSUED => 'Tagihan terbit',
        FinanceActivityNotification::TYPE_PAYMENT_RECEIVED => 'Pembayaran diterima',
        FinanceActivityNotification::TYPE_PAYMENT_PENDING_VERIFICATION => 'Pembayaran menunggu verifikasi',
        FinanceActivityNotification::TYPE_PAYMENT_REJECTED => 'Pembayaran ditolak',
        FinanceActivityNotification::TYPE_PAYMENT_FAILED => 'Pembayaran gagal',
        FinanceActivityNotification::TYPE_PAYMENT_CANCELLED => 'Pembayaran dibatalkan',
        FinanceActivityNotification::TYPE_PAYMENT_EXPIRED => 'Batas waktu pembayaran lewat',
        FinanceActivityNotification::TYPE_BILLING_OVERDUE => 'Tagihan lewat jatuh tempo',
        FinanceActivityNotification::TYPE_COLLECTION_REMINDER => 'Pengingat jatuh tempo',
        FinanceActivityNotification::TYPE_PAYMENT_PLAN_DUE => 'Angsuran akan jatuh tempo',
        FinanceActivityNotification::TYPE_PAYMENT_PLAN_OVERDUE => 'Angsuran lewat jatuh tempo',
        FinanceActivityNotification::TYPE_REFUND_APPROVED => 'Refund disetujui',
        FinanceActivityNotification::TYPE_REFUND_COMPLETED => 'Refund selesai',
        FinanceActivityNotification::TYPE_RE_REGISTRATION_BILLED => 'Tagihan daftar ulang',
        FinanceActivityNotification::TYPE_RE_REGISTRATION_COMPLETED => 'Daftar ulang selesai',
        FinanceActivityNotification::TYPE_RE_REGISTRATION_EXPIRED => 'Daftar ulang kedaluwarsa',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = (array) $this->resource->data;
        $type = (string) ($data['type'] ?? '');

        return [
            'id' => (string) $this->resource->id,
            'type' => $type,
            'type_label' => self::TYPE_LABELS[$type] ?? $type,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'reference' => $data['reference'] ?? null,
            'invoice_id' => isset($data['invoice_id']) ? (int) $data['invoice_id'] : null,
            'payment_id' => isset($data['payment_id']) ? (int) $data['payment_id'] : null,
            'payment_plan_id' => isset($data['payment_plan_id']) ? (int) $data['payment_plan_id'] : null,
            'refund_id' => isset($data['refund_id']) ? (int) $data['refund_id'] : null,
            're_registration_id' => isset($data['re_registration_id']) ? (int) $data['re_registration_id'] : null,
            'due_date' => $data['due_date'] ?? null,
            'reason' => $data['reason'] ?? null,
            'is_read' => $this->resource->read_at !== null,
            'read_at' => $this->resource->read_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
