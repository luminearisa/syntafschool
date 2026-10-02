<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kontak darurat (§26). Satu pegawai dapat punya beberapa, berurutan prioritas;
 * data ini yang dibuka petugas ketika terjadi sesuatu — bukan profil lengkap
 * pegawai.
 */
class HrEmergencyContact extends HrdModel
{
    use SoftDeletes;

    protected $casts = [
        'is_primary' => 'boolean',
        'priority' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function relationshipLabel(): ?string
    {
        return HrReferenceItem::query()
            ->where('group_code', 'relationship_type')
            ->where('code', $this->relationship_code)
            ->value('label');
    }
}
