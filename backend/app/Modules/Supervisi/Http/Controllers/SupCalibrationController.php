<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Modules\Supervisi\Application\Services\CalibrationService;
use App\Modules\Supervisi\Models\SupCalibrationSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Kalibrasi penilai: sesi moderasi, skor antar supervisor, dan kesimpulan. */
class SupCalibrationController extends SupController
{
    public function __construct(private readonly CalibrationService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        // `per_page` dibatasi: nilai bebas dari klien dapat dipakai untuk
        // meminta ribuan baris sekaligus (memori habis).
        $page = $this->service->paginate($request->only(['status', 'program_id']), $this->perPage($request, 25));

        return $this->successResponse([
            'rows' => collect($page->items())->map(fn (SupCalibrationSession $session) => [
                'id' => $session->id,
                'title' => $session->title,
                'status' => $session->status,
                'held_at' => $session->held_at?->toIso8601String(),
                'threshold' => $session->threshold,
                'program' => $session->program?->title,
                'conclusion' => $session->conclusion,
                'finalized_at' => $session->finalized_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
            'pending' => $this->service->pendingCount(),
        ], 'Daftar sesi kalibrasi penilai');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'instrument_version_id' => ['nullable', 'integer', 'exists:sup_instrument_versions,id'],
            'program_id' => ['nullable', 'integer', 'exists:sup_programs,id'],
            'threshold' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'held_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        $session = $this->service->create($data, $this->actor($request));

        return $this->successResponse($this->service->summary($session), 'Sesi kalibrasi dibuat', 201);
    }

    public function show(SupCalibrationSession $session): JsonResponse
    {
        return $this->successResponse($this->service->summary($session), 'Rincian sesi kalibrasi');
    }

    public function update(Request $request, SupCalibrationSession $session): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'threshold' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'held_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'instrument_version_id' => ['nullable', 'integer', 'exists:sup_instrument_versions,id'],
            'program_id' => ['nullable', 'integer', 'exists:sup_programs,id'],
        ]);

        $session = $this->service->update($session, $data, $this->actor($request));

        return $this->successResponse($this->service->summary($session), 'Sesi kalibrasi diperbarui');
    }

    public function saveScores(Request $request, SupCalibrationSession $session): JsonResponse
    {
        $data = $request->validate([
            'scores' => ['required', 'array', 'min:1'],
            'scores.*.supervisor_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'scores.*.indicator_id' => ['nullable', 'integer', 'exists:sup_indicators,id'],
            'scores.*.indicator_label' => ['nullable', 'string', 'max:200'],
            'scores.*.case_code' => ['nullable', 'string', 'max:60'],
            'scores.*.score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'scores.*.comment' => ['nullable', 'string', 'max:500'],
            'scores.*.round' => ['nullable', 'in:INDIVIDUAL,GROUP,AGREED'],
        ]);

        $session = $this->service->saveScores($session, $data['scores'], $this->actor($request));

        return $this->successResponse($this->service->summary($session), 'Skor kalibrasi tersimpan');
    }

    public function finalize(Request $request, SupCalibrationSession $session): JsonResponse
    {
        $data = $request->validate([
            'conclusion' => ['required', 'string', 'min:10'],
        ]);

        $session = $this->service->finalize($session, $data['conclusion'], $this->actor($request));

        return $this->successResponse($this->service->summary($session), 'Sesi kalibrasi difinalkan');
    }

    /** Bahan moderasi: kesenjangan nyata antar supervisor pada kegiatan yang sudah final. */
    public function divergence(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instrument_version_id' => ['nullable', 'integer', 'exists:sup_instrument_versions,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->successResponse([
            'rows' => $this->service->divergenceFromVisits(
                $data['instrument_version_id'] ?? null,
                (int) ($data['limit'] ?? 15),
            ),
        ], 'Kesenjangan penilaian antar supervisor');
    }
}
