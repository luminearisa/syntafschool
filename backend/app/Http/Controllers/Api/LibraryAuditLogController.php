<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\LibraryActivityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * Jejak audit modul Library (baca saja).
 *
 * Dibaca dari tabel `activity_log` milik paket spatie/laravel-activitylog —
 * tidak ada tabel audit terpisah, dan tidak ada endpoint tulis sehingga
 * catatan tidak dapat dimanipulasi lewat API.
 *
 * Otorisasi ditegakkan middleware rute (`permission:library.audit.view`);
 * tidak ada model yang dapat dipakai untuk Policy karena jejak audit bersifat
 * global, bukan milik satu perpustakaan.
 */
class LibraryAuditLogController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Activity::query()->with('causer')->where('log_name', 'library');

        if ($event = $request->query('event')) {
            $query->where('event', $event);
        }

        if ($subjectType = $request->query('subject_type')) {
            $query->where('subject_type', 'like', '%'.class_basename($subjectType));
        }

        if ($subjectId = $request->query('subject_id')) {
            $query->where('subject_id', $subjectId);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        if ($search = $request->query('search')) {
            $query->where('description', 'like', "%{$search}%");
        }

        $query->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                LibraryActivityResource::collection($query->get()),
                'Jejak audit perpustakaan berhasil diambil'
            );
        }

        return $this->successResponse(
            LibraryActivityResource::collection($query->paginate((int) $request->query('per_page', 20))),
            'Jejak audit perpustakaan berhasil diambil'
        );
    }
}