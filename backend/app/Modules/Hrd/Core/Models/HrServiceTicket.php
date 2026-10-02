<?php

namespace App\Modules\Hrd\Core\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Core\Models\HrServiceTicketComment;
use App\Modules\Hrd\Models\HrEmployee;

/**
 * Phase 2 — tabel `hr_service_tickets` (domain Core).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrServiceTicket extends HrdModel
{
    protected $table = 'hr_service_tickets';

    protected $guarded = [];
    protected $casts = [
        'sla_due_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
    public function comments(): HasMany
    {
        return $this->hasMany(HrServiceTicketComment::class, 'ticket_id');
    }
}
