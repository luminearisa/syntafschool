<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbAppealDecisionRequest;
use App\Http\Requests\Ppdb\PpdbAppealRequest;
use App\Http\Resources\PpdbAppealResource;
use App\Models\PpdbRegistration;
use App\Models\PpdbRegistrationAppeal;
use App\Models\User;
use App\Services\Ppdb\PpdbAppealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Masa sanggah atas hasil seleksi.
 *
 * Dua sisi:
 * - portal orang tua (`registrations/{registration}/appeals`): lihat riwayat
 *   sanggahan miliknya + kirim sanggahan (alasan + lampiran opsional). Kepemilikan
 *   diperiksa `PpdbAppealService` lewat `PpdbPortalAccess`, jadi ID pendaftaran
 *   orang lain tidak pernah mengembalikan data.
 * - admin (`appeals`): daftar/lihat/putuskan.
 *
 * Aturan keputusan pemilik (audit §29): **sanggah yang diterima tidak mengubah
 * hasil seleksi**. Status `failed`/`waitlisted` tetap seperti adanya; keputusan
 * `accepted` hanya membuka berkasnya untuk diputus ulang, dan panitia menetapkan
 * hasil baru secara eksplisit lewat `POST /ppdb/registrations/{id}/result`
 * (kuota tetap dijaga). Semua perubahan tercatat di riwayat + audit.
 */
class PpdbAppealController extends BaseApiController
{
    public function __construct(protected PpdbAppealService $appeals)
    {
    }

    /**
     * GET /ppdb/portal/registrations/{registration}/appeals — sanggahan milik
     * pendaftar ini + kelayakan menyanggah saat ini.
     *
     * `eligibility` ikut dikembalikan supaya UI portal menampilkan alasan tombol
     * sanggah tidak aktif (masa sanggah belum dibuka / sudah lewat / masih ada
     * sanggahan pending), bukan sekadar menyembunyikannya.
     */
    public function forRegistration(Request $request, PpdbRegistration $registration): JsonResponse
    {
        $appeals = $this->appeals->forRegistration($registration, $this->actor($request));

        return $this->successResponse([
            'appeals' => PpdbAppealResource::collection($appeals),
            'eligibility' => $this->appeals->eligibility($registration),
            'appealable_results' => PpdbAppealService::APPEALABLE_RESULTS,
        ], 'Daftar sanggahan berhasil diambil');
    }

    /**
     * POST /ppdb/portal/registrations/{registration}/appeals — kirim sanggahan.
     *
     * Ditolak (422) bila masa sanggah tutup, hasil pendaftaran bukan `failed`/
     * `waitlisted`, atau masih ada sanggahan pending — termasuk bila UI diloloskan.
     */
    public function store(PpdbAppealRequest $request, PpdbRegistration $registration): JsonResponse
    {
        $appeal = $this->appeals->submit($registration, $this->actor($request), [
            'reason' => $request->validated('reason'),
            'attachment' => $request->file('attachment'),
        ]);

        return $this->createdResponse(
            new PpdbAppealResource($appeal),
            'Sanggahan berhasil dikirim dan menunggu pemeriksaan panitia',
        );
    }

    /** GET /ppdb/appeals — daftar untuk panitia (pending lebih dulu). */
    public function index(Request $request): JsonResponse
    {
        $appeals = $this->appeals->query($request)
            ->paginate(max(1, min(200, (int) $request->query('per_page', 15))))
            ->appends($request->query());

        return $this->successResponse(
            PpdbAppealResource::collection($appeals),
            'Daftar sanggahan berhasil diambil',
        );
    }

    /** GET /ppdb/appeals/{appeal}. */
    public function show(PpdbRegistrationAppeal $appeal): JsonResponse
    {
        $appeal->loadMissing([
            'registration.applicant:id,full_name,nickname',
            'registration.program:id,name,code',
            'registration.wave:id,name,code',
            'registration.parentAccount.user:id,name,phone',
            'period:id,name,code,appeal_start,appeal_end',
            'reviewedBy:id,name',
        ]);

        return $this->successResponse(
            new PpdbAppealResource($appeal),
            'Detail sanggahan berhasil diambil',
        );
    }

    /**
     * POST /ppdb/appeals/{appeal}/decide — terima atau tolak sanggahan.
     *
     * `review_note` wajib, pemeriksa & waktu distempel, dan hasil seleksi sengaja
     * TIDAK disentuh: `next_action` pada respons mengarahkan panitia ke endpoint
     * penetapan hasil bila memang keputusan hendak diubah.
     */
    public function decide(PpdbAppealDecisionRequest $request, PpdbRegistrationAppeal $appeal): JsonResponse
    {
        $decided = $this->appeals->decide(
            $appeal,
            (string) $request->validated('decision'),
            (string) $request->validated('review_note'),
            $this->actor($request),
        );

        $accepted = $decided->status === PpdbRegistrationAppeal::STATUS_ACCEPTED;

        $decided->loadMissing([
            'registration.applicant:id,full_name,nickname',
            'registration.program:id,name,code',
            'reviewedBy:id,name',
        ]);

        return $this->successResponse([
            'appeal' => new PpdbAppealResource($decided),
            'selection_result' => $decided->registration?->selection_result,
            'result_changed' => false,
            'next_action' => $accepted
                ? 'Berkas dibuka kembali. Tetapkan hasil baru secara eksplisit lewat '
                    .'POST /ppdb/registrations/'.$decided->registration_id.'/result bila memang berubah.'
                : null,
        ], $accepted
            ? 'Sanggahan diterima — hasil seleksi belum berubah, berkas siap diputus ulang panitia'
            : 'Sanggahan ditolak');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_if($user === null, 401, 'Autentikasi diperlukan untuk mengakses data ini.');

        return $user;
    }
}
