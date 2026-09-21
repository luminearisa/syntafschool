<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbProfileRequest;
use App\Http\Resources\GuardianResource;
use App\Http\Resources\PpdbApplicantResource;
use App\Http\Resources\PpdbParentAccountResource;
use App\Http\Resources\UserResource;
use App\Models\PpdbParentAccount;
use App\Models\User;
use App\Services\Ppdb\PpdbNotificationService;
use App\Services\Ppdb\PpdbParentAccountService;
use App\Services\Ppdb\PpdbPortalAccess;
use App\Services\Ppdb\PpdbRegistrationQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Beranda portal orang tua: ringkasan akun, profil, dan calon (anak).
 *
 * Kelas ini sengaja TEBAL tipis: seluruh pembacaan data dititipkan ke
 * `PpdbRegistrationQueryService` dan penulisan profil ke
 * `PpdbParentAccountService`. Yang dikerjakan di sini hanya
 * 1) memastikan pemanggil punya akun PPDB, dan
 * 2) membatasi seluruh query pada akun tersebut lewat `PpdbPortalAccess`.
 *
 * Identitas orang tua tidak disalin ke tabel PPDB: nama/email/telepon tinggal
 * di `users`, detail wali di `guardians` (aturan audit §"Profil").
 */
class PpdbPortalController extends BaseApiController
{
    public function __construct(
        protected PpdbPortalAccess $access,
        protected PpdbRegistrationQueryService $queries,
        protected PpdbParentAccountService $accounts,
        protected PpdbNotificationService $notifications,
    ) {
    }

    /**
     * GET /ppdb/portal/dashboard — kartu pendaftaran, tenggat, dan penghitung.
     *
     * Boleh dibaca akun nonaktif (read-only); hanya endpoint tulis yang
     * menuntut akun aktif.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $account = $this->reader($request);

        return $this->successResponse(
            $this->queries->dashboard($account, $this->notifications->unreadCount($this->user($request))),
            'Ringkasan portal berhasil diambil',
        );
    }

    /**
     * GET /ppdb/portal/profile — profil orang tua apa adanya.
     *
     * `account` = payload akun (status + can_transact), `user` & `guardian` =
     * baris asli dari tabel inti supaya UI tidak perlu menebak kolom.
     */
    public function profile(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->profilePayload($this->reader($request)),
            'Profil berhasil diambil',
        );
    }

    /**
     * PUT /ppdb/portal/profile — tulis ke `users` + `guardians`.
     *
     * Mutasi: akun harus aktif (`PpdbPortalAccess::requireAccount()`), jadi
     * akun pending_verification / blocked / inactive ditolak 403.
     */
    public function updateProfile(PpdbProfileRequest $request): JsonResponse
    {
        $account = $this->access->requireAccount($this->user($request));

        $this->accounts->updateProfile($account, $request->validated());

        return $this->successResponse(
            $this->profilePayload($account->fresh()),
            'Profil berhasil diperbarui',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(PpdbParentAccount $account): array
    {
        $account->loadMissing(['user', 'guardian.occupation', 'guardian.incomeRange', 'guardian.educationBackground', 'applicants']);

        return [
            'account' => new PpdbParentAccountResource($account),
            'parent' => $this->queries->parentPayload($account),
            'user' => $account->user === null ? null : new UserResource($account->user),
            'guardian' => $account->guardian === null ? null : new GuardianResource($account->guardian),
            'children_count' => (int) $account->applicants()->count(),
            'applicants' => PpdbApplicantResource::collection($account->applicants),
        ];
    }

    /** Akun pemanggil untuk endpoint HANYA-BACA. */
    private function reader(Request $request): PpdbParentAccount
    {
        $user = $this->user($request);
        $account = $this->access->account($user);

        if ($account === null) {
            abort(403, 'Akun PPDB Anda tidak ditemukan. Silakan daftar terlebih dahulu.');
        }

        // Stamp aktivitas (dibatasi 5 menit per akun) tanpa menuntut akun aktif.
        $this->accounts->touchActivity($account);

        return $account->fresh();
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
