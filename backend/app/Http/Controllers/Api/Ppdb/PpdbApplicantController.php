<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbApplicantRequest;
use App\Http\Resources\PpdbApplicantResource;
use App\Models\PpdbApplicant;
use App\Models\PpdbParentAccount;
use App\Models\PpdbRegistration;
use App\Models\User;
use App\Services\Ppdb\PpdbParentAccountService;
use App\Services\Ppdb\PpdbPortalAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Calon peserta didik (anak) — portal orang tua & daftar panitia.
 *
 * Aturan yang dijaga di sini:
 * - SATU akun orang tua bisa punya BANYAK anak: calon selalu dibuat di bawah
 *   `parent_account_id` milik pemanggil, TIDAK pernah membuat `users` baru.
 * - Calon BUKAN siswa: tidak ada satu pun jalur di controller ini yang menulis
 *   tabel `students` (konversi jadi siswa milik `PpdbConversionService`, dan
 *   rute portal tidak menyentuhnya).
 * - Setiap pembacaan/pennulisan data lewat `PpdbPortalAccess` — route portal
 *   memakai implicit binding, jadi ID tebakan harus ditolak 403, bukan dilayani.
 */
class PpdbApplicantController extends BaseApiController
{
    public function __construct(
        protected PpdbPortalAccess $access,
        protected PpdbParentAccountService $accounts,
    ) {
    }

    // =====================================================================
    // PORTAL ORANG TUA
    // =====================================================================

    /** GET /ppdb/portal/applicants — daftar anak milik akun pemanggil. */
    public function index(Request $request): JsonResponse
    {
        $account = $this->reader($request);

        $applicants = $this->paginate($request, $this->accounts->applicants($account, $request));

        return $this->successResponse(
            PpdbApplicantResource::collection($applicants)->additional([
                'meta' => [
                    'total' => $this->total($applicants),
                    'can_add' => $account->canTransact(),
                    'account_status' => $account->account_status,
                ],
            ]),
            'Daftar calon siswa berhasil diambil',
        );
    }

    /**
     * POST /ppdb/portal/applicants — tambah anak pada akun yang sama.
     */
    public function store(PpdbApplicantRequest $request): JsonResponse
    {
        $account = $this->access->requireAccount($this->user($request));

        $applicant = $this->accounts->createApplicant($account, PpdbApplicantRequest::portalOnly($request->validated()));

        return $this->createdResponse(
            new PpdbApplicantResource($this->withRelations($applicant)),
            'Data calon siswa berhasil ditambahkan',
        );
    }

    /** GET /ppdb/portal/applicants/{applicant} */
    public function show(Request $request, PpdbApplicant $applicant): JsonResponse
    {
        $this->readable($request, $applicant);

        return $this->successResponse(
            new PpdbApplicantResource($this->withRelations($applicant, true)),
            'Detail calon siswa berhasil diambil',
        );
    }

    /**
     * PUT /ppdb/portal/applicants/{applicant}.
     *
     * Ditolak bila calon sudah dipakai pada pendaftaran yang dikirim: perbaikan
     * data setelah dikirim hanya sah lewat status `need_revision` (panitia
     * meminta revisi) — melihat `PpdbRegistration::EDITABLE_STATUSES`.
     */
    public function update(PpdbApplicantRequest $request, PpdbApplicant $applicant): JsonResponse
    {
        $this->access->applicant($this->user($request), $applicant);
        $this->assertEditable($applicant);

        $applicant = $this->accounts->updateApplicant(
            $applicant,
            PpdbApplicantRequest::portalOnly($request->validated()),
        );

        return $this->successResponse(
            new PpdbApplicantResource($this->withRelations($applicant, true)),
            'Data calon siswa berhasil diperbarui',
        );
    }

    // =====================================================================
    // PANITIA
    // =====================================================================

    /** GET /ppdb/applicants — daftar calon lintas akun (permission ppdb.view). */
    public function adminIndex(Request $request): JsonResponse
    {
        $applicants = $this->paginate($request, $this->accounts->applicantQuery($request));

        return $this->successResponse(
            PpdbApplicantResource::collection($applicants)->additional([
                'meta' => ['total' => $this->total($applicants)],
            ]),
            'Daftar calon siswa berhasil diambil',
        );
    }

    /**
     * GET /ppdb/applicants/{applicant} — detail calon untuk panitia.
     *
     * Hijau-nya jalur ini bukan formalitas: halaman calon di admin membuka
     * detail lewat ID, dan tanpa rute ini tombol "lihat" hanya menghasilkan 404.
     * Kepemilikan tidak diperiksa — yang bertanya sudah lolos
     * `permission:ppdb.view`, berbeda dengan `show()` sisi portal.
     */
    public function adminShow(PpdbApplicant $applicant): JsonResponse
    {
        return $this->successResponse(
            new PpdbApplicantResource($this->withRelations($applicant, true)),
            'Detail calon siswa berhasil diambil',
        );
    }

    /** PUT /ppdb/applicants/{applicant} — panitia boleh menulis catatan & status. */
    public function adminUpdate(PpdbApplicantRequest $request, PpdbApplicant $applicant): JsonResponse
    {
        $applicant = $this->accounts->updateApplicant($applicant, $request->validated());

        return $this->successResponse(
            new PpdbApplicantResource($this->withRelations($applicant, true)),
            'Data calon siswa berhasil diperbarui',
        );
    }

    /** DELETE /ppdb/applicants/{applicant} — calon yang sudah dikirim ditolak. */
    public function destroy(PpdbApplicant $applicant): JsonResponse
    {
        $this->accounts->removeApplicant($applicant);

        return $this->successResponse(null, 'Calon siswa berhasil dihapus');
    }

    // =====================================================================
    // HELPER
    // =====================================================================

    /** @param  Builder<PpdbApplicant>  $query */
    private function paginate(Request $request, Builder $query): mixed
    {
        if ($request->boolean('all')) {
            return $query->get();
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $query->paginate($perPage)->appends($request->query());
    }

    /** Jumlah baris keseluruhan, baik saat berpaginasi maupun `?all=1`. */
    private function total(LengthAwarePaginator|Collection $rows): int
    {
        return $rows instanceof LengthAwarePaginator ? $rows->total() : $rows->count();
    }

    private function withRelations(PpdbApplicant $applicant, bool $withRegistrations = false): PpdbApplicant
    {
        return $applicant->loadMissing([
            'gender', 'religion', 'targetProgram', 'parentAccount.user',
            ...($withRegistrations ? ['registrations.program', 'registrations.period', 'registrations.wave'] : []),
        ]);
    }

    /**
     * Calon yang sudah dipakai pendaftaran terkirim tidak dapat diubah lagi dari
     * portal — kecuali pendaftarannya berstatus draft/need_revision/cancelled.
     */
    private function assertEditable(PpdbApplicant $applicant): void
    {
        $sent = PpdbRegistration::query()
            ->where('applicant_id', $applicant->id)
            ->whereNotIn('status', [...PpdbRegistration::EDITABLE_STATUSES, PpdbRegistration::STATUS_CANCELLED])
            ->count();

        if ($sent > 0) {
            throw ValidationException::withMessages([
                'applicant' => 'Data calon ini sudah dipakai pada pendaftaran yang dikirim dan sedang diproses panitia. '
                    .'Perbaikan hanya dapat dilakukan setelah panitia meminta revisi.',
            ]);
        }
    }

    /**
     * Kunci baca: calon harus milik akun pemanggil. Akun nonaktif tetap boleh
     * melihat datanya (read-only); penulisan menuntut akun aktif lewat
     * `PpdbPortalAccess::applicant()`.
     */
    private function readable(Request $request, PpdbApplicant $applicant): void
    {
        $account = $this->reader($request);

        if ((int) $applicant->parent_account_id !== (int) $account->id) {
            abort(403, 'Data calon siswa tersebut bukan milik Anda.');
        }
    }

    /** Akun pemanggil untuk endpoint hanya-baca. */
    private function reader(Request $request): PpdbParentAccount
    {
        $account = $this->access->account($this->user($request));

        if ($account === null) {
            abort(403, 'Akun PPDB Anda tidak ditemukan. Silakan daftar terlebih dahulu.');
        }

        return $account;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Anda harus masuk sebagai orang tua/wali untuk mengakses portal ini.');
        }

        return $user;
    }
}
