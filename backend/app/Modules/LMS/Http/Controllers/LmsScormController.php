<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\ScormPackageService;
use App\Modules\LMS\Application\Services\ScormTrackingService;
use App\Modules\LMS\Domain\Enums\EnrollmentStatus;
use App\Modules\LMS\Domain\Enums\ScormVersion;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseSection;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsScormAttempt;
use App\Modules\LMS\Models\LmsScormPackage;
use App\Modules\LMS\Models\LmsScormSco;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Interoperabilitas konten SCORM 1.2 / 2004 (§12).
 *
 * Guru mengimpor paket & memasangnya sebagai aktivitas; peluncur melaporkan
 * elemen CMI ke endpoint `commit` yang idempotent (aman dari kiriman ulang).
 */
class LmsScormController extends LmsController
{
    public function __construct(
        private readonly ScormPackageService $packages,
        private readonly ScormTrackingService $tracking,
        private readonly CourseAccessService $access,
    ) {}

    /** GET /lms/scorm/packages */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeScormManage();

        return $this->successResponse([
            'packages' => $this->packages->packages((int) $this->perPage($request, 25)),
            'supported_versions' => [ScormVersion::SCORM_12->label(), ScormVersion::SCORM_2004->label()],
        ]);
    }

    /** POST /lms/scorm/packages */
    public function import(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeScormManage();

        $request->validate([
            'file' => ['required', 'file', 'max:102400'],
            'title' => ['nullable', 'string', 'max:190'],
        ]);

        $result = $this->packages->import($request->file('file'), $actor, $request->input('title'));

        if (! $result['validation']['valid']) {
            return $this->validationErrorResponse(
                ['file' => $result['validation']['errors']],
                'Paket SCORM tidak valid.',
            );
        }

        return $this->createdResponse([
            'package' => $this->packages->payload($result['package']),
            'validation' => $result['validation'],
        ], 'Paket SCORM diimpor.');
    }

    /** GET /lms/scorm/packages/{package} */
    public function show(Request $request, int $package): JsonResponse
    {
        $this->authorizeScormManage();

        return $this->successResponse($this->packages->payload($this->package($package)));
    }

    /** POST /lms/scorm/packages/{package}/activity */
    public function createActivity(Request $request, int $package): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeScormManage();

        $data = $request->validate([
            'course_id' => ['required', 'integer'],
            'section_id' => ['required', 'integer'],
            'sco_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'is_required' => ['nullable', 'boolean'],
            'is_graded' => ['nullable', 'boolean'],
            'completion_rule' => ['nullable', 'string'],
            'completion_min_grade' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $course = LmsCourse::query()->findOrFail((int) $data['course_id']);
        $this->authorizeLms($this->access->canManage($course, $actor), 'Anda tidak berhak memasang aktivitas pada course ini.');

        $section = LmsCourseSection::query()->where('course_id', $course->id)->findOrFail((int) $data['section_id']);

        $sco = isset($data['sco_id'])
            ? LmsScormSco::query()->where('scorm_package_id', $package)->findOrFail((int) $data['sco_id'])
            : null;

        $activity = $this->packages->createActivity($package = $this->package($package), $course, $section, $actor, $sco, $data);

        return $this->createdResponse([
            'activity_id' => (int) $activity->id,
            'learning_item_id' => (int) $activity->learning_item_id,
            'package' => $this->packages->payload($package, false),
        ], 'Aktivitas SCORM dipasang.');
    }

    /** POST /lms/scorm/activities/{activity}/launch */
    public function launch(Request $request, int $activity): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->activity($activity);
        $enrollment = $this->studentEnrollment($model);

        $data = $request->validate(['sco_id' => ['nullable', 'integer']]);

        $sco = isset($data['sco_id'])
            ? LmsScormSco::query()->find((int) $data['sco_id'])
            : null;

        $result = $this->tracking->launch($model, $enrollment, $sco);

        return $this->successResponse([
            'attempt' => $this->tracking->payload($result['attempt']),
            'resume' => $result['resume'],
            'launch_path' => $result['launch_path'],
            'version' => $model->settings['scorm_version'] ?? null,
        ]);
    }

    /** POST /lms/scorm/attempts/{attempt}/commit */
    public function commit(Request $request, int $attempt): JsonResponse
    {
        $actor = $this->actor($request);

        $model = LmsScormAttempt::query()->find($attempt);
        abort_if($model === null, 404, 'Percobaan SCORM tidak ditemukan.');

        if ((int) $model->user_id !== (int) $actor->id && ! $actor->can('lms.manage')) {
            LmsFault::forbidden('Percobaan ini bukan milik Anda.');
        }

        $data = $request->validate([
            'elements' => ['required', 'array'],
            'session_time' => ['nullable', 'string', 'max:30'],
            'idempotency_key' => ['nullable', 'string', 'max:190'],
            'finished' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse(
            $this->tracking->commit($model, $data['elements'], [
                'session_time' => $data['session_time'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'finished' => (bool) ($data['finished'] ?? false),
            ]),
            'Pelacakan tersimpan.',
        );
    }

    /** GET /lms/scorm/activities/{activity}/tracking */
    public function tracking(Request $request, int $activity): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->activity($activity);

        if ($this->access->canManage($model->course, $actor)) {
            $rows = LmsScormAttempt::query()
                ->where('course_id', $model->course_id)
                ->orderByDesc('id')
                ->limit((int) $this->perPage($request, 50))
                ->get()
                ->map(fn (LmsScormAttempt $attempt) => $this->tracking->payload($attempt))
                ->all();

            return $this->successResponse(['attempts' => $rows, 'summary' => $this->attendancelessSummary($model)]);
        }

        return $this->successResponse($this->tracking->summaryFor($model, (int) $actor->id));
    }

    /** @return array<string, mixed> */
    private function attendancelessSummary(LmsActivity $activity): array
    {
        $attempts = LmsScormAttempt::query()->where('course_id', $activity->course_id)->get();

        return [
            'attempts' => $attempts->count(),
            'completed' => $attempts->filter(fn (LmsScormAttempt $attempt) => $attempt->isCompleted())->count(),
            'average_score' => $attempts->avg(fn (LmsScormAttempt $attempt) => $attempt->normalizedScore()) !== null
                ? round((float) $attempts->avg(fn (LmsScormAttempt $attempt) => $attempt->normalizedScore()), 2)
                : null,
            'total_minutes' => (int) round($attempts->sum('total_time_seconds') / 60),
        ];
    }

    private function package(int $id): LmsScormPackage
    {
        $package = LmsScormPackage::query()->find($id);
        abort_if($package === null, 404, 'Paket SCORM tidak ditemukan.');

        return $package;
    }

    private function activity(int $id): LmsActivity
    {
        $activity = LmsActivity::query()->with('course')->find($id);
        abort_if($activity === null, 404, 'Aktivitas tidak ditemukan.');

        return $activity;
    }

    private function studentEnrollment(LmsActivity $activity): LmsEnrollment
    {
        $actor = $this->actor(request());
        $enrollment = $activity->course->enrollmentFor($actor->id);

        if ($enrollment === null || $enrollment->status !== EnrollmentStatus::ACTIVE) {
            LmsFault::forbidden('Anda belum terdaftar sebagai peserta pada course ini.');
        }

        return $enrollment;
    }

    private function authorizeScormManage(): void
    {
        $actor = $this->actor(request());

        $this->authorizeLms($actor->can('lms.scorm.manage') || $actor->can('lms.manage'), 'Anda tidak berhak mengelola paket SCORM.');
    }
}
