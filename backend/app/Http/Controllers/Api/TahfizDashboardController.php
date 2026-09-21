<?php

namespace App\Http\Controllers\Api;

use App\Services\Tahfiz\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lima dashboard modul Tahfiz.
 *
 * Dashboard mana yang dipakai ditentukan oleh peran pengguna yang login:
 * - admin    → `tahfiz/dashboards/admin`
 * - guru     → `tahfiz/dashboards/guru`    (halaqah yang diajar)
 * - musyrif  → `tahfiz/dashboards/musyrif` (halaqah yang dipantau)
 * - peserta  → `tahfiz/dashboards/peserta` (diri sendiri)
 * - wali     → `tahfiz/dashboards/wali`    (diri sendiri ATAU anak, pilih satu)
 *
 * Cakupan data tidak bisa ditentukan dari parameter klien — kecuali pemilihan
 * "Saya sebagai Peserta / Anak Saya" pada dashboard wali, yang tetap
 * memverifikasi bahwa anak tersebut benar-benar anak wali pengguna.
 */
class TahfizDashboardController extends BaseApiController
{
    public function __construct(protected DashboardService $dashboards)
    {
    }

    public function admin(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->dashboards->admin(),
            'Dashboard admin berhasil diambil'
        );
    }

    public function instructor(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->dashboards->instructor($request->user()),
            'Dashboard guru berhasil diambil'
        );
    }

    public function musyrif(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->dashboards->musyrif($request->user()),
            'Dashboard musyrif berhasil diambil'
        );
    }

    public function participant(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->dashboards->participant($request->user()),
            'Dashboard peserta berhasil diambil'
        );
    }

    /**
     * Dashboard wali — `view=self` (Saya sebagai Peserta) atau
     * `view=child&participant_id=...` (Anak Saya).
     *
     * Kedua tampilan tidak pernah dicampur dalam satu tanggapan.
     */
    public function parent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'view' => ['nullable', 'string', 'in:self,child'],
            'participant_id' => ['nullable', 'integer'],
        ]);

        $view = $validated['view'] ?? 'self';

        return $this->successResponse(
            $this->dashboards->parent(
                $request->user(),
                $view,
                isset($validated['participant_id']) ? (int) $validated['participant_id'] : null,
            ),
            'Dashboard wali berhasil diambil'
        );
    }
}
