<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbDocumentRequirementRequest;
use App\Http\Resources\PpdbDocumentRequirementResource;
use App\Models\PpdbDocumentRequirement;
use App\Services\Ppdb\PpdbPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Persyaratan dokumen pendaftaran (scoped: periode / program / gelombang).
 *
 * Batasan ekstensi & ukuran disimpan sebagai data supaya verifier tidak perlu
 * menebak berkas seperti apa yang sah.
 */
class PpdbDocumentRequirementController extends BaseApiController
{
    public function __construct(protected PpdbPeriodService $config)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = PpdbDocumentRequirement::query()->with(['program', 'wave'])->withCount('documents');

        if ($request->filled('period_id')) {
            $query->where('period_id', $request->integer('period_id'));
        }

        if ($request->filled('program_id')) {
            $query->where('program_id', $request->integer('program_id'));
        }

        if ($request->filled('wave_id')) {
            $query->where('wave_id', $request->integer('wave_id'));
        }

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
        }

        $requirements = $this->config->paginated(
            $request,
            $this->config->sorted($query, $request, ['code', 'name', 'order', 'max_size_kb', 'created_at'], 'order', 'asc')
                ->orderBy('id')
        );

        return $this->successResponse(
            PpdbDocumentRequirementResource::collection($requirements),
            'Daftar persyaratan dokumen berhasil diambil'
        );
    }

    public function store(PpdbDocumentRequirementRequest $request): JsonResponse
    {
        // Disegarkan agar nilai bawaan kolom (active, required, order, batas
        // berkas) ikut terisi pada respons.
        $requirement = PpdbDocumentRequirement::create($request->validated())->refresh();

        return $this->createdResponse(
            new PpdbDocumentRequirementResource($this->withRelations($requirement)),
            'Persyaratan dokumen berhasil dibuat'
        );
    }

    public function show(PpdbDocumentRequirement $document_requirement): JsonResponse
    {
        return $this->successResponse(
            new PpdbDocumentRequirementResource($this->withRelations($document_requirement)),
            'Detail persyaratan dokumen berhasil diambil'
        );
    }

    public function update(
        PpdbDocumentRequirementRequest $request,
        PpdbDocumentRequirement $document_requirement,
    ): JsonResponse {
        $document_requirement->fill($request->validated());
        $document_requirement->save();

        return $this->successResponse(
            new PpdbDocumentRequirementResource($this->withRelations($document_requirement)),
            'Persyaratan dokumen berhasil diperbarui'
        );
    }

    /**
     * Dokumen yang sudah diunggah calon merujuk ke persyaratan ini; menghapusnya
     * akan memutus jejak verifikasi berkas.
     */
    public function destroy(PpdbDocumentRequirement $document_requirement): JsonResponse
    {
        $this->config->assertNotInUse(
            $document_requirement->documents()->count(),
            'Persyaratan dokumen',
            'berkas calon',
            'document_requirement'
        );

        $document_requirement->delete();

        return $this->successResponse(null, 'Persyaratan dokumen berhasil dihapus');
    }

    private function withRelations(PpdbDocumentRequirement $requirement): PpdbDocumentRequirement
    {
        $requirement->loadMissing(['period', 'program', 'wave']);

        return $requirement->loadCount('documents');
    }
}
