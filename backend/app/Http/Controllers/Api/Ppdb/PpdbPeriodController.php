<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbPeriodRequest;
use App\Http\Resources\PpdbPeriodResource;
use App\Models\PpdbPeriod;
use App\Services\Ppdb\PpdbPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Konfigurasi periode PPDB.
 *
 * Periode adalah induk seluruh konfigurasi lain (gelombang, program, field
 * form, pertanyaan, dokumen, biaya) sekaligus halaman publik. Kode & slug
 * dibangkitkan otomatis dan perpindahan status dijaga lewat
 * `PpdbPeriod::TRANSITIONS` di `PpdbPeriodService`.
 */
class PpdbPeriodController extends BaseApiController
{
    public function __construct(protected PpdbPeriodService $periods)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $periods = $this->periods->paginated($request, $this->periods->query($request));

        return $this->successResponse(
            PpdbPeriodResource::collection($periods),
            'Daftar periode PPDB berhasil diambil'
        );
    }

    public function store(PpdbPeriodRequest $request): JsonResponse
    {
        $period = $this->periods->create($request->validated() + ['created_by' => $request->user()?->id]);

        return $this->createdResponse(
            new PpdbPeriodResource($this->withDetails($period)),
            'Periode PPDB berhasil dibuat'
        );
    }

    public function show(PpdbPeriod $period): JsonResponse
    {
        return $this->successResponse(
            new PpdbPeriodResource($this->withDetails($period)),
            'Detail periode PPDB berhasil diambil'
        );
    }

    public function update(PpdbPeriodRequest $request, PpdbPeriod $period): JsonResponse
    {
        $period = $this->periods->update($period, $request->validated());

        return $this->successResponse(
            new PpdbPeriodResource($this->withDetails($period)),
            'Periode PPDB berhasil diperbarui'
        );
    }

    /**
     * Buka pendaftaran. Hanya sah lewat `PpdbPeriod::TRANSITIONS` dan hanya bila
     * periode sudah punya program aktif.
     */
    public function publish(PpdbPeriod $period): JsonResponse
    {
        $period = $this->periods->publish($period);

        return $this->successResponse(
            new PpdbPeriodResource($this->withDetails($period)),
            'Periode berhasil diterbitkan dan pendaftaran dibuka'
        );
    }

    public function destroy(PpdbPeriod $period): JsonResponse
    {
        $this->periods->delete($period);

        return $this->successResponse(null, 'Periode PPDB berhasil dihapus');
    }

    /** Muat detail & jumlah pemakaian agar respons index/show konsisten. */
    private function withDetails(PpdbPeriod $period): PpdbPeriod
    {
        $period->loadMissing(['academicYear', 'waves', 'programs']);

        return $period->loadCount(['waves', 'programs', 'registrations']);
    }
}
