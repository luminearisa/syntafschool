<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tugas pada template offboarding (§40).
 *
 * `clearance_area` + `requires_clearance` membedakan tugas biasa dari pembebasan
 * tanggung jawab: yang terakhir hanya sah bila dibebaskan pejabat areanya
 * (Sarana, Keuangan, Perpustakaan, TI), bukan sekadar dicentang pegawai.
 */
class HrOffboardingTemplateTask extends HrdModel
{
    protected $casts = [
        'requires_clearance' => 'boolean',
        'due_offset_days' => 'integer',
        'is_mandatory' => 'boolean',
        'blocks_exit' => 'boolean',
        'requires_attachment' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(HrOffboardingTemplate::class, 'template_id');
    }

    public function clearanceAreas(): array
    {
        return ['asset', 'finance', 'document', 'it', 'library', 'academic', 'other'];
    }
}
