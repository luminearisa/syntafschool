<?php

namespace App\Modules\Hrd\Core\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_service_ticket_comments` (domain Core).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrServiceTicketComment extends HrdModel
{
    protected $table = 'hr_service_ticket_comments';

    protected $guarded = [];
    protected $casts = [
        'is_internal' => 'boolean',
    ];

}
