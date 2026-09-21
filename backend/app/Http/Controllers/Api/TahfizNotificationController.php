<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\TahfizNotificationResource;
use App\Models\Guardian;
use App\Models\TahfizNotification;
use App\Models\TahfizParticipant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Notifikasi modul Tahfiz per peran.
 *
 * Pemisahan "Saya sebagai Peserta" vs "Anak Saya" ketat: notifikasi milik
 * peserta itu sendiri (user_id = pengguna ini) tidak pernah dicampur dengan
 * notifikasi anak wali, kecuali wali secara eksplisit memilih `participant_id`
 * anaknya — dan itu pun hanya boleh untuk peserta yang memang anaknya.
 */
class TahfizNotificationController extends BaseApiController
{
    protected array $filterable = ['audience', 'type', 'read_at'];

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;

        $query = TahfizNotification::query()->with('participant');

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        // Ruang lingkup: peserta itu sendiri, atau peserta yang menjadi
        // anak dari wali (jika pengguna ini tercatat sebagai wali).
        $visible = TahfizParticipant::query()->where('user_id', $userId)->pluck('id');
        $visible = $visible->merge($this->childParticipantIds($userId));

        $query->where(function (Builder $q) use ($userId, $visible): void {
            $q->where('user_id', $userId)
                ->orWhereIn('participant_id', $visible->all());
        });

        if ($request->has('read')) {
            $request->boolean('read')
                ? $query->whereNotNull('read_at')
                : $query->whereNull('read_at');
        }

        $query->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizNotificationResource::collection($query->get()),
                'Daftar notifikasi berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizNotificationResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar notifikasi berhasil diambil'
        );
    }

    public function show(TahfizNotification $notification): JsonResponse
    {
        $this->authorizeNotification(request(), $notification);

        return $this->successResponse(
            new TahfizNotificationResource($notification->load('participant')),
            'Detail notifikasi berhasil diambil'
        );
    }

    public function markAsRead(Request $request, TahfizNotification $notification): JsonResponse
    {
        $this->authorizeNotification($request, $notification);

        $notification->markAsRead();

        return $this->successResponse(
            new TahfizNotificationResource($notification),
            'Notifikasi berhasil ditandai dibaca'
        );
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $visible = TahfizParticipant::query()->where('user_id', $userId)->pluck('id');
        $visible = $visible->merge($this->childParticipantIds($userId));

        $count = TahfizNotification::query()
            ->whereNull('read_at')
            ->where(function (Builder $q) use ($userId, $visible): void {
                $q->where('user_id', $userId)
                    ->orWhereIn('participant_id', $visible->all());
            })
            ->update(['read_at' => now()]);

        return $this->successResponse(
            ['marked' => $count],
            "{$count} notifikasi berhasil ditandai dibaca"
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $visible = TahfizParticipant::query()->where('user_id', $userId)->pluck('id');
        $visible = $visible->merge($this->childParticipantIds($userId));

        $count = TahfizNotification::query()
            ->whereNull('read_at')
            ->where(function (Builder $q) use ($userId, $visible): void {
                $q->where('user_id', $userId)
                    ->orWhereIn('participant_id', $visible->all());
            })
            ->count();

        return $this->successResponse(
            ['unread' => $count],
            'Jumlah notifikasi belum dibaca berhasil diambil'
        );
    }

    public function destroy(TahfizNotification $notification): JsonResponse
    {
        $this->authorizeNotification(request(), $notification);

        $notification->delete();

        return $this->successResponse(null, 'Notifikasi berhasil dihapus');
    }

    /**
     * Peserta anak-anak dari wali yang merupakan pengguna ini.
     */
    protected function childParticipantIds(?int $userId)
    {
        if ($userId === null) {
            return collect();
        }

        $studentIds = Guardian::query()
            ->where('user_id', $userId)
            ->with('students')
            ->get()
            ->flatMap(fn (Guardian $guardian) => $guardian->students->pluck('id'))
            ->unique();

        if ($studentIds->isEmpty()) {
            return collect();
        }

        return TahfizParticipant::query()
            ->whereIn('student_id', $studentIds->all())
            ->pluck('id');
    }

    /**
     * Pengguna hanya boleh mengakses notifikasinya sendiri atau notifikasi
     * anaknya — tidak boleh notifikasi peserta lain.
     */
    protected function authorizeNotification(Request $request, TahfizNotification $notification): void
    {
        $userId = $request->user()?->id;

        if ($notification->user_id !== null && $notification->user_id === $userId) {
            return;
        }

        $childIds = $this->childParticipantIds($userId);
        if ($notification->participant_id !== null && $childIds->contains($notification->participant_id)) {
            return;
        }

        throw ValidationException::withMessages([
            'notification' => 'Anda tidak memiliki akses ke notifikasi ini.',
        ]);
    }
}
