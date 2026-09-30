<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Jejak audit akses & perubahan data klinis (VIEW/CREATE/UPDATE/DELETE/EXPORT/PRINT/SHARE/DISPENSE/ADMINISTER/AMEND).
 *
 * Tabel: `clinic_audit_logs`
 */
class ClinicAuditLog extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_audit_logs';

    protected $casts = [
        'changes' => 'array',
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
    public function actionEnum(): AuditAction
    {
        return AuditAction::from($this->action);
    }
}
