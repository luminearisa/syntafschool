<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\PpdbNotificationResource;
use App\Models\User;
use App\Notifications\PpdbActivityNotification;
use App\Services\Ppdb\PpdbNotificationService;
use App\Services\Ppdb\PpdbPortalAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Kotak masuk notifikasi PPDB portal orang tua.
 *
 * Memakai tabel `notifications` + kanal `database` bawaan Laravel (sistem yang
 * sudah dipakai modul lain) — tidak ada tabel atau notification engine baru.
 * Isi payload mengikuti `PpdbActivityNotification::toArray()`: `type`, `title`,
 * `body`, ditambah konteks yang disertakan pengirim.
 *
 * Dua penjaga keamanan:
 * 1. Daftar & hitung selalu diskop pada notifikasi milik pengguna yang sedang
 *    login (`notifiable_id`/`notifiable_type` + `type` = notifikasi PPDB), jadi
 *    pengguna tidak pernah melihat inbox orang lain.
 * 2. `markAsRead` menerima ID dari route — ID apa pun akan ter-resolve, sehingga
 *    kepemilikannya WAJIB diuji lewat `PpdbPortalAccess::notification()` (403
 *    untuk milik orang lain) sebelum apa pun diubah.
 *
 * Membaca kotak masuk cukup untuk akun yang ada (termasuk pending/nonaktif/
 * diblokir) supaya pesan "akun Anda diblokir" tetap sampai; menandai terbaca
 * adalah aksi tulis sehingga memakai `requireAccount()` — akun yang belum aktif
 * menolak 403, sama seperti endpoint tulis portal lainnya.
 */
class PpdbNotificationController extends BaseApiController
{
    public function __construct(
        protected PpdbNotificationService $notifications,
        protected PpdbPortalAccess $access,
    ) {
    }

    /**
     * GET /ppdb/portal/notifications — paginasi, `unread_only=1` untuk daftar
     * belum terbaca saja.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->reader($request);
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));

        $rows = $request->boolean('unread_only')
            ? $this->inbox($user)->whereNull('read_at')->paginate($perPage)->appends($request->query())
            : $this->notifications->listFor($user, $perPage);

        return $this->successResponse(
            PpdbNotificationResource::collection($rows)->additional([
                'meta' => [
                    'unread_count' => $this->notifications->unreadCount($user),
                    'type_options' => PpdbNotificationResource::TYPE_LABELS,
                ],
            ]),
            'Daftar notifikasi berhasil diambil',
        );
    }

    /** GET /ppdb/portal/notifications/unread-count. */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $this->reader($request);

        return $this->successResponse([
            'unread' => $this->notifications->unreadCount($user),
            'total' => $this->inbox($user)->count(),
        ], 'Jumlah notifikasi belum dibaca berhasil diambil');
    }

    /**
     * POST /ppdb/portal/notifications/read-all — hanya baris milik pemanggil.
     */
    public function readAll(Request $request): JsonResponse
    {
        $user = $this->transactor($request);

        $marked = $this->notifications->markAllAsRead($user);

        return $this->successResponse([
            'marked_as_read' => $marked,
            'unread' => $this->notifications->unreadCount($user),
        ], sprintf('%d notifikasi ditandai sudah dibaca.', $marked));
    }

    /**
     * POST /ppdb/portal/notifications/{notification}/read.
     *
     * Baris diambil tanpa diskop pemilik supaya milik orang lain bisa dibalas
     * 403 secara tegas (alih-alih 404 yang membuat frontend menebak), lalu
     * diuji kepemilikannya sebelum `read_at` ditulis.
     */
    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $user = $this->transactor($request);

        $row = DatabaseNotification::query()
            ->where('type', PpdbActivityNotification::class)
            ->whereKey($notification)
            ->first();

        abort_if($row === null, 404, 'Notifikasi tidak ditemukan.');

        $this->access->notification($user, $row);

        $this->notifications->markAsRead($user, (string) $row->id);

        return $this->successResponse(
            new PpdbNotificationResource($row->refresh()),
            'Notifikasi ditandai sudah dibaca',
        );
    }

    /**
     * Kotak masuk PPDB milik pengguna ini saja.
     *
     * `getQuery()` dipakai karena `MorphMany::where()` mengembalikan objek
     * relasi (bukan Builder) — constraint `notifiable_*` sudah terpasang saat
     * relasi dibuat, jadi hasil tetap terisolasi per pengguna.
     */
    private function inbox(User $user): Builder
    {
        return $user->notifications()
            ->getQuery()
            ->where('type', PpdbActivityNotification::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Pembaca: harus punya akun PPDB (status apa pun) — penolakan bersih 403,
     * bukan 500, bagi pengguna tanpa akun (mis. staf umum).
     */
    private function reader(Request $request): User
    {
        $user = $request->user();

        abort_if($user === null, 401, 'Anda belum terautentikasi.');

        if ($this->access->account($user) === null) {
            abort(403, 'Akun PPDB Anda tidak ditemukan. Silakan daftar melalui halaman pendaftaran PPDB.');
        }

        return $user;
    }

    /** Penulis: akun PPDB yang boleh bertransaksi (lihat `PpdbPortalAccess`). */
    private function transactor(Request $request): User
    {
        $user = $request->user();

        abort_if($user === null, 401, 'Anda belum terautentikasi.');

        $this->access->requireAccount($user);

        return $user;
    }
}
