<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbParentRegisterRequest;
use App\Http\Resources\PpdbParentAccountResource;
use App\Models\PpdbParentAccount;
use App\Services\Ppdb\PpdbParentAccountService;
use Illuminate\Http\JsonResponse;

/**
 * Pendaftaran akun orang tua/wali mandiri (halaman publik, TANPA login).
 *
 * Rute ini sudah di-throttle 10x/menit di `routes/api/ppdb.php`; validitas
 * email/telepon/password diurus `PpdbParentRegisterRequest`.
 *
 * Identitas TIDAK disalin ke tabel PPDB: service membuat baris `users` +
 * `guardians` yang memang sudah ada di sistem inti, lalu satu baris
 * `ppdb_parent_accounts` yang menautkan keduanya. SATU akun dipakai untuk
 * SEMUA anak — tidak ada akun per anak; jumlah anak muncul di `children_count`
 * dan tiap anak nanti menjadi `ppdb_applicants` sendiri.
 */
class PpdbParentAuthController extends BaseApiController
{
    /** Penjelasan aturan "satu akun untuk semua anak" yang ikut dikirim ke UI. */
    public const SHARED_ACCOUNT_NOTE = 'Satu akun orang tua/wali ini dipakai untuk semua anak. '
        .'Tambahkan setiap anak sebagai calon peserta didik pada akun yang sama — jangan buat akun baru.';

    public function __construct(protected PpdbParentAccountService $accounts)
    {
    }

    /**
     * POST /ppdb/auth/register.
     *
     * Balasan berisi token Sanctum dengan cara yang sama seperti
     * `AuthController@login`, sehingga pendaftar langsung masuk ke portal tanpa
     * perlu mengetik ulang kredensial.
     */
    public function register(PpdbParentRegisterRequest $request): JsonResponse
    {
        // Aturan status akun (langsung aktif atau menunggu verifikasi panitia)
        // hidup di PpdbParentAccountService::register() — controller tidak
        // menentukannya sendiri.
        $account = $this->accounts->register($request->validated());
        $user = $account->user;

        $token = $user->createToken((string) $request->input('device_name', 'ppdb-portal'))->plainTextToken;

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->createdResponse([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->getRoleNames(),
            ],
            'parent_account' => (new PpdbParentAccountResource($account))->resolve(),
            'children_count' => (int) $account->applicants()->count(),
            'one_account_for_all_children' => true,
            'account_note' => self::SHARED_ACCOUNT_NOTE,
            'can_transact' => $account->canTransact(),
            'account_status' => (string) $account->account_status,
            'account_status_label' => PpdbParentAccountService::statusLabel((string) $account->account_status),
            'next_steps' => $this->nextSteps($account),
        ], 'Pendaftaran akun orang tua/wali berhasil. Silakan lengkapi profil dan tambahkan data anak.');
    }

    /**
     * Langkah berikutnya sesuai status akun — status nonaktif/pending berarti
     * harus menunggu panitia sebelum bisa mengisi formulir.
     *
     * @return list<string>
     */
    private function nextSteps(PpdbParentAccount $account): array
    {
        if (! $account->canTransact()) {
            return [
                'Akun Anda sedang menunggu verifikasi panitia PPDB.',
                'Setelah aktif, lengkapi profil, tambahkan data anak, lalu buat pendaftaran.',
                'Hubungi panitia melalui kanal kontak pada halaman informasi PPDB bila terlalu lama.',
            ];
        }

        return [
            'Lengkapi profil orang tua/wali Anda.',
            'Tambahkan data anak (calon peserta didik) — satu akun untuk semua anak.',
            'Pilih periode, gelombang, dan program, lalu isi formulir pendaftaran.',
            'Unggah dokumen yang diminta dan kirim pendaftaran sebelum batas waktu.',
        ];
    }
}
