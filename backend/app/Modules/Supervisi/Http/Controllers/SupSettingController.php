<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Modules\Supervisi\Application\Services\SupervisiSettings;
use App\Modules\Supervisi\Models\SupSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pengaturan modul: ambang, frekuensi minimum, kebijakan publikasi. */
class SupSettingController extends SupController
{
    public function __construct(private readonly SupervisiSettings $settings) {}

    public function index(): JsonResponse
    {
        return $this->successResponse([
            'values' => $this->settings->all(),
            'defaults' => SupSetting::DEFAULTS,
            'fields' => [
                ['key' => 'grade_scale_code', 'label' => 'Skala predikat bawaan', 'type' => 'string'],
                ['key' => 'peer_can_finalize', 'label' => 'Rekan sejawat boleh memfinalkan nilai', 'type' => 'bool'],
                ['key' => 'allow_multi_source', 'label' => 'Izinkan penilaian multi-sumber (diri/sejawat/siswa)', 'type' => 'bool'],
                ['key' => 'publish_result_to_supervisee', 'label' => 'Tampilkan hasil akhir kepada yang disupervisi', 'type' => 'bool'],
                ['key' => 'require_comment_below_percent', 'label' => 'Wajib komentar bila skor indikator di bawah (%)', 'type' => 'float'],
                ['key' => 'min_visits_guru_full_observation', 'label' => 'Minimum observasi kelas penuh per guru per semester', 'type' => 'int'],
                ['key' => 'min_visits_guru_walkthrough', 'label' => 'Minimum walkthrough per guru per semester', 'type' => 'int'],
                ['key' => 'min_visits_tendik', 'label' => 'Minimum kunjungan per tenaga kependidikan per semester', 'type' => 'int'],
                ['key' => 'min_visits_kepala_sekolah', 'label' => 'Minimum supervisi kepala sekolah per tahun', 'type' => 'int'],
                ['key' => 'supervision_weight_percent', 'label' => 'Bobot hasil supervisi dalam penilaian kinerja (%)', 'type' => 'float'],
                ['key' => 'attendance_correction_active', 'label' => 'Aktifkan koreksi kehadiran pada PKG', 'type' => 'bool'],
                ['key' => 'require_post_conference', 'label' => 'Wajib wawancara pasca-observasi sebelum finalisasi', 'type' => 'bool'],
                ['key' => 'finding_required_below_percent', 'label' => 'Wajib temuan/rekomendasi bila nilai akhir di bawah (%)', 'type' => 'float'],
                ['key' => 'require_action_plan_before_finalize', 'label' => 'Wajib RTL sebelum finalisasi (aktif mulai Fase 3)', 'type' => 'bool'],
                ['key' => 'dispute_window_days', 'label' => 'Batas hari pengajuan keberatan setelah hasil final', 'type' => 'int'],
                ['key' => 'attendance_working_days', 'label' => 'Hari kerja untuk koreksi kehadiran PKG', 'type' => 'int'],
                ['key' => 'pkg_credit_scale_code', 'label' => 'Skala sebutan & angka kredit PKG/NKKS', 'type' => 'string'],
                ['key' => 'appraisal_source_weights', 'label' => 'Bobot sumber penilaian multi-sumber (%)', 'type' => 'json'],
                ['key' => 'appraisal_normalize_weights', 'label' => 'Normalisasi otomatis bobot sumber', 'type' => 'bool'],
                ['key' => 'skp_rating_thresholds', 'label' => 'Ambang rating SKP (di atas / di bawah)', 'type' => 'json'],
                ['key' => 'skp_quadrant_matrix', 'label' => 'Matriks kuadran SKP (kosong = bawaan)', 'type' => 'json'],
                ['key' => 'skp_predicate_credit', 'label' => 'Persentase angka kredit per predikat SKP', 'type' => 'json'],
                ['key' => 'action_plan_default_due_days', 'label' => 'Tenggat bawaan butir RTL (hari)', 'type' => 'int'],
                ['key' => 'action_plan_auto_from_findings', 'label' => 'Susun RTL otomatis dari temuan', 'type' => 'bool'],
                ['key' => 'coaching_follow_up_days', 'label' => 'Tenggat tindak lanjut coaching (hari)', 'type' => 'int'],
            ],
            'supervision_weight_note' => 'Bobot 0% berarti hasil supervisi hanya menjadi bukti pendukung, bukan komponen resmi penilaian kinerja.',
        ], 'Pengaturan modul supervisi');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'values' => ['required', 'array'],
            'values.*' => ['nullable'],
        ]);

        $allowed = array_keys(SupSetting::DEFAULTS);
        $unknown = array_diff(array_keys($data['values']), $allowed);

        if ($unknown !== []) {
            $this->invalid('values', 'Kunci pengaturan tidak dikenal: '.implode(', ', $unknown));
        }

        if (array_key_exists('supervision_weight_percent', $data['values'])
            && (float) $data['values']['supervision_weight_percent'] > 100) {
            $this->invalid('values.supervision_weight_percent', 'Bobot supervisi tidak boleh melebihi 100%.');
        }

        $normalize = $data['values']['appraisal_normalize_weights'] ?? true;
        $weights = $data['values']['appraisal_source_weights'] ?? null;

        if (is_array($weights) && ! (bool) $normalize) {
            $total = array_sum(array_map('floatval', $weights));

            if (abs($total - 100.0) > 0.01) {
                $this->invalid(
                    'values.appraisal_source_weights',
                    'Bobot sumber penilaian harus berjumlah 100% karena normalisasi otomatis dimatikan (saat ini '.$total.'%).'
                );
            }
        }

        return $this->successResponse(
            ['values' => $this->settings->putMany($data['values'])],
            'Pengaturan modul supervisi disimpan',
        );
    }
}
