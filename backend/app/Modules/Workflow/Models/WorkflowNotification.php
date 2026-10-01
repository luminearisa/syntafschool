<?php

namespace App\Modules\Workflow\Models;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log pengiriman notifikasi (§67, §68).
 *
 * `idempotency_key` unik adalah mekanisme tunggal yang mencegah notifikasi
 * ganda ketika webhook diulang, job dicoba ulang, atau pengguna menekan tombol
 * dua kali. Baris dengan status SKIPPED justru bukti bahwa pencegahan bekerja.
 *
 * @property NotificationStatus $status
 */
class WorkflowNotification extends WorkflowModel
{
    protected $table = 'workflow_notifications';

    protected $casts = [
        'status' => NotificationStatus::class,
        'payload' => 'array',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
