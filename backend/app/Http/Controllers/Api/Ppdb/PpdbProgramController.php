<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbProgramRequest;
use App\Http\Resources\PpdbProgramResource;
use App\Models\PpdbProgram;
use App\Services\Ppdb\PpdbPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penawaran program pada sebuah periode PPDB.
 *
 * Master `programs`/`education_levels` yang ada ditautkan, tidak disalin.
 */
class PpdbProgramController extends BaseApiController
{
    public function __construct(protected PpdbPeriodService $config)
    {
    }

    public function index(Request $request): JsonResponse
    {
        // `program` & `educationLevel` ikut: daftar menampilkan nama master yang
        // ditunjuk penawaran, dan keduanya adalah tautan ke modul lain — tanpa
        // beban tambahan selain dua join yang sudah ada di detail.
        $query = PpdbProgram::query()
            ->with(['program:id,name,code', 'educationLevel:id,name,jenjang'])
            ->withCount('registrations');

        if ($request->filled('period_id')) {
            $query->where('period_id', $request->integer('period_id'));
        }

        if ($request->filled('status') && in_array($request->query('status'), PpdbProgram::STATUSES, true)) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
        }

        $programs = $this->config->paginated(
            $request,
            $this->config->sorted(
                $query,
                $request,
                ['code', 'name', 'quota', 'sort_order', 'created_at'],
                'sort_order',
                'asc'
            )->orderBy('id')
        );

        return $this->successResponse(
            PpdbProgramResource::collection($programs),
            'Daftar program PPDB berhasil diambil'
        );
    }

    public function store(PpdbProgramRequest $request): JsonResponse
    {
        // Disegarkan agar nilai bawaan kolom (status, sort_order) ikut terisi.
        $program = PpdbProgram::create($request->validated())->refresh();

        return $this->createdResponse(
            new PpdbProgramResource($this->withRelations($program)),
            'Program PPDB berhasil dibuat'
        );
    }

    public function show(PpdbProgram $program): JsonResponse
    {
        return $this->successResponse(
            new PpdbProgramResource($this->withRelations($program)),
            'Detail program PPDB berhasil diambil'
        );
    }

    public function update(PpdbProgramRequest $request, PpdbProgram $program): JsonResponse
    {
        $program->fill($request->validated());
        $program->save();

        return $this->successResponse(
            new PpdbProgramResource($this->withRelations($program)),
            'Program PPDB berhasil diperbarui'
        );
    }

    /**
     * Hapus keras: tabel pendaftaran menautkan program dengan `cascadeOnDelete`,
     * jadi program yang sudah dipakai tidak boleh dihapus.
     */
    public function destroy(PpdbProgram $program): JsonResponse
    {
        $this->config->assertNotInUse($program->registrations()->count(), 'Program', 'pendaftaran', 'program');

        $program->delete();

        return $this->successResponse(null, 'Program PPDB berhasil dihapus');
    }

    private function withRelations(PpdbProgram $program): PpdbProgram
    {
        $program->loadMissing(['period', 'program', 'educationLevel']);

        return $program->loadCount('registrations');
    }
}
