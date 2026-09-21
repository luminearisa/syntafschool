<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbPublicPeriodFilterRequest;
use App\Http\Resources\PpdbPublicPeriodResource;
use App\Models\PpdbPeriod;
use App\Services\Ppdb\PpdbRegistrationQueryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Halaman publik PPDB — bisa diakses TANPA login.
 *
 * Yang dikembalikan hanya informasi yang memang untuk calon pendaftar: profil
 * periode (judul, deskripsi, banner, logo, kontak, FAQ), jadwal & gelombang
 * beserta sisa kuota, program beserta biaya, syarat dokumen (ekstensi & ukuran),
 * tahap seleksi, dan apakah pendaftaran sedang dibuka. TIDAK ada data pribadi
 * (nama pendaftar, NIK, dokumen, tagihan) di endpoint ini.
 */
class PpdbPublicController extends BaseApiController
{
    public function __construct(protected PpdbRegistrationQueryService $queries)
    {
    }

    /** Daftar periode yang tampil untuk publik. */
    public function index(PpdbPublicPeriodFilterRequest $request): JsonResponse
    {
        $perPage = (int) ($request->validated('per_page') ?? 10);

        $periods = $this->baseQuery($request)
            ->paginate($perPage)
            ->appends($request->query());

        // Satu query kuota untuk semua periode di halaman ini (tanpa N+1).
        $quotas = $this->queries->quotaSummaries($periods->getCollection()->all());

        $rows = $periods->getCollection()
            ->map(fn (PpdbPeriod $period): array => PpdbPublicPeriodResource::summary(
                $period,
                $quotas[(int) $period->id] ?? [],
            ))
            ->all();

        return $this->successResponse(
            $periods->setCollection(collect($rows)),
            'Daftar periode PPDB berhasil diambil',
        );
    }

    /** Isi satu halaman publik: profil, jadwal, kuota, program, dokumen, biaya, tahap seleksi. */
    public function show(string $slug): JsonResponse
    {
        $period = PpdbPeriod::query()
            ->with('academicYear')
            ->publiclyVisible()
            ->where('slug', $slug)
            ->firstOrFail();

        $payload = array_merge(
            PpdbPublicPeriodResource::summary($period, $this->queries->quotaSummary($period)),
            $this->queries->publicPeriodPayload($period),
        );

        return $this->successResponse($payload, 'Informasi PPDB berhasil diambil');
    }

    /**
     * Periode yang boleh dilihat publik + filter & urutan yang diminta halaman.
     *
     * @return Builder<PpdbPeriod>
     */
    private function baseQuery(PpdbPublicPeriodFilterRequest $request): Builder
    {
        $search = (string) $request->validated('search');
        $sort = (string) ($request->validated('sort') ?? 'registration_start');
        $direction = $request->validated('direction') === 'asc' ? 'asc' : 'desc';

        return PpdbPeriod::query()
            ->with('academicYear')
            ->publiclyVisible()
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', (string) $request->validated('status')))
            ->when($request->filled('academic_year_id'), fn (Builder $query) => $query->where('academic_year_id', (int) $request->validated('academic_year_id')))
            ->when($request->boolean('only_open'), fn (Builder $query) => $query->open())
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $scope) use ($search): void {
                $like = '%'.$search.'%';
                $scope->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('public_title', 'like', $like);
            }))
            ->orderBy($sort, $direction)
            ->orderByDesc('id');
    }
}
