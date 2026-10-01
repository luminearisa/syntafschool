<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Activity\Application\Services\ActivityIntegrationService;
use App\Modules\Activity\Models\ActivityCharge;
use App\Modules\Activity\Models\ActivityEquipmentRequest;
use App\Modules\Activity\Models\ActivityFacilityBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sisi aktivitas dari integrasi: apa yang telah diminta modul ini kepada
 * Keuangan, Inventaris, dan Sarpras \— beserta tindak lanjutnya.
 *
 * Modul tetangga tetap menjadi pemilik datanya; di sini hanya supaya sekolah
 * dapat melihat dan merapikan permintaan aktivitas tanpa harus membuka modul
 * lain (dan tanpa menunggu antrean tiket).
 */
class ActivityIntegrationController extends BaseApiController
{
    public function __construct(private readonly ActivityIntegrationService $integrations) {}

    public function health(Request $request): JsonResponse
    {
        return $this->successResponse($this->integrations->health());
    }

    // ------------------------------------------------------------- keuangan

    public function charges(Request $request): JsonResponse
    {
        return $this->successResponse($this->integrations->charges($request->all()));
    }

    public function cancelCharge(ActivityCharge $charge, Request $request): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->successResponse(
            $this->integrations->cancelCharge($charge, $request->user(), $request->input('reason')),
            'Permintaan tagihan dibatalkan.',
        );
    }

    // ----------------------------------------------------------- inventaris

    public function equipmentRequests(Request $request): JsonResponse
    {
        return $this->successResponse($this->integrations->equipmentRequests($request->all()));
    }

    public function updateEquipmentRequest(ActivityEquipmentRequest $request, Request $http): JsonResponse
    {
        $http->validate([
            'status' => ['required', Rule::in(['ISSUED', 'PARTIAL', 'RETURNED', 'REJECTED', 'LOST'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->successResponse(
            $this->integrations->updateEquipmentRequest($request, (string) $http->input('status'), $http->user(), $http->input('notes')),
            'Permintaan perlengkapan diperbarui.',
        );
    }

    // -------------------------------------------------------------- sarpras

    public function facilityBookings(Request $request): JsonResponse
    {
        return $this->successResponse($this->integrations->facilityBookings($request->all()));
    }

    public function cancelFacilityBooking(ActivityFacilityBooking $booking, Request $request): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->successResponse(
            $this->integrations->cancelFacilityBooking($booking, $request->user(), $request->input('reason')),
            'Pemesanan fasilitas dibatalkan.',
        );
    }
}
