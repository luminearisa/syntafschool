<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbParentAccountStatusRequest;
use App\Http\Resources\PpdbApplicantResource;
use App\Http\Resources\PpdbParentAccountResource;
use App\Models\PpdbApplicant;
use App\Models\PpdbParentAccount;
use App\Services\Ppdb\PpdbNotificationService;
use App\Services\Ppdb\PpdbParentAccountService;
use App\Services\Ppdb\PpdbRegistrationQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Kelola akun orang tua/wali PPDB (admin, permission `ppdb.account.manage`).
 *
 * Di sinilah "1 orang tua → N anak" terlihat oleh sekolah: baris daftar membawa
 * jumlah anak dan jumlah pendaftaran, detailnya membawa seluruh calon milik
 * akun tersebut plus ringkasan agregat pendaftarannya.
 *
 * Controller ini TIDAK menghitung apa pun sendiri: daftar & filter dari
 * `PpdbParentAccountService::adminQuery()`, perubahan status/password dari
 * service yang sama (termasuk jejak auditnya), agregat status pendaftaran dari
 * `PpdbRegistrationQueryService::counts()`.
 *
 * Catatan privasi: data pribadi orang tua hanya keluar ke pemegang
 * `ppdb.account.manage`; tidak ada endpoint publik di kelas ini.
 */
class PpdbParentAccountController extends BaseApiController
{
    /**
     * Tipe notifikasi bebas untuk info reset password — `PpdbActivityNotification`
     * hanya membakukan alur utama, dan kelas itu bukan milik modul ini untuk
     * diubah. Mengikuti preseden `account_blocked` di service akun.
     */
    public const NOTIFICATION_PASSWORD_RESET = 'account_password_reset';

    public function __construct(
        protected PpdbParentAccountService $accounts,
        protected PpdbRegistrationQueryService $queries,
        protected PpdbNotificationService $notifications,
    ) {
    }

    /**
     * GET /ppdb/parent-accounts — pencarian nama/email/telepon/anak/nomor
     * pendaftaran, filter status akun, dan paginasi.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->accounts->adminQuery($request);

        $accounts = $request->boolean('all')
            ? $query->get()
            : $query->paginate($this->perPage($request))->appends($request->query());

        return $this->successResponse(
            PpdbParentAccountResource::collection($accounts)->additional(['meta' => $this->meta()]),
            'Daftar akun orang tua PPDB berhasil diambil',
        );
    }

    /**
     * GET /ppdb/parent-accounts/{parentAccount} — akun + anak-anaknya +
     * agregat pendaftaran. Nama anak boleh tampil (panitia memang mengurusnya);
     * isi pendaftaran, tagihan, dan dokumen TIDAK — itu milik endpoint
     * pendaftaran/pembayaran dengan permission-nya sendiri.
     */
    public function show(PpdbParentAccount $parentAccount): JsonResponse
    {
        $account = $parentAccount->loadMissing([
            'user', 'guardian', 'applicants.gender', 'applicants.targetProgram', 'registrations',
        ]);

        $data = (new PpdbParentAccountResource($account, true))->resolve();

        // Anak-anaknya (calon peserta didik) — selalu ada, walau kosong, agar
        // layar admin tidak membedakan "belum dimuat" dan "tidak ada anak".
        $data['applicants'] = $account->applicants
            ->map(fn (PpdbApplicant $child): array => PpdbApplicantResource::row($child))
            ->all();

        // Agregat saja: status alur, status pembayaran, status dokumen.
        $data['registration_summary'] = $this->queries->counts($account->registrations);
        $data['children_note'] = sprintf(
            'Akun ini menaungi %d anak dengan %d pendaftaran. Satu akun dipakai bersama untuk seluruh anak.',
            count($data['applicants']),
            $account->registrations->count(),
        );

        // Pendaftaran terakhir + jejak audit akun (sudah agregat & ber-label).
        $data += $this->accounts->activity($account);

        return $this->successResponse($data, 'Detail akun orang tua PPDB berhasil diambil');
    }

    /**
     * POST /ppdb/parent-accounts/{parentAccount}/status.
     *
     * Hanya 4 status yang dikenal model (`pending_verification`, `active`,
     * `inactive`, `blocked`) — sisanya ditolak FormRequest (422). Menonaktifkan
     * atau memblokir akun langsung menghentikan transaksi portal karena
     * `canTransact()` bernilai false; data lama tetap terbaca.
     */
    public function updateStatus(PpdbParentAccountStatusRequest $request, PpdbParentAccount $parentAccount): JsonResponse
    {
        $account = $this->accounts->updateStatus(
            $parentAccount,
            (string) $request->validated('account_status'),
            $request->validated('notes') === null ? null : (string) $request->validated('notes'),
            $request->user(),
        );

        return $this->successResponse([
            'parent_account' => (new PpdbParentAccountResource($account, true))->resolve(),
            'can_transact' => $account->canTransact(),
            'portal_effect' => $account->canTransact()
                ? 'Akun dapat mengakses dan mengubah data portal.'
                : 'Akun hanya dapat membaca data portal (read-only) sampai status aktif kembali.',
        ], sprintf(
            'Status akun berubah menjadi %s.',
            PpdbParentAccountService::statusLabel((string) $account->account_status),
        ));
    }

    /**
     * POST /ppdb/parent-accounts/{parentAccount}/reset-password.
     *
     * Password sementara dikembalikan SEKALI di respons dan tidak pernah masuk
     * log, notifikasi, maupun kolom database — yang tersimpan hanya hash-nya.
     * Orang tua diberi tahu lewat notifikasi bahwa passwordnya disetel ulang
     * (tanpa isi passwordnya).
     */
    public function resetPassword(Request $request, PpdbParentAccount $parentAccount): JsonResponse
    {
        $password = $this->accounts->resetPassword($parentAccount, $request->user());

        $this->notifications->toParent(
            $parentAccount,
            self::NOTIFICATION_PASSWORD_RESET,
            'Password PPDB disetel ulang',
            sprintf(
                'Panitia PPDB menyetel ulang password akun Anda pada %s. Silakan masuk dengan password sementara yang diberikan petugas, lalu segera ganti password Anda.',
                now()->toDateString(),
            ),
            ['parent_account_id' => $parentAccount->id],
        );

        return $this->successResponse([
            'parent_account' => (new PpdbParentAccountResource($parentAccount->refresh(), true))->resolve(),
            'temporary_password' => $password,
            'one_time_credential' => true,
            'warning' => 'Password sementara ini hanya ditampilkan satu kali dan tidak akan dapat dibuka kembali. '
                .'Sampaikan langsung kepada yang bersangkutan, lalu minta ia menggantinya setelah masuk.',
        ], 'Password sementara akun orang tua berhasil dibuat');
    }

    /**
     * Penghapusan akun: SELALU soft delete, dan ditolak bila akun masih punya
     * jejak pendaftaran yang meminta penghapusan permanen — riwayat pendaftaran,
     * tagihan, dan siswa hasil konversi menautkan `parent_account_id`.
     *
     * Rute `DELETE /ppdb/parent-accounts/{parentAccount}` belum ada di
     * `routes/api/ppdb.php` (file beku), jadi method ini sengaja disiapkan agar
     * aturannya tidak dilanggar begitu rutenya dibuat.
     */
    public function destroy(Request $request, PpdbParentAccount $parentAccount): JsonResponse
    {
        $registrationCount = (int) $parentAccount->registrations()->count();

        if ($request->boolean('force') && $registrationCount > 0) {
            throw ValidationException::withMessages([
                'parent_account' => sprintf(
                    'Akun ini punya %d pendaftaran sehingga tidak dapat dihapus permanen. '
                    .'Data orang tua dan riwayatnya harus tetap tertelusur — gunakan soft delete '
                    .'atau ubah status menjadi inactive/blocked.',
                    $registrationCount,
                ),
            ]);
        }

        activity('ppdb')
            ->event('PPDB_ACCOUNT_DELETED')
            ->performedOn($parentAccount)
            ->causedBy($request->user())
            ->withProperties(['registration_count' => $registrationCount, 'forced' => false])
            ->log(sprintf(
                'Akun orang tua %s dihapus lembut (soft delete) oleh %s.',
                (string) $parentAccount->user?->email,
                (string) $request->user()?->name,
            ));

        $parentAccount->delete();

        return $this->successResponse([
            'parent_account_id' => $parentAccount->id,
            'deleted' => 'soft',
            'registration_count' => $registrationCount,
        ], 'Akun orang tua disembunyikan (soft delete). Data pendaftarannya tetap utuh.');
    }

    /**
     * Konteks daftar: opsi status berlabel Indonesia + jumlah akun per status,
     * agar filter admin tidak menebak label atau angka.
     *
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        $counts = PpdbParentAccount::query()
            ->selectRaw('account_status, COUNT(*) as aggregate')
            ->groupBy('account_status')
            ->pluck('aggregate', 'account_status');

        $byStatus = [];

        foreach (PpdbParentAccount::STATUSES as $status) {
            $byStatus[$status] = (int) $counts->get($status, 0);
        }

        return [
            'total_accounts' => (int) $counts->sum(),
            'by_status' => $byStatus,
            'status_options' => PpdbParentAccountService::statusOptions(),
        ];
    }

    private function perPage(Request $request): int
    {
        return max(1, min(200, (int) $request->query('per_page', 15)));
    }
}
