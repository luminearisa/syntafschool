<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventCommitteeMember — tabel `event_committee_members`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventCommitteeMember extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_committee_members';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'committee_id' => 'integer',
            'event_id' => 'integer',
            'employee_id' => 'integer',
            'student_id' => 'integer',
            'user_id' => 'integer',
            'is_leader' => 'boolean',
            'replaced_by' => 'integer',
            'joined_at' => 'date',
            'left_at' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    public function committee(): BelongsTo
    {
        return $this->belongsTo(EventCommittee::class, 'committee_id');
    }
}
