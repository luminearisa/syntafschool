<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Enums\ActorRole;
use App\Modules\LMS\Domain\Enums\LearningEventType;
use App\Modules\LMS\Domain\Enums\ScormVersion;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsScormAttempt;
use App\Modules\LMS\Models\LmsScormSco;
use App\Modules\LMS\Models\LmsScormTrackingEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pelacakan SCORM (§12).
 *
 * Arsitektur yang ditegakkan:
 * - **Attempt unik** per (siswa, SCO) — klik ganda tidak membuat percobaan baru.
 * - **Idempotent**: setiap elemen yang dilaporkan punya kunci unik, sehingga
 *   commit berulang atau kiriman ulang setelah koneksi putus tidak menggandakan
 *   data.
 * - **Tidak ada mesin kedua**: ketuntasan ditulis lewat `CourseCompletionService`
 *   dan nilai lewat `ActivityGradeService` — sama seperti aktivitas lain.
 * - CMI mutakhir disimpan agar siswa dapat **melanjutkan** dari posisi terakhir.
 */
class ScormTrackingService
{
    /** Elemen CMI yang diterima (sisanya diabaikan, bukan disimpan diam-diam). */
    private const ALLOWED_PREFIXES = ['cmi.', 'adl.'];

    private const ALLOWED_ELEMENTS = [
        'cmi.core.lesson_status', 'cmi.core.lesson_location', 'cmi.core.score.raw',
        'cmi.core.score.min', 'cmi.core.score.max', 'cmi.core.session_time',
        'cmi.core.total_time', 'cmi.suspend_data', 'cmi.core.entry',
        'cmi.completion_status', 'cmi.success_status', 'cmi.location',
        'cmi.score.raw', 'cmi.score.min', 'cmi.score.max', 'cmi.score.scaled',
        'cmi.session_time', 'cmi.total_time',
    ];

    public function __construct(
        private readonly CourseCompletionService $completions,
        private readonly ActivityGradeService $grades,
        private readonly LearningEventService $events,
    ) {}

    /**
     * Buka/mulai percobaan untuk sebuah SCO.
     *
     * @return array{attempt:LmsScormAttempt,resume:array<string,mixed>,launch_path:string|null}
     */
    public function launch(LmsActivity $activity, LmsEnrollment $enrollment, ?LmsScormSco $sco = null): array
    {
        $config = (array) ($activity->settings ?? []);
        $scoId = $sco?->id ?? ($config['scorm_sco_id'] ?? null);

        if ($scoId === null) {
            $sco = LmsScormSco::query()
                ->where('scorm_package_id', (int) ($config['scorm_package_id'] ?? 0))
                ->orderBy('id')
                ->first();
        } else {
            $sco = LmsScormSco::query()->find((int) $scoId);
        }

        $attempt = LmsScormAttempt::query()->firstOrCreate(
            ['user_id' => $enrollment->user_id, 'sco_id' => $sco?->id],
            [
                'scorm_package_id' => (int) ($config['scorm_package_id'] ?? $sco?->scorm_package_id),
                'course_id' => (int) $activity->course_id,
                'enrollment_id' => (int) $enrollment->id,
                'status' => 'INCOMPLETE',
                'launched_at' => now(),
            ],
        );

        if ($attempt->launched_at === null) {
            $attempt->fill(['launched_at' => now()])->save();
        }

        $this->events->record(
            LearningEventType::SCORM_STARTED,
            $this->actorFor($enrollment, $activity),
            $activity->course()->first(),
            ['source' => 'SCORM', 'scorm_sco_id' => $sco?->id, 'scorm_attempt_id' => (int) $attempt->id],
            $activity->learningItem()->first(),
            $activity,
        );

        return [
            'attempt' => $attempt,
            'resume' => [
                'location' => $attempt->cmi['cmi.core.lesson_location'] ?? ($attempt->cmi['cmi.location'] ?? null),
                'suspend_data' => $attempt->cmi['cmi.suspend_data'] ?? null,
                'entry' => $attempt->isCompleted() ? 'resume' : ($attempt->cmi === null ? 'ab-initio' : 'resume'),
                'total_time_seconds' => (int) $attempt->total_time_seconds,
            ],
            'launch_path' => $sco?->launch_path ?? ($config['launch_path'] ?? null),
        ];
    }

    /**
     * Simpan satu kali commit dari peluncur SCORM (§12: Track Attempt).
     *
     * @param  array<string, mixed>  $elements  mis. ['cmi.core.lesson_status' => 'completed', 'cmi.core.score.raw' => '80']
     * @param  array<string, mixed>  $meta  {idempotency_key, session_time, finished}
     * @return array<string, mixed>
     */
    public function commit(LmsScormAttempt $attempt, array $elements, array $meta = []): array
    {
        $filtered = $this->filter($elements);
        $sessionSeconds = $this->parseTime((string) ($meta['session_time'] ?? ($filtered['cmi.session_time'] ?? $filtered['cmi.core.session_time'] ?? '0')));

        $result = DB::transaction(function () use ($attempt, $filtered, $meta, $sessionSeconds): array {
            $cmi = array_merge((array) $attempt->cmi, $filtered);

            $status = $this->completionStatus($attempt, $filtered);
            $success = $this->successStatus($filtered);
            $version = ScormVersion::tryFrom((string) ($attempt->package?->version?->value ?? ScormVersion::SCORM_12->value)) ?? ScormVersion::SCORM_12;

            $scoreRaw = $this->numeric($filtered, ['cmi.score.raw', 'cmi.core.score.raw']);
            $scoreMin = $this->numeric($filtered, ['cmi.score.min', 'cmi.core.score.min']);
            $scoreMax = $this->numeric($filtered, ['cmi.score.max', 'cmi.core.score.max']);
            $scoreScaled = $this->numeric($filtered, ['cmi.score.scaled']);

            $attempt->fill([
                'cmi' => $cmi,
                'status' => $status,
                'completion_status' => $this->completionVariable($filtered, $version),
                'success_status' => $success,
                'score_raw' => $scoreRaw ?? $attempt->score_raw,
                'score_min' => $scoreMin ?? $attempt->score_min,
                'score_max' => $scoreMax ?? $attempt->score_max,
                'score_scaled' => $scoreScaled ?? $attempt->score_scaled,
                'session_time' => isset($filtered['cmi.session_time']) || isset($filtered['cmi.core.session_time'])
                    ? (string) ($filtered['cmi.session_time'] ?? $filtered['cmi.core.session_time'])
                    : $attempt->session_time,
                'total_time_seconds' => (int) $attempt->total_time_seconds + $sessionSeconds,
                'last_commit_at' => now(),
                'completed_at' => $status === 'COMPLETED' ? ($attempt->completed_at ?? now()) : $attempt->completed_at,
            ])->save();

            $recorded = 0;

            foreach ($filtered as $element => $value) {
                $key = $meta['idempotency_key'] ?? null;
                $elementKey = $key !== null
                    ? $key.':'.$element
                    : 'attempt:'.$attempt->id.':'.$element.':'.md5((string) $value);

                try {
                    LmsScormTrackingEvent::query()->create([
                        'attempt_id' => $attempt->id,
                        'element' => $element,
                        'value' => mb_strimwidth((string) $value, 0, 255, '…'),
                        'idempotency_key' => $elementKey,
                        'occurred_at' => now(),
                    ]);

                    $recorded++;
                } catch (Throwable) {
                    // Elemen yang sama sudah tercatat → idempotent, lanjutkan.
                }
            }

            return ['recorded' => $recorded];
        });

        $effects = $this->applyToLearning($attempt->fresh());

        return [
            'attempt' => $this->payload($attempt->fresh()),
            'events_recorded' => $result['recorded'],
            'effects' => $effects,
        ];
    }

    /**
     * Aktor untuk peristiwa belajar SCORM.
     *
     * Peristiwa ditulis atas nama siswa pemilik percobaan — bukan peluncur —
     * supaya riwayat belajar tidak pernah salah orang bila peluncur dilayani
     * pihak ketiga.
     */
    private function actorFor(LmsEnrollment $enrollment, LmsActivity $activity): LmsActor
    {
        return new LmsActor(
            id: (int) $enrollment->user_id,
            name: 'Siswa #'.$enrollment->user_id,
            email: null,
            avatarPath: null,
            primaryRole: ActorRole::STUDENT,
            roles: [ActorRole::STUDENT->value],
            permissions: [],
            studentId: $enrollment->student_id !== null ? (int) $enrollment->student_id : null,
        );
    }

    /** @return array<string, mixed> */
    public function payload(LmsScormAttempt $attempt): array
    {
        $normalized = $attempt->normalizedScore();

        return [
            'id' => (int) $attempt->id,
            'sco_id' => $attempt->sco_id !== null ? (int) $attempt->sco_id : null,
            'course_id' => $attempt->course_id !== null ? (int) $attempt->course_id : null,
            'status' => $attempt->status,
            'completion_status' => $attempt->completion_status,
            'success_status' => $attempt->success_status,
            'score' => [
                'raw' => $attempt->score_raw !== null ? (float) $attempt->score_raw : null,
                'min' => $attempt->score_min !== null ? (float) $attempt->score_min : null,
                'max' => $attempt->score_max !== null ? (float) $attempt->score_max : null,
                'normalized' => $normalized,
            ],
            'total_time_seconds' => (int) $attempt->total_time_seconds,
            'completed' => $attempt->isCompleted(),
            'launched_at' => $attempt->launched_at?->toIso8601String(),
            'last_commit_at' => $attempt->last_commit_at?->toIso8601String(),
        ];
    }

    /** Ringkasan percobaan seorang siswa pada satu aktivitas SCO. */
    public function summaryFor(LmsActivity $activity, int $userId): array
    {
        $config = (array) ($activity->settings ?? []);

        $attempt = LmsScormAttempt::query()
            ->where('course_id', $activity->course_id)
            ->where('user_id', $userId)
            ->when(isset($config['scorm_sco_id']), fn ($query) => $query->where('sco_id', (int) $config['scorm_sco_id']))
            ->first();

        return [
            'attempt' => $attempt !== null ? $this->payload($attempt) : null,
            'events' => $attempt !== null
                ? (int) LmsScormTrackingEvent::query()->where('attempt_id', $attempt->id)->count()
                : 0,
        ];
    }

    /**
     * Teruskan capaian SCORM ke mesin LMS yang sudah ada (§12): ketuntasan,
     * nilai aktivitas, lalu progres — tanpa engine baru.
     *
     * @return array<string, mixed>
     */
    private function applyToLearning(LmsScormAttempt $attempt): array
    {
        if ($attempt->enrollment_id === null || $attempt->course_id === null) {
            return ['applied' => false, 'reason' => 'attempt_without_enrollment'];
        }

        $enrollment = LmsEnrollment::query()->find($attempt->enrollment_id);

        if ($enrollment === null) {
            return ['applied' => false, 'reason' => 'enrollment_missing'];
        }

        $activity = LmsActivity::query()
            ->where('course_id', $attempt->course_id)
            ->where('settings->scorm_sco_id', (int) $attempt->sco_id)
            ->first();

        if ($activity === null) {
            return ['applied' => false, 'reason' => 'activity_missing'];
        }

        $score = $attempt->normalizedScore();
        $threshold = $activity->completion_min_grade !== null ? (float) $activity->completion_min_grade : null;

        $passed = match (true) {
            $attempt->success_status === 'passed' => true,
            $attempt->success_status === 'failed' => false,
            $score !== null && $threshold !== null => $score >= $threshold,
            $attempt->isCompleted() => true,
            default => false,
        };

        if ($score !== null) {
            $this->grades->record($activity, (int) $attempt->user_id, [
                'grade' => $score,
                'maximum_grade' => 100,
                'passed' => $passed,
                'metadata' => [
                    'source' => 'SCORM',
                    'scorm_attempt_id' => (int) $attempt->id,
                    'total_time_seconds' => (int) $attempt->total_time_seconds,
                ],
            ]);
        }

        $completed = false;

        if ($passed || $attempt->isCompleted()) {
            $item = $activity->learningItem()->first();

            if ($item !== null) {
                $this->completions->markComplete($enrollment, $item, $score, 'scorm', (int) $attempt->user_id);
                $completed = true;
            }
        }

        $this->events->record(
            LearningEventType::SCORM_COMPLETED,
            $this->actorFor($enrollment, $activity),
            $activity->course()->first(),
            [
                'source' => 'SCORM',
                'score' => $score,
                'completed' => $completed,
                'total_time_seconds' => (int) $attempt->total_time_seconds,
            ],
            $activity->learningItem()->first(),
            $activity,
        );

        return ['applied' => true, 'grade' => $score, 'passed' => $passed, 'completed' => $completed];
    }

    /**
     * Terima hanya elemen yang dikenal (§12, §29).
     *
     * @param  array<string, mixed>  $elements
     * @return array<string, string>
     */
    private function filter(array $elements): array
    {
        $result = [];

        foreach ($elements as $element => $value) {
            $name = trim((string) $element);

            if ($name === '' || strlen($name) > 120) {
                continue;
            }

            $allowed = in_array($name, self::ALLOWED_ELEMENTS, true);

            foreach (self::ALLOWED_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $allowed = true;
                }
            }

            if (! $allowed || is_array($value)) {
                continue;
            }

            $result[$name] = mb_strimwidth((string) $value, 0, 4000, '…');
        }

        return $result;
    }

    /** @param array<string, string> $elements */
    private function completionStatus(LmsScormAttempt $attempt, array $elements): string
    {
        $status = $this->completionVariable($elements, ScormVersion::SCORM_12);

        if ($status === 'completed' || $status === 'passed' || $status === 'failed') {
            return $status === 'completed' ? 'COMPLETED' : ($status === 'passed' ? 'COMPLETED' : 'COMPLETED');
        }

        if ($status === 'incomplete' || $status === 'browsed' || $status === 'not attempted') {
            return 'INCOMPLETE';
        }

        if (($elements['cmi.success_status'] ?? null) === 'passed') {
            return 'COMPLETED';
        }

        return $attempt->status ?? 'INCOMPLETE';
    }

    /** @param array<string, string> $elements */
    private function completionVariable(array $elements, ScormVersion $version): ?string
    {
        $element = $version->elements()['completion'];

        return $elements[$element] ?? $elements['cmi.core.lesson_status'] ?? $elements['cmi.completion_status'] ?? null;
    }

    /** @param array<string, string> $elements */
    private function successStatus(array $elements): ?string
    {
        if (isset($elements['cmi.success_status'])) {
            return $elements['cmi.success_status'];
        }

        return match ($elements['cmi.core.lesson_status'] ?? null) {
            'passed' => 'passed',
            'failed' => 'failed',
            default => null,
        };
    }

    /** @param list<string> $keys */
    private function numeric(array $elements, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($elements[$key]) && is_numeric($elements[$key])) {
                return (float) $elements[$key];
            }
        }

        return null;
    }

    /** Bentuk waktu SCORM (`PT1H2M3S` atau `00:12:03`) → detik. */
    private function parseTime(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        if (preg_match('/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:([\d.]+)S)?$/', $value, $matches) === 1) {
            $days = (int) ($matches[1] ?? 0);
            $hours = (int) ($matches[2] ?? 0);
            $minutes = (int) ($matches[3] ?? 0);
            $seconds = (int) round((float) ($matches[4] ?? 0));

            return $days * 86400 + $hours * 3600 + $minutes * 60 + $seconds;
        }

        if (preg_match('/^(\d+):(\d{1,2}):(\d{1,2})(?:\.\d+)?$/', $value, $matches) === 1) {
            return ((int) $matches[1]) * 3600 + ((int) $matches[2]) * 60 + (int) $matches[3];
        }

        return is_numeric($value) ? (int) $value : 0;
    }
}
