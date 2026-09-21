<?php

namespace App\Http\Controllers\Api;

use App\Services\Library\LibraryNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifikasi Library milik pengguna yang sedang login.
 *
 * Tidak memerlukan permission modul: setiap pengguna hanya melihat
 * notifikasinya sendiri (kanal `database` bawaan Laravel).
 */
class LibraryNotificationController extends BaseApiController
{
    public function __construct(protected LibraryNotificationService $notifications)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->notifications->listFor($request->user(), (int) $request->query('per_page', 20));

        return $this->successResponse($paginator, 'Daftar notifikasi berhasil diambil');
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->successResponse(
            ['count' => $this->notifications->unreadCount($request->user())],
            'Jumlah notifikasi belum dibaca'
        );
    }

    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $ok = $this->notifications->markAsRead($request->user(), $notification);

        return $ok
            ? $this->successResponse(null, 'Notifikasi ditandai sudah dibaca')
            : $this->errorResponse('Notifikasi tidak ditemukan.', 404);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $count = $this->notifications->markAllAsRead($request->user());

        return $this->successResponse(['updated' => $count], "{$count} notifikasi ditandai sudah dibaca");
    }
}