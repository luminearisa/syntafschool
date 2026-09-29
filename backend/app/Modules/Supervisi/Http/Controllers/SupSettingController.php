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

        return $this->successResponse(
            ['values' => $this->settings->putMany($data['values'])],
            'Pengaturan modul supervisi disimpan',
        );
    }
}
