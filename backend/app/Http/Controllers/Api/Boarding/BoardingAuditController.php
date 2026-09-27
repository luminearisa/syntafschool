<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * Jejak audit modul asrama (baca saja) — dibaca dari `activity_log` milik
 * spatie/laravel-activitylog dengan log_name `boarding`. Tidak ada endpoint
 * tulis, jadi catatan tidak bisa dimanipulasi lewat API.
 */
class BoardingAuditController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Activity::query()->with('causer')->where('log_name', 'boarding');

        if ($event = $request->query('event')) {
            $query->where('event', $event);
        }

        if ($subjectType = $request->query('subject_type')) {
            $query->where('subject_type', 'like', '%'.class_basename($subjectType));
        }

        if ($subjectId = $request->query('subject_id')) {
            $query->where('subject_id', $subjectId);
        }

        if ($causerId = $request->query('causer_id')) {
            $query->where('causer_id', $causerId);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        if ($search = $request->query('search')) {
            $query->where('description', 'like', '%'.$search.'%');
        }

        $query->orderByDesc('id');

        $map = static fn (Activity $activity): array => [
            'id' => (int) $activity->id,
            'event' => $activity->event,
            'description' => $activity->description,
            'subject_type' => $activity->subject_type === null ? null : class_basename($activity->subject_type),
            'subject_id' => $activity->subject_id === null ? null : (int) $activity->subject_id,
            'causer' => $activity->causer?->name,
            'causer_id' => $activity->causer_id === null ? null : (int) $activity->causer_id,
            'properties' => $activity->properties ?? null,
            'created_at' => $activity->created_at?->toIso8601String(),
        ];

        if ($request->boolean('all')) {
            return $this->successResponse(
                array_map($map, $query->limit(5000)->get()->all()),
                'Jejak audit asrama berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 20)));

        $paginator = $query->paginate($perPage)->appends($request->query());

        return $this->successResponse([
            'data' => array_map($map, $paginator->items()),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ], 'Jejak audit asrama berhasil diambil');
    }
}
