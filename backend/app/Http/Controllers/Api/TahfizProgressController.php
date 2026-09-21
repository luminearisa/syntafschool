<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\TahfizParticipantProgressResource;
use App\Models\TahfizParticipant;
use App\Models\TahfizParticipantProgress;
use App\Services\Tahfiz\ProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Progress & peta hafalan peserta.
 *
 * Tabel `tahfiz_participant_progress` hanya ditulis oleh ProgressService,
 * bukan oleh pengguna. Endpoint ini membacanya serta menyajikan peta hafalan
 * dan pencapaian target.
 */
class TahfizProgressController extends BaseApiController
{
    public function __construct(protected ProgressService $progress)
    {
    }

    /**
     * Ringkasan per surah untuk satu peserta.
     */
    public function index(Request $request, TahfizParticipant $participant): JsonResponse
    {
        $query = TahfizParticipantProgress::query()
            ->with('surah')
            ->where('participant_id', $participant->id);

        if ($juz = $request->query('juz')) {
            $query->where('juz', (int) $juz);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $query->orderBy('juz')->orderBy('surah_id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizParticipantProgressResource::collection($query->get()),
                'Progress hafalan peserta berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizParticipantProgressResource::collection($query->paginate((int) $request->query('per_page', 30))),
            'Progress hafalan peserta berhasil diambil'
        );
    }

    /**
     * Peta hafalan: per surah, rekap per juz, dan total — hingga level ayat.
     */
    public function map(TahfizParticipant $participant): JsonResponse
    {
        return $this->successResponse(
            $this->progress->memorizationMap($participant->id),
            'Peta hafalan peserta berhasil diambil'
        );
    }

    /**
     * Pencapaian target periode berjalan (individual > halaqah > program).
     */
    public function target(Request $request, TahfizParticipant $participant): JsonResponse
    {
        $validated = $request->validate([
            'enrollment_id' => ['nullable', 'integer', 'exists:tahfiz_enrollments,id'],
            'period' => ['nullable', 'string', 'in:daily,weekly,monthly,semester,program'],
            'date' => ['nullable', 'date'],
        ]);

        return $this->successResponse(
            $this->progress->targetProgress(
                $participant->id,
                isset($validated['enrollment_id']) && $validated['enrollment_id'] !== null
                    ? (int) $validated['enrollment_id']
                    : $this->activeEnrollmentId($participant->id),
                $validated['period'] ?? 'weekly',
                $validated['date'] ?? null,
            ),
            'Pencapaian target peserta berhasil diambil'
        );
    }

    /**
     * Enrollment aktif peserta, agar endpoint target tetap bisa dipakai
     * tanpa harus menyertakan enrollment_id secara eksplisit.
     */
    protected function activeEnrollmentId(int $participantId): ?int
    {
        $enrollment = TahfizParticipant::query()
            ->whereKey($participantId)
            ->firstOrFail()
            ->enrollments()
            ->active()
            ->latest('id')
            ->first();

        return $enrollment?->id;
    }
}
