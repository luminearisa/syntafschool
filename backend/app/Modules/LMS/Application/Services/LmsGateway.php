<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Contracts\AcademicActivityFeedInterface;
use App\Modules\LMS\Domain\Contracts\AcademicCourseProviderInterface;
use App\Modules\LMS\Domain\Contracts\AcademicGradeProviderInterface;
use App\Modules\LMS\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\LMS\Domain\Contracts\CalendarProviderInterface;
use App\Modules\LMS\Domain\Contracts\ClassProviderInterface;
use App\Modules\LMS\Domain\Contracts\EnrollmentProviderInterface;
use App\Modules\LMS\Domain\Contracts\FileStorageProviderInterface;
use App\Modules\LMS\Domain\Contracts\FinancialEligibilityProviderInterface;
use App\Modules\LMS\Domain\Contracts\GradeProviderInterface;
use App\Modules\LMS\Domain\Contracts\InteractiveContentProviderInterface;
use App\Modules\LMS\Domain\Contracts\NotificationProviderInterface;
use App\Modules\LMS\Domain\Contracts\ParentProviderInterface;
use App\Modules\LMS\Domain\Contracts\StudentProviderInterface;
use App\Modules\LMS\Domain\Contracts\TeacherProviderInterface;
use App\Modules\LMS\Domain\Contracts\UserProviderInterface;
use App\Modules\LMS\Domain\Enums\FinancialEligibilityStatus;
use App\Modules\LMS\Domain\Enums\NotificationEvent;

/**
 * Satu gerbang integrasi LMS ↔ ERP/SIAKAD (§4).
 *
 * Seluruh service hanya berbicara pada gerbang ini, sehingga tidak ada satu pun
 * `if (standalone)` di dalam business logic: mode ditentukan sekali saat
 * container menyusun provider (`LmsServiceProvider`).
 *
 * Gerbang ini juga menegakkan kebijakan lintas modul yang memang milik LMS —
 * mis. kebijakan pembatasan keuangan (§8) dan pemberitahuan (§10).
 */
class LmsGateway
{
    public function __construct(
        private readonly UserProviderInterface $users,
        private readonly StudentProviderInterface $students,
        private readonly TeacherProviderInterface $teachers,
        private readonly ClassProviderInterface $classes,
        private readonly AcademicCourseProviderInterface $academicCourses,
        private readonly AcademicActivityFeedInterface $academicActivities,
        private readonly AcademicGradeProviderInterface $academicGrades,
        private readonly GradeProviderInterface $grades,
        private readonly ParentProviderInterface $parents,
        private readonly EnrollmentProviderInterface $enrollments,
        private readonly NotificationProviderInterface $notifications,
        private readonly CalendarProviderInterface $calendar,
        private readonly FileStorageProviderInterface $files,
        private readonly AttendanceProviderInterface $attendance,
        private readonly FinancialEligibilityProviderInterface $finance,
        private readonly InteractiveContentProviderInterface $interactiveContent,
    ) {}

    /** `integrated` (default produksi) atau `standalone`. */
    public function mode(): string
    {
        return config('lms.standalone', false) ? 'standalone' : 'integrated';
    }

    public function users(): UserProviderInterface
    {
        return $this->users;
    }

    public function students(): StudentProviderInterface
    {
        return $this->students;
    }

    public function teachers(): TeacherProviderInterface
    {
        return $this->teachers;
    }

    public function classes(): ClassProviderInterface
    {
        return $this->classes;
    }

    public function academicCourses(): AcademicCourseProviderInterface
    {
        return $this->academicCourses;
    }

    public function academicActivities(): AcademicActivityFeedInterface
    {
        return $this->academicActivities;
    }

    public function academicGrades(): AcademicGradeProviderInterface
    {
        return $this->academicGrades;
    }

    public function grades(): GradeProviderInterface
    {
        return $this->grades;
    }

    public function parents(): ParentProviderInterface
    {
        return $this->parents;
    }

    public function enrollments(): EnrollmentProviderInterface
    {
        return $this->enrollments;
    }

    public function notifications(): NotificationProviderInterface
    {
        return $this->notifications;
    }

    public function calendar(): CalendarProviderInterface
    {
        return $this->calendar;
    }

    public function files(): FileStorageProviderInterface
    {
        return $this->files;
    }

    public function attendance(): AttendanceProviderInterface
    {
        return $this->attendance;
    }

    public function finance(): FinancialEligibilityProviderInterface
    {
        return $this->finance;
    }

    public function interactiveContent(): InteractiveContentProviderInterface
    {
        return $this->interactiveContent;
    }

    /**
     * Ringkasan seluruh provider — dipakai halaman pengaturan, health check,
     * dan audit (tanpa membuka rahasia apa pun).
     *
     * @return array<string, array{provider:string,available:bool}>
     */
    public function describe(): array
    {
        return [
            'users' => $this->describeOne($this->users),
            'students' => $this->describeOne($this->students),
            'teachers' => $this->describeOne($this->teachers),
            'classes' => $this->describeOne($this->classes),
            'academic_courses' => $this->describeOne($this->academicCourses),
            'academic_activities' => $this->describeOne($this->academicActivities),
            'academic_grades' => $this->describeOne($this->academicGrades),
            'grades' => ['provider' => 'lms', 'available' => true],
            'parents' => $this->describeOne($this->parents),
            'enrollments' => $this->describeOne($this->enrollments),
            'notifications' => [
                'provider' => $this->notifications->key(),
                'available' => $this->notifications->isAvailable(),
                'channels' => $this->notifications->supportedChannels(),
            ],
            'calendar' => $this->describeOne($this->calendar),
            'files' => [
                'provider' => $this->files->key(),
                'available' => $this->files->isAvailable(),
                'disk' => $this->files->disk(),
            ],
            'attendance' => $this->describeOne($this->attendance),
            'finance' => $this->describeOne($this->finance),
            'interactive_content' => [
                'provider' => $this->interactiveContent->key(),
                'available' => $this->interactiveContent->isAvailable(),
                'types' => $this->interactiveContent->supportedTypes(),
            ],
        ];
    }

    /**
     * Kirim pemberitahuan lewat gerbang (§10) — satu tempat untuk seluruh
     * peristiwa sehingga kanal/jejaknya seragam.
     *
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $payload
     * @param  list<string>|null  $channels
     * @return array<string, mixed>
     */
    public function notify(array $userIds, NotificationEvent|string $event, array $payload, ?array $channels = null, ?string $idempotencyKey = null): array
    {
        $key = $event instanceof NotificationEvent ? $event->value : $event;

        return $this->notifications->send($userIds, $key, $payload, $channels, $idempotencyKey);
    }

    /**
     * Kebijakan pembatasan keuangan (§8).
     *
     * Default produksi: belajar tetap berjalan (`allow_learning_when_restricted`
     * = true). Bila sekolah mematikannya, hanya kemampuan yang tercantum pada
     * `restricted_capabilities` yang ditahan — progress, submission, nilai,
     * sertifikat, dan riwayat belajar tidak pernah dihapus/ditahan.
     *
     * @return array{allowed:bool,status:string,reason:string|null,capability:string,checked_at:string,source:string}
     */
    public function financialDecision(int $studentId, string $capability = 'view'): array
    {
        $base = [
            'allowed' => true,
            'status' => FinancialEligibilityStatus::ELIGIBLE->value,
            'reason' => null,
            'capability' => $capability,
            'checked_at' => now()->toIso8601String(),
            'source' => 'policy',
        ];

        if ((bool) config('lms.finance.enabled', false) === false) {
            return $base;
        }

        $eligibility = $this->finance->eligibilityFor($studentId);
        $alwaysAllowed = (array) config('lms.finance.always_allowed', [
            'view', 'continue', 'submit', 'grade', 'certificate', 'history',
        ]);

        $base['status'] = (string) $eligibility['status'];
        $base['reason'] = $eligibility['reason'];
        $base['outstanding_amount'] = (float) $eligibility['outstanding_amount'];
        $base['overdue_count'] = (int) $eligibility['overdue_count'];
        $base['source'] = (string) $eligibility['source'];

        $status = FinancialEligibilityStatus::tryFrom((string) $eligibility['status']);

        if ($status === null || ! $status->isBlocking()) {
            return $base;
        }

        if (in_array($capability, $alwaysAllowed, true)) {
            return $base;
        }

        $restricted = (array) config('lms.finance.restricted_capabilities', ['enroll_new_course']);

        if ((bool) config('lms.finance.allow_learning_when_restricted', true) === true) {
            return $base;
        }

        if (! in_array($capability, $restricted, true)) {
            return $base;
        }

        $base['allowed'] = false;
        $base['reason'] = $eligibility['reason'] ?? 'Kelayakan belajar belum terpenuhi.';

        return $base;
    }

    /** @return array{provider:string,available:bool} */
    private function describeOne(object $provider): array
    {
        $available = method_exists($provider, 'isAvailable') ? (bool) $provider->isAvailable() : true;
        $key = method_exists($provider, 'key') ? (string) $provider->key() : $provider::class;

        return ['provider' => $key, 'available' => $available];
    }
}
