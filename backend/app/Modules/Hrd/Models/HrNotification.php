<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notifikasi HRD (§55).
 *
 * Baris internal ini dicatat **lebih dulu**, sebelum provider kanal dipanggil.
 * Bila kanal WhatsApp belum dipasang atau surel gagal, pengingatnya tetap ada di
 * dalam aplikasi — tidak ada peringatan yang hilang tanpa jejak.
 */
class HrNotification extends HrdModel
{
    protected $casts = [
        'meta' => 'array',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public const CHANNELS = ['database', 'mail', 'whatsapp', 'push'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeForEvent(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }
}
