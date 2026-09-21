<?php

namespace App\Http\Resources;

use App\Notifications\PpdbActivityNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notifikasi PPDB pada kanal `database` bawaan Laravel.
 *
 * Baris `notifications.data` berisi `{type, title, body}` + konteks yang
 * disertakan pengirim (mis. `registration_id`, `document_id`), jadi resource
 * ini meratakannya menjadi satu bentuk respons.
 *
 * @property DatabaseNotification $resource
 */
class PpdbNotificationResource extends JsonResource
{
    public const TYPE_LABELS = [
        PpdbActivityNotification::TYPE_ACCOUNT_CREATED => 'Akun dibuat',
        PpdbActivityNotification::TYPE_REGISTRATION_SUBMITTED => 'Pendaftaran terkirim',
        PpdbActivityNotification::TYPE_DOCUMENT_REJECTED => 'Dokumen ditolak',
        PpdbActivityNotification::TYPE_REVISION_REQUESTED => 'Revisi diminta',
        PpdbActivityNotification::TYPE_REGISTRATION_VERIFIED => 'Pendaftaran terverifikasi',
        PpdbActivityNotification::TYPE_REGISTRATION_CANCELLED => 'Pendaftaran dibatalkan',
        PpdbActivityNotification::TYPE_PAYMENT_PAID => 'Pembayaran diterima',
        PpdbActivityNotification::TYPE_PAYMENT_FAILED => 'Pembayaran gagal',
        PpdbActivityNotification::TYPE_PAYMENT_REMINDER => 'Pengingat pembayaran',
        PpdbActivityNotification::TYPE_SELECTION_SCHEDULED => 'Jadwal seleksi',
        PpdbActivityNotification::TYPE_SELECTION_RESULT => 'Hasil seleksi',
        PpdbActivityNotification::TYPE_RE_REGISTRATION_OPEN => 'Daftar ulang dibuka',
        PpdbActivityNotification::TYPE_ANNOUNCEMENT => 'Pengumuman',
        'account_blocked' => 'Akun diblokir',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::row($this->resource->id, (array) $this->resource->data, $this->resource->read_at, $this->resource->created_at);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function row(string $id, array $data, mixed $readAt, mixed $createdAt): array
    {
        $type = (string) ($data['type'] ?? 'announcement');
        $registrationId = isset($data['registration_id']) ? (int) $data['registration_id'] : null;

        return [
            'id' => $id,
            'type' => $type,
            'type_label' => self::TYPE_LABELS[$type] ?? $type,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'data' => $data,
            'is_read' => $readAt !== null,
            'read_at' => $readAt?->toIso8601String(),
            'created_at' => $createdAt?->toIso8601String(),
            'context' => [
                'registration_id' => $registrationId,
                'registration_number' => $data['registration_number'] ?? null,
                'parent_account_id' => isset($data['parent_account_id']) ? (int) $data['parent_account_id'] : null,
                'applicant_id' => isset($data['applicant_id']) ? (int) $data['applicant_id'] : null,
                'document_id' => isset($data['document_id']) ? (int) $data['document_id'] : null,
                'requirement_id' => isset($data['requirement_id']) ? (int) $data['requirement_id'] : null,
                'requirement_name' => $data['requirement_name'] ?? null,
                'invoice_id' => isset($data['invoice_id']) ? (int) $data['invoice_id'] : null,
                'reason' => $data['reason'] ?? $data['note'] ?? null,
            ],
            'links' => $registrationId === null ? [] : [
                'api_detail' => '/api/v1/ppdb/portal/registrations/'.$registrationId,
            ],
        ];
    }
}
