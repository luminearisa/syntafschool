<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Contracts\AcademicCourseProviderInterface;
use App\Modules\LMS\Domain\Contracts\StudentProviderInterface;
use App\Modules\LMS\Domain\Contracts\TeacherProviderInterface;
use App\Modules\LMS\Domain\Enums\EnrollmentMethod;
use App\Modules\LMS\Domain\Enums\EnrollmentRole;
use App\Modules\LMS\Domain\Enums\EnrollmentStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsLearner;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pendaftaran peserta & pengajar (§15–§18).
 *
 * Dua jalur yang dijaga terpisah:
 *   • MANUAL/bulk  → guru atau admin memilih peserta (§18)
 *   • ACADEMIC     → SIAKAD yang menentukan, LMS hanya mencerminkan (§17)
 *
 * Pembatalan akademik tidak pernah menghapus riwayat belajar: statusnya
 * dipindahkan ke SUSPENDED sehingga nilai & penyelesaian lama tetap utuh.
 */
class CourseEnrollmentService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly StudentProviderInterface $students,
        private readonly TeacherProviderInterface $teachers,
        private readonly AcademicCourseProviderInterface $academic,
        private readonly CourseProgressService $progress,
    ) {}

    // --------------------------------------------------------------------
    // Pendaftaran
    // --------------------------------------------------------------------

    /**
     * @param  array{user_id?: int|null, student_id?: int|null, teacher_id?: int|null, role?: string, enrollment_method?: string, status?: string, start_at?: mixed, end_at?: mixed}  $data
     */
    public function enroll(LmsCourse $course, array $data, ?LmsActor $actor = null): LmsEnrollment
    {
        $role = EnrollmentRole::from($data['role'] ?? EnrollmentRole::STUDENT->value);
        $method = EnrollmentMethod::from($data['enrollment_method'] ?? EnrollmentMethod::MANUAL->value);
        $userId = isset($data['user_id']) && $data['user_id'] !== null ? (int) $data['user_id'] : null;
        $studentId = isset($data['student_id']) && $data['student_id'] !== null ? (int) $data['student_id'] : null;
        $teacherId = isset($data['teacher_id']) && $data['teacher_id'] !== null ? (int) $data['teacher_id'] : null;

        if ($userId === null && $studentId !== null) {
            $learner = $this->students->find($studentId);

            if ($learner === null) {
                throw ValidationException::withMessages(['student_id' => 'Siswa tidak ditemukan.']);
            }

            $userId = $learner->userId;
        }

        if ($userId === null && $teacherId !== null) {
            $userId = $this->teachers->find($teacherId)?->userId;
        }

        if ($userId === null) {
            throw ValidationException::withMessages([
                'user_id' => 'Peserta ini belum memiliki akun pengguna, sehingga tidak dapat didaftarkan ke LMS.',
            ]);
        }

        if ($course->max_students !== null && $role === EnrollmentRole::STUDENT) {
            $current = $course->enrollments()
                ->where('role', EnrollmentRole::STUDENT->value)
                ->whereIn('status', [EnrollmentStatus::ACTIVE->value, EnrollmentStatus::COMPLETED->value])
                ->count();

            $alreadyEnrolled = $course->enrollments()->where('user_id', $userId)->exists();

            if (! $alreadyEnrolled && $current >= (int) $course->max_students) {
                throw ValidationException::withMessages([
                    'user_id' => 'Kuota peserta course ini sudah penuh ('.$course->max_students.' siswa).',
                ]);
            }
        }

        $enrollment = LmsEnrollment::withTrashed()
            ->where('course_id', $course->id)
            ->where('user_id', $userId)
            ->first();

        $attributes = [
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'role' => $role->value,
            'enrollment_method' => $method->value,
            'status' => $data['status'] ?? EnrollmentStatus::ACTIVE->value,
            'enrolled_at' => now(),
            'start_at' => $data['start_at'] ?? null,
            'end_at' => $data['end_at'] ?? null,
            'suspended_at' => null,
            'enrolled_by' => $actor?->id,
            'metadata' => $data['metadata'] ?? null,
        ];

        if ($enrollment !== null) {
            if ($enrollment->trashed()) {
                $enrollment->restore();
            }

            // Sinkronisasi akademik tidak menurunkan hak yang sudah diberikan manual.
            if ($enrollment->enrollment_method === EnrollmentMethod::MANUAL && $method === EnrollmentMethod::ACADEMIC) {
                $attributes['enrollment_method'] = $enrollment->enrollment_method->value;
            }

            $enrollment->fill($attributes)->save();
        } else {
            $enrollment = LmsEnrollment::create([
                'course_id' => $course->id,
                'user_id' => $userId,
                ...$attributes,
            ]);
        }

        if ($role === EnrollmentRole::STUDENT) {
            $this->progress->recompute($enrollment);
        }

        $this->log('LMS_ENROLLMENT_CREATED', $enrollment, [
            'course_id' => $course->id,
            'user_id' => $userId,
            'method' => $method->value,
            'role' => $role->value,
        ]);

        return $enrollment->fresh();
    }

    /**
     * Pendaftaran massal (§18). Mengembalikan ringkasan agar UI dapat
     * menjelaskan berapa yang masuk dan berapa yang dilewati.
     *
     * @param  list<int>  $userIds
     * @return array<string, mixed>
     */
    public function bulkEnroll(LmsCourse $course, array $userIds, ?LmsActor $actor, string $method = 'MANUAL'): array
    {
        $created = 0;
        $updated = 0;
        $skipped = [];

        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            if ($userId <= 0) {
                continue;
            }

            $exists = $course->enrollments()->where('user_id', $userId)->exists();

            try {
                $this->enroll($course, [
                    'user_id' => $userId,
                    'role' => EnrollmentRole::STUDENT->value,
                    'enrollment_method' => $method,
                ], $actor);

                $exists ? $updated++ : $created++;
            } catch (ValidationException $exception) {
                $skipped[] = ['user_id' => $userId, 'reason' => collect($exception->errors())->flatten()->first()];
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total_enrolled' => $course->enrollments()->where('role', EnrollmentRole::STUDENT->value)->count(),
        ];
    }

    /**
     * Pendaftaran berdasarkan daftar id siswa ERP — dipakai pemilih siswa di UI.
     *
     * @param  list<int>  $studentIds
     * @return array<string, mixed>
     */
    public function enrollStudents(LmsCourse $course, array $studentIds, ?LmsActor $actor, string $method = 'MANUAL'): array
    {
        $created = 0;
        $skipped = [];

        foreach (array_unique(array_map('intval', $studentIds)) as $studentId) {
            $learner = $this->students->find($studentId);

            if ($learner === null || $learner->userId === null) {
                $skipped[] = ['student_id' => $studentId, 'reason' => 'Siswa tidak memiliki akun pengguna.'];

                continue;
            }

            $this->enroll($course, [
                'user_id' => $learner->userId,
                'student_id' => $learner->id,
                'role' => EnrollmentRole::STUDENT->value,
                'enrollment_method' => $method,
            ], $actor);

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'total_enrolled' => $course->enrollments()->count()];
    }

    // --------------------------------------------------------------------
    // Perubahan status
    // --------------------------------------------------------------------

    public function update(LmsEnrollment $enrollment, array $data): LmsEnrollment
    {
        foreach (['role', 'start_at', 'end_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $enrollment->{$field} = $data[$field];
            }
        }

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $this->applyStatus($enrollment, EnrollmentStatus::from($data['status']));
        }

        $enrollment->save();

        return $enrollment->fresh();
    }

    public function suspend(LmsEnrollment $enrollment, ?string $reason = null, ?LmsActor $actor = null): LmsEnrollment
    {
        $this->applyStatus($enrollment, EnrollmentStatus::SUSPENDED);
        $enrollment->metadata = array_merge($enrollment->metadata ?? [], array_filter([
            'suspended_reason' => $reason,
            'suspended_by' => $actor?->id,
            'suspended_at' => now()->toIso8601String(),
        ]));
        $enrollment->save();

        $this->log('LMS_ENROLLMENT_SUSPENDED', $enrollment, ['reason' => $reason]);

        return $enrollment->fresh();
    }

    public function cancel(LmsEnrollment $enrollment, ?string $reason = null, ?LmsActor $actor = null): LmsEnrollment
    {
        $this->applyStatus($enrollment, EnrollmentStatus::CANCELLED);
        $enrollment->metadata = array_merge($enrollment->metadata ?? [], array_filter([
            'cancelled_reason' => $reason,
            'cancelled_by' => $actor?->id,
        ]));
        $enrollment->save();

        $this->log('LMS_ENROLLMENT_CANCELLED', $enrollment, ['reason' => $reason]);

        return $enrollment->fresh();
    }

    public function reactivate(LmsEnrollment $enrollment, ?LmsActor $actor = null): LmsEnrollment
    {
        $this->applyStatus($enrollment, EnrollmentStatus::ACTIVE);
        $enrollment->save();
        $this->progress->recompute($enrollment);

        $this->log('LMS_ENROLLMENT_REACTIVATED', $enrollment, ['actor_id' => $actor?->id]);

        return $enrollment->fresh();
    }

    public function complete(LmsEnrollment $enrollment, ?LmsActor $actor = null): LmsEnrollment
    {
        $this->applyStatus($enrollment, EnrollmentStatus::COMPLETED);
        $enrollment->save();

        $this->log('LMS_ENROLLMENT_COMPLETED', $enrollment, ['actor_id' => $actor?->id]);

        return $enrollment->fresh();
    }

    /** Keluarkan peserta dari course (soft delete; riwayat penyelesaian tetap ada). */
    public function remove(LmsEnrollment $enrollment, ?LmsActor $actor = null): void
    {
        $this->log('LMS_ENROLLMENT_DELETED', $enrollment, ['actor_id' => $actor?->id]);
        $enrollment->delete();
    }

    protected function applyStatus(LmsEnrollment $enrollment, EnrollmentStatus $status): void
    {
        $enrollment->status = $status;
        $enrollment->suspended_at = $status === EnrollmentStatus::SUSPENDED ? now() : null;
        $enrollment->completed_at = $status === EnrollmentStatus::COMPLETED
            ? ($enrollment->completed_at ?? now())
            : null;
    }

    // --------------------------------------------------------------------
    // Sinkronisasi SIAKAD
    // --------------------------------------------------------------------

    /**
     * Membuat/menyegarkan enrollment dari data akademik (§6, §17):
     *
     *   rombel + tahun ajaran → siswa aktif → akun siswa → enrollment LMS
     *
     * Siswa yang sudah tidak berada di rombel dipindahkan ke SUSPENDED, bukan
     * dihapus, supaya riwayat belajar tidak hilang.
     *
     * @return array<string, mixed>
     */
    public function syncAcademicEnrollments(LmsCourse $course, ?LmsActor $actor = null, ?int $previousClassId = null): array
    {
        if ($course->class_id === null) {
            throw ValidationException::withMessages([
                'class_id' => 'Course ini belum ditautkan ke rombel SIAKAD.',
            ]);
        }

        $academicYearId = $course->academic_year_id !== null ? (int) $course->academic_year_id : null;
        $learners = $this->students->inClass((int) $course->class_id, $academicYearId);

        $created = 0;
        $updated = 0;
        $skipped = [];

        DB::transaction(function () use ($course, $learners, $actor, $academicYearId, &$created, &$updated, &$skipped): void {
            $userIds = [];

            foreach ($learners as $learner) {
                if (! $learner->hasAccount()) {
                    $skipped[] = ['student_id' => $learner->id, 'name' => $learner->name, 'reason' => 'Belum memiliki akun pengguna.'];

                    continue;
                }

                $userIds[] = (int) $learner->userId;
                $exists = $course->enrollments()->where('user_id', $learner->userId)->exists();

                $this->enroll($course, [
                    'user_id' => $learner->userId,
                    'student_id' => $learner->id,
                    'role' => EnrollmentRole::STUDENT->value,
                    'enrollment_method' => EnrollmentMethod::ACADEMIC->value,
                    'metadata' => [
                        'source' => 'siakad',
                        'class_id' => (int) $course->class_id,
                        'academic_year_id' => $academicYearId,
                        'student_name' => $learner->name,
                    ],
                ], $actor);

                $exists ? $updated++ : $created++;
            }

            // Peserta yang hilang dari rombel → SUSPENDED (bukan dihapus).
            $course->enrollments()
                ->where('enrollment_method', EnrollmentMethod::ACADEMIC->value)
                ->where('role', EnrollmentRole::STUDENT->value)
                ->when($userIds !== [], fn (Builder $q) => $q->whereNotIn('user_id', $userIds))
                ->get()
                ->each(function (LmsEnrollment $enrollment) use ($actor): void {
                    $this->suspend($enrollment, 'Tidak lagi terdaftar pada rombel sumber SIAKAD.', $actor);
                });
        });

        // Pengajar diambil dari `class_subjects` bila belum ditetapkan manual (§6).
        if ($course->academic_course_id !== null) {
            $teacher = $this->academic->teacherFor((int) $course->class_id, (int) $course->academic_course_id, $academicYearId);

            if ($teacher !== null) {
                // Penetapan pengajar dicatat lebih dulu — relasi ini sah meski
                // pegawai belum punya akun login. Status pengajar utama yang
                // sudah ditetapkan manual tidak diturunkan (hanya diisi bila kosong).
                $key = $teacher->userId !== null
                    ? ['user_id' => $teacher->userId]
                    : ['employee_id' => $teacher->id];

                $existing = $course->teachers()->where($key)->first();

                $course->teachers()->updateOrCreate($key, [
                    'user_id' => $teacher->userId,
                    'employee_id' => $teacher->id,
                    'name' => $teacher->name,
                    'role' => EnrollmentRole::TEACHER->value,
                    'is_primary' => $existing?->is_primary ?? ($course->teachers()->count() === 0),
                ]);

                // Enrollment hanya mungkin bila pegawai memiliki akun LMS.
                if ($teacher->userId !== null) {
                    $this->enroll($course, [
                        'user_id' => $teacher->userId,
                        'teacher_id' => $teacher->id,
                        'role' => EnrollmentRole::TEACHER->value,
                        'enrollment_method' => EnrollmentMethod::ACADEMIC->value,
                    ], $actor);
                }
            }
        }

        $this->log('LMS_ENROLLMENT_SYNCED', $course, [
            'class_id' => (int) $course->class_id,
            'previous_class_id' => $previousClassId,
            'created' => $created,
            'updated' => $updated,
            'skipped' => count($skipped),
        ]);

        return [
            'class_id' => (int) $course->class_id,
            'learners_found' => count($learners),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total_enrolled' => $course->enrollments()->where('role', EnrollmentRole::STUDENT->value)->count(),
        ];
    }

    // --------------------------------------------------------------------
    // Daftar peserta
    // --------------------------------------------------------------------

    /** @return HasMany<LmsEnrollment, LmsCourse> */
    public function roster(LmsCourse $course, array $filters = []): HasMany
    {
        return $course->enrollments()
            ->with(['progress:id,enrollment_id,progress_percent,completed_items,required_items,next_item_id,computed_at'])
            ->when(($filters['role'] ?? null) !== null && $filters['role'] !== '', fn (Builder $q) => $q->where('role', $filters['role']))
            ->when(($filters['status'] ?? null) !== null && $filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(($filters['method'] ?? null) !== null && $filters['method'] !== '', fn (Builder $q) => $q->where('enrollment_method', $filters['method']))
            ->when(($filters['search'] ?? null) !== null && $filters['search'] !== '', function (Builder $q) use ($filters): void {
                $term = trim((string) $filters['search']);

                $q->whereHasMorph('user', ['*'], function (Builder $inner) use ($term): void {
                    $inner->where('name', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('enrolled_at');
    }

    /** @return array<string, mixed> */
    public function summary(LmsCourse $course): array
    {
        $enrollments = $course->enrollments();

        return [
            'total' => (int) (clone $enrollments)->count(),
            'students' => (int) (clone $enrollments)->where('role', EnrollmentRole::STUDENT->value)->count(),
            'teachers' => (int) (clone $enrollments)->whereIn('role', [EnrollmentRole::TEACHER->value, EnrollmentRole::ASSISTANT->value])->count(),
            'active' => (int) (clone $enrollments)->where('status', EnrollmentStatus::ACTIVE->value)->count(),
            'pending' => (int) (clone $enrollments)->where('status', EnrollmentStatus::PENDING->value)->count(),
            'suspended' => (int) (clone $enrollments)->where('status', EnrollmentStatus::SUSPENDED->value)->count(),
            'completed' => (int) (clone $enrollments)->where('status', EnrollmentStatus::COMPLETED->value)->count(),
            'average_progress' => round((float) ((clone $enrollments)->avg('progress_percent') ?? 0), 2),
        ];
    }

    /**
     * Siswa yang belum terdaftar pada course — bahan dialog "Tambah Siswa" (§18).
     *
     * @return list<array<string, mixed>>
     */
    public function availableStudents(LmsCourse $course, string $term, ?int $limit = 25): array
    {
        $candidates = $term !== '' || $course->class_id !== null
            ? ($course->class_id !== null
                ? $this->students->inClass((int) $course->class_id, $course->academic_year_id !== null ? (int) $course->academic_year_id : null)
                : $this->students->search($term))
            : $this->students->search($term, null, $limit ?? 25);

        $enrolledUserIds = $course->enrollments()->pluck('user_id')->all();

        $result = [];

        foreach ($candidates as $learner) {
            /** @var LmsLearner $learner */
            if ($learner->userId !== null && in_array($learner->userId, $enrolledUserIds, true)) {
                continue;
            }

            if ($term !== '' && ! str_contains(mb_strtolower($learner->name), mb_strtolower($term))) {
                continue;
            }

            $result[] = $learner->toArray() + [
                'already_enrolled' => false,
                'has_account' => $learner->hasAccount(),
            ];
        }

        return array_slice($result, 0, $limit ?? 25);
    }

    protected function log(string $action, mixed $subject = null, array $properties = []): void
    {
        $this->audit($action, $subject instanceof \Illuminate\Database\Eloquent\Model ? $subject : null, $properties);
    }
}
