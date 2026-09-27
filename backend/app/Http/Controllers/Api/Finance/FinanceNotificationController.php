<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\FinanceNotificationResource;
use App\Models\User;
use App\Services\Finance\FinanceNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kotak masuk notifikasi Keuangan milik pengguna yang sedang login.
 *
 * Tidak memakai permission modul: isinya sudah terisolasi per penerima (kanal
 * `database` bawaan Laravel, diskop `notifiable_*`), sehingga seorang wali
 * cukup masuk untuk melihat tagihan anaknya tanpa diberi akses laporan
 * keuangan (§24, §30). Staf pemegang permission juga melihat kotak masuk yang
 * sama di endpoint ini.
 */
class FinanceNotificationController extends BaseApiController
{
    public function __construct(protected FinanceNotificationService $notifications)
    {
    }

    /** GET /finance/notifications — `unread_only=1` untuk belum terbaca saja. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $perPage = max(1, min(50, (int) $request->query('per_page', 20)));

        $rows = $this->notifications->listFor($user, $perPage, $request->boolean('unread_only'));

        return $this->successResponse(
            FinanceNotificationResource::collection($rows)->additional([
                'meta' => [
                    'unread_count' => $this->notifications->unreadCount($user),
                    'type_options' => FinanceNotificationResource::TYPE_LABELS,
                ],
            ]),
            'Daftar notifikasi berhasil diambil',
        );
    }

    /** GET /finance/notifications/unread-count. */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $this->actor($request);

        return $this->successResponse([
            'unread' => $this->notifications->unreadCount($user),
            'total' => $this->notifications->totalFor($user),
        ], 'Jumlah notifikasi belum dibaca berhasil diambil');
    }

    /** POST /finance/notifications/{notification}/read — hanya milik sendiri. */
    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $ok = $this->notifications->markAsRead($this->actor($request), $notification);

        return $ok
            ? $this->successResponse(null, 'Notifikasi ditandai sudah dibaca')
            : $this->errorResponse('Notifikasi tidak ditemukan.', 404);
    }

    /** POST /finance/notifications/read-all. */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $count = $this->notifications->markAllAsRead($user);

        return $this->successResponse([
            'marked_as_read' => $count,
            'unread' => $this->notifications->unreadCount($user),
        ], "{$count} notifikasi ditandai sudah dibaca");
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_if($user === null, 401, 'Anda belum terautentikasi.');

        return $user;
    }
}
