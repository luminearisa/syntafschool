<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;

/**
 * Jejak audit modul supervisi.
 *
 * Hanya kolom `created_at` yang ada — catatan tidak boleh diubah/dihapus
 * (syarat audit akademik & kepegawaian).
 */
class SupAuditLog extends BaseModel
{
    protected $table = 'sup_audit_logs';

    public $timestamps = false;

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'context' => 'array',
        'created_at' => 'datetime',
    ];
}
