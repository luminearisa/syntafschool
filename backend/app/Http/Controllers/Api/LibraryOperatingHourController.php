<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Library\LibraryOperatingHoursRequest;
use App\Http\Resources\LibraryOperatingHourResource;
use App\Models\Library;
use App\Models\LibraryOperatingHour;
use App\Services\Library\LibraryService;
use Illuminate\Http\JsonResponse;

/**
 * Jam operasional perpustakaan.
 *
 * Update bersifat bulk replace: satu permintaan mengirim seluruh jadwal
 * mingguan, sehingga hari yang dihapus ikut terhapus (tidak ada sisa).
 */
class LibraryOperatingHourController extends BaseApiController
{
    public function __construct(protected LibraryService $service)
    {
    }

    public function index(Library $library): JsonResponse
    {
        $this->authorize('viewOperatingHours', $library);

        $hours = LibraryOperatingHour::query()
            ->where('library_id', $library->id)
            ->orderBy('day_of_week')
            ->orderBy('open_time')
            ->get();

        return $this->successResponse(
            LibraryOperatingHourResource::collection($hours),
            'Jam operasional berhasil diambil'
        );
    }

    public function update(LibraryOperatingHoursRequest $request, Library $library): JsonResponse
    {
        $this->authorize('updateOperatingHours', $library);

        $this->service->syncOperatingHours($library, $request->validated()['hours']);

        $hours = LibraryOperatingHour::query()
            ->where('library_id', $library->id)
            ->orderBy('day_of_week')
            ->orderBy('open_time')
            ->get();

        return $this->successResponse(
            LibraryOperatingHourResource::collection($hours),
            'Jam operasional berhasil diperbarui'
        );
    }
}