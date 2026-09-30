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

        // Fase 3 — tindak lanjut, penilaian kinerja, dan pengembangan.
        'attendance_working_days' => 46,                 // pembagi koreksi kehadiran (Permendikbud 35/2010)
        'pkg_credit_scale_code' => 'pkg_sebutan',        // skala sebutan & angka kredit PKG/NKKS
        'appraisal_source_weights' => [                  // bobot bawaan penilaian multi-sumber (360°)
            'SUPERVISOR' => 60, 'PEER' => 20, 'SELF' => 10, 'STUDENT' => 10,
        ],
        'appraisal_normalize_weights' => true,           // false = bobot sumber wajib tepat 100%
        'skp_rating_thresholds' => ['above' => 100, 'below' => 90],
        'skp_quadrant_matrix' => [],                     // kosong = matriks bawaan PermenPANRB 6/2022
        'skp_predicate_credit' => [],                    // kosong = persentase angka kredit bawaan
        'action_plan_default_due_days' => 30,            // tenggat bawaan butir RTL
        'action_plan_auto_from_findings' => true,        // RTL dibuat otomatis dari temuan
        'coaching_follow_up_days' => 14,                 // tenggat tindak lanjut sesi coaching

        // Fase 5 — privasi (blueprint §11.6). Bawaan: guru/staf hanya melihat
        // datanya sendiri. Bila sekolah mengaktifkan, rekan sejawat boleh
        // membaca ringkasan **agregat** (tanpa nama siapa pun).
        'peer_summary_enabled' => false,
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
