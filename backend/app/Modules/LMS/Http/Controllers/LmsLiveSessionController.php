<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\LiveSessionService;
use App\Modules\LMS\Domain\Enums\LiveAttendanceStatus;
use App\Modules\LMS\Domain\Enums\LiveSessionProvider;
use App\Modules\LMS\Domain\Enums\LiveSessionType;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsLiveSession;
use App\Modules\LMS\Models\LmsLiveSessionRecording;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kelas langsung, kehadiran, dan rekaman (§13–§17).
 */
class LmsLiveSessionController extends LmsController
{
    public function __construct(
        private readonly LiveSessionService $sessions,
        private readonly CourseAccessService $access,
    ) {}

    /** GET /lms/courses/{course}/live-sessions */
    public function index(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $staff = $this->access->canManage($model, $actor);

        $this->authorizeLms(
            $staff || $model->enrollmentFor($actor->id) !== null,
            'Anda tidak memiliki akses ke kelas langsung course ini.',
        );

        return $this->successResponse([
            'options' => [
                'types' => LiveSessionType::values(),
                'providers' => LiveSessionProvider::values(),
                'attendance_statuses' => LiveAttendanceStatus::values(),
            ],
            'sessions' => $this->sessions->forCourse($model, $staff, [
                'type' => $request->query('type'),
                'status' => $request->query('status'),
                'upcoming' => $request->boolean('upcoming'),
            ]),
            'next' => $this->sessions->nextSession($model),
        ]);
    }

    /** GET /lms/my/live-sessions */
    public function mine(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        return $this->successResponse([
            'live_now' => $this->sessions->liveNowForUser((int) $actor->id),
            'sessions' => $this->sessions->forStudent((int) $actor->id, (int) $this->perPage($request, 20)),
        ]);
    }

    /** POST /lms/courses/{course}/live-sessions */
    public function store(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeLiveManage($model);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'provider' => ['nullable', 'string'],
            'start_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            'timezone' => ['nullable', 'string', 'max:60'],
            'host_url' => ['nullable', 'url', 'max:1000'],
            'join_url' => ['nullable', 'url', 'max:1000'],
            'passcode' => ['nullable', 'string', 'max:60'],
            'section_id' => ['nullable', 'integer'],
            'item_id' => ['nullable', 'integer'],
            'recording_url' => ['nullable', 'url', 'max:1000'],
            'settings' => ['nullable', 'array'],
        ]);

        return $this->createdResponse(
            $this->sessions->create($model, $data, $actor),
            'Kelas langsung dibuat.',
        );
    }

    /** GET /lms/live-sessions/{session} */
    public function show(Request $request, int $session): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->session($session);
        $staff = $this->access->canManage($model->course, $actor);

        $this->authorizeLms(
            $staff || $model->course->enrollmentFor($actor->id) !== null,
            'Anda tidak memiliki akses ke kelas langsung ini.',
        );

        return $this->successResponse($this->sessions->payload($model, $staff));
    }

    /** PUT /lms/live-sessions/{session} */
    public function update(Request $request, int $session): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->session($session);
        $this->authorizeLiveManage($model->course);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'provider' => ['nullable', 'string'],
            'start_at' => ['sometimes', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            'host_url' => ['nullable', 'url', 'max:1000'],
            'join_url' => ['nullable', 'url', 'max:1000'],
            'passcode' => ['nullable', 'string', 'max:60'],
            'settings' => ['nullable', 'array'],
        ]);

        return $this->successResponse($this->sessions->update($model, $data, $actor), 'Kelas langsung diperbarui.');
    }

    /** POST /lms/live-sessions/{session}/publish */
    public function publish(Request $request, int $session): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->session($session);
        $this->authorizeLiveManage($model->course);

        return $this->successResponse($this->sessions->publish($model, $actor), 'Jadwal dikirim ke peserta.');
    }

    /** POST /lms/live-sessions/{session}/cancel */
    public function cancel(Request $request, int $session): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->session($session);
        $this->authorizeLiveManage($model->course);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->successResponse(
            $this->sessions->cancel($model, (string) ($data['reason'] ?? 'Dibatalkan oleh pengajar.'), $actor),
            'Kelas langsung dibatalkan.',
        );
    }

    /** DELETE /lms/live-sessions/{session} */
    public function destroy(Request $request, int $session): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->session($session);
        $this->authorizeLiveManage($model->course);

        $this->sessions->delete($model, $actor);

        return $this->successResponse(null, 'Kelas langsung dihapus.');
    }

    /** POST /lms/live-sessions/{session}/refresh */
    public function refresh(Request $request, int $session): JsonResponse
    {
        $model = $this->session($session);
        $this->authorizeLiveManage($model->course);

        $this->sessions->refreshStatus($model);

        return $this->successResponse($this->sessions->payload($model, true), 'Status kelas diperbarui.');
    }

    /** GET /lms/live-sessions/{session}/join */
    public function joinInfo(Request $request, int $session): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->session($session);

        return $this->successResponse($this->sessions->joinInfo($model, $actor));
    }

    /** POST /lms/live-sessions/{session}/join */
    public function join(Request $request, int $session): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->session($session);

        return $this->successResponse($this->sessions->join($model, $actor), 'Kehadiran Anda dicatat.');
    }

    /** GET /lms/live-sessions/{session}/attendance */
    public function attendance(Request $request, int $session): JsonResponse
    {
        $model = $this->session($session);
        $this->authorizeLiveManage($model->course);

        return $this->successResponse([
            'summary' => $this->sessions->attendanceSummary($model),
            'rows' => $this->sessions->attendanceRows($model),
            'pending' => $this->sessions->pendingAttendanceCount($model->course),
        ]);
    }

    /** POST /lms/live-sessions/{session}/attendance */
    public function markAttendance(Request $request, int $session): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->session($session);
        $this->authorizeLiveManage($model->course);

        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.user_id' => ['required', 'integer'],
            'entries.*.status' => ['required', 'string'],
            'entries.*.minutes_attended' => ['nullable', 'integer', 'min:0'],
            'entries.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->successResponse(
            $this->sessions->markAttendance($model, $data['entries'], $actor),
            'Kehadiran disimpan.',
        );
    }

    /** POST /lms/live-sessions/{session}/recordings */
    public function addRecording(Request $request, int $session): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->session($session);
        $this->authorizeLiveManage($model->course);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'url' => ['required', 'url', 'max:1000'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'published_at' => ['nullable', 'date'],
        ]);

        return $this->createdResponse($this->sessions->addRecording($model, $data, $actor), 'Rekaman ditambahkan.');
    }

    /** DELETE /lms/live-recordings/{recording} */
    public function deleteRecording(Request $request, int $recording): JsonResponse
    {
        $actor = $this->actor($request);

        $model = LmsLiveSessionRecording::query()->find($recording);
        abort_if($model === null, 404, 'Rekaman tidak ditemukan.');

        $this->authorizeLiveManage($model->session->course);

        $this->sessions->deleteRecording($model, $actor);

        return $this->successResponse(null, 'Rekaman dihapus.');
    }

    /** POST /lms/live-recordings/{recording}/watch */
    public function watchRecording(Request $request, int $recording): JsonResponse
    {
        $actor = $this->actor($request);

        $model = LmsLiveSessionRecording::query()->find($recording);
        abort_if($model === null, 404, 'Rekaman tidak ditemukan.');

        $data = $request->validate(['percent' => ['required', 'integer', 'min:0', 'max:100']]);

        return $this->successResponse(
            $this->sessions->recordWatch($model, $actor, (int) $data['percent']),
            'Tontonan tercatat.',
        );
    }

    private function course(int $id): LmsCourse
    {
        $course = LmsCourse::query()->find($id);
        abort_if($course === null, 404, 'Course tidak ditemukan.');

        return $course;
    }

    private function session(int $id): LmsLiveSession
    {
        $session = LmsLiveSession::query()->with(['course', 'recordings'])->find($id);
        abort_if($session === null, 404, 'Kelas langsung tidak ditemukan.');

        return $session;
    }

    private function authorizeLiveManage(LmsCourse $course): void
    {
        $actor = $this->actor(request());

        $this->authorizeLms(
            $actor->can('lms.live.manage') && $this->access->canManage($course, $actor),
            'Anda tidak berhak mengelola kelas langsung course ini.',
        );
    }
}
