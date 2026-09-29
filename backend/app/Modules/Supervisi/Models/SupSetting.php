<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;

/** Pengaturan modul supervisi (ambang, frekuensi minimum, kebijakan publikasi). */
class SupSetting extends BaseModel
{
    protected $table = 'sup_settings';

    /** Nilai bawaan bila sekolah belum pernah mengubah pengaturan. */
    public const DEFAULTS = [
        'grade_scale_code' => 'kemendikbud_90_80_70',
        'peer_can_finalize' => false,
        'allow_multi_source' => false,
        'publish_result_to_supervisee' => true,
        'require_comment_below_percent' => 70,
        'min_visits_guru_full_observation' => 1,
        'min_visits_guru_walkthrough' => 2,
        'min_visits_tendik' => 1,
        'min_visits_kepala_sekolah' => 1,
        'supervision_weight_percent' => 0,
        'attendance_correction_active' => true,
        'report_signature_requires_head' => true,
        // Fase 2 — aturan pelaksanaan & finalisasi kegiatan.
        'require_post_conference' => true,
        'finding_required_below_percent' => 80,
        'require_action_plan_before_finalize' => false, // diaktifkan pada Fase 3 (RTL)
        'dispute_window_days' => 7,
    ];

    public function typedValue(): mixed
    {
        return match ($this->cast) {
            'int' => (int) $this->value,
            'float' => (float) $this->value,
            'bool' => (bool) (int) $this->value,
            'json' => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }
}
