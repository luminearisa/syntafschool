<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbWaveRequest;
use App\Http\Resources\PpdbWaveResource;
use App\Models\PpdbWave;
use App\Services\Ppdb\PpdbPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gelombang PPDB.
 *
 * Satu periode boleh punya beberapa gelombang dengan kuota, prioritas, dan
 * tarif berbeda. Kode gelombang unik di dalam periodenya.
 */
class PpdbWaveController extends BaseApiController
{
    public function __construct(protected PpdbPeriodService $config)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = PpdbWave::query()->withCount('registrations');

        if ($request->filled('period_id')) {
            $query->where('period_id', $request->integer('period_id'));
        }

        if ($request->filled('status') && in_array($request->query('status'), PpdbWave::STATUSES, true)) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
        }

        $waves = $this->config->paginated(
            $request,
            $this->config->sorted(
                $query,
                $request,
                ['code', 'name', 'priority', 'quota', 'registration_start', 'created_at'],
                'priority',
                'asc'
            )->orderBy('id')
        );

        return $this->successResponse(
            PpdbWaveResource::collection($waves),
            'Daftar gelombang PPDB berhasil diambil'
        );
    }

    public function store(PpdbWaveRequest $request): JsonResponse
    {
        // Disegarkan agar nilai bawaan kolom (status, priority) ikut terisi.
        $wave = PpdbWave::create($request->validated())->refresh();

        return $this->createdResponse(
            new PpdbWaveResource($wave->load('period')->loadCount('registrations')),
            'Gelombang PPDB berhasil dibuat'
        );
    }

    public function show(PpdbWave $wave): JsonResponse
    {
        return $this->successResponse(
            new PpdbWaveResource($wave->load('period')->loadCount('registrations')),
            'Detail gelombang PPDB berhasil diambil'
        );
    }

    public function update(PpdbWaveRequest $request, PpdbWave $wave): JsonResponse
    {
        $wave->fill($request->validated());
        $wave->save();

        return $this->successResponse(
            new PpdbWaveResource($wave->load('period')->loadCount('registrations')),
            'Gelombang PPDB berhasil diperbarui'
        );
    }

    public function destroy(PpdbWave $wave): JsonResponse
    {
        $this->config->assertNotInUse($wave->registrations()->count(), 'Gelombang', 'pendaftaran', 'wave');

        $wave->delete();

        return $this->successResponse(null, 'Gelombang PPDB berhasil dihapus');
    }
}
