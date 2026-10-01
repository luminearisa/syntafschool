<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Activity\Application\Services\ActivityScheduleService;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivitySchedule;
use App\Modules\Activity\Models\ActivitySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Jadwal, sesi, materi, pengumuman, dan pemesanan fasilitas (\§29\–32).
 */
class ActivityScheduleController extends BaseApiController
{
    public function __construct(private readonly ActivityScheduleService $schedules) {}

    public function schedules(ActivityProgram $program): JsonResponse
    {
        return $this->successResponse($program->schedules()->with('room:id,name')->orderBy('day_of_week')->get());
    }

    public function storeSchedule(Request $request, ActivityProgram $program): JsonResponse
    {
        $request->validate([
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'coach_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'session_type' => ['nullable', Rule::in(['REGULAR', 'SPECIAL', 'HOLIDAY', 'EXAM'])],
            'book_facility' => ['nullable', 'boolean'],
            'facility_date' => ['nullable', 'date'],
        ]);

        return $this->createdResponse(
            $this->schedules->createSchedule($program, $request->all(), $request->user()),
            'Jadwal aktivitas dibuat.',
        );
    }

    public function updateSchedule(ActivitySchedule $schedule, Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->schedules->updateSchedule($schedule, $request->all(), $request->user()),
            'Jadwal diperbarui.',
        );
    }

    public function deleteSchedule(ActivitySchedule $schedule, Request $request): JsonResponse
    {
        $this->schedules->deleteSchedule($schedule, $request->user());

        return $this->successResponse(null, 'Jadwal dihapus.');
    }

    // ----------------------------------------------------------------- sesi

    public function sessions(Request $request): JsonResponse
    {
        return $this->successResponse($this->schedules->sessions($request->all()));
    }

    public function storeSession(Request $request, ActivityProgram $program): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'topic' => ['nullable', 'string', 'max:191'],
            'objective' => ['nullable', 'string'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
        ]);

        return $this->createdResponse(
            $this->schedules->createSession($program, $request->all(), $request->user()),
            'Sesi dibuat.',
        );
    }

    public function updateSession(ActivitySession $session, Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->schedules->updateSession($session, $request->all(), $request->user()),
            'Sesi diperbarui.',
        );
    }

    public function cancelSession(ActivitySession $session, Request $request): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->successResponse(
            $this->schedules->cancelSession($session, $request->user(), (string) $request->input('reason')),
            'Sesi dibatalkan.',
        );
    }

    /** Buat sesi massal dari jadwal berulang (idempoten). */
    public function generateSessions(Request $request, ActivityProgram $program): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return $this->successResponse(
            $this->schedules->generateSessions($program, $request->input('from'), $request->input('to'), $request->user()),
            'Pembuatan sesi selesai.',
        );
    }

    // --------------------------------------------------------------- materi

    public function materials(ActivityProgram $program): JsonResponse
    {
        return $this->successResponse($this->schedules->materials($program));
    }

    public function storeMaterial(Request $request, ActivityProgram $program): JsonResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'type' => ['nullable', 'string', 'max:24'],
            'session_id' => ['nullable', 'integer', 'exists:activity_sessions,id'],
            'url' => ['nullable', 'string', 'max:500'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'lms_reference' => ['nullable', 'string', 'max:191'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        return $this->createdResponse(
            $this->schedules->addMaterial($program, $request->all(), $request->user()),
            'Materi ditambahkan.',
        );
    }

    // ------------------------------------------------------------ pengumuman

    public function announcements(ActivityProgram $program): JsonResponse
    {
        return $this->successResponse($this->schedules->announcements($program));
    }

    public function storeAnnouncement(Request $request, ActivityProgram $program): JsonResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string'],
            'audience' => ['nullable', 'string', 'in:MEMBERS,COACHES,PARENTS,ALL'],
            'notify' => ['nullable', 'boolean'],
        ]);

        return $this->createdResponse(
            $this->schedules->announce($program, $request->all(), $request->user()),
            'Pengumuman dibuat.',
        );
    }
}
