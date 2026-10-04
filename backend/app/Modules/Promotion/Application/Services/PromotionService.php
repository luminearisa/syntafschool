<?php

namespace App\Modules\Promotion\Application\Services;

use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentHistory;
use App\Models\StudentStatus;
use App\Modules\Promotion\Models\PromotionApproval;
use App\Modules\Promotion\Models\PromotionDecision;
use App\Modules\Promotion\Models\PromotionRule;
use App\Services\Assessment\GradingService;
use App\Services\Attendance\AttendanceRecapService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mesin kenaikan kelas (A10).
 *
 * Tanggung jawab utama:
 *  - Menilai satu kelas terhadap `promotion_rules` dan menghasilkan pratinjau
 *    per siswa tanpa menyimpan apa pun (`evaluate`).
 *  - Menyimpan keputusan massal beserta snapshot pada saat keputusan diambil
 *    (`bulk`); snapshot tidak pernah dihitung ulang demi auditabilitas.
 *  - Menerapkan keputusan secara idempoten & dapat dibalik (`finalize`/`rollback`).
 *  - Mengelola alur persetujuan berjenjang (`submit`/`approve`/`reopen`).
 */
class PromotionService
{
    public function __construct(
        private readonly GradingService $grading,
        private readonly AttendanceRecapService $attendance,
    ) {}

    // -----------------------------------------------------------------
    // Aturan
    // -----------------------------------------------------------------

    /**
     * Ambil aturan yang berlaku untuk sebuah kelas.
     *
     * Aturan khusus jenjang (`education_level_id` terisi) mengalahkan aturan
     * umum tahun ajaran; bila tidak ada sama sekali, kembalikan null.
     */
    public function resolveRule(SchoolClass $class): ?PromotionRule
    {
        return PromotionRule::query()
            ->where('academic_year_id', $class->academic_year_id)
            ->where('is_active', true)
            ->where(function ($query) use ($class): void {
                $query->whereNull('education_level_id');
                if ($class->education_level_id !== null) {
                    $query->orWhere('education_level_id', $class->education_level_id);
                }
            })
            // Baris dengan jenjang spesifik (IS NULL = 0) diurut lebih dulu.
            ->orderByRaw('education_level_id IS NULL')
            ->orderByDesc('id')
            ->first();
    }

    public function requireRule(SchoolClass $class): PromotionRule
    {
        $rule = $this->resolveRule($class);

        if ($rule === null) {
            throw ValidationException::withMessages([
                'class_id' => 'Aturan kenaikan kelas untuk kelas ini belum dikonfigurasi.',
            ]);
        }

        return $rule;
    }

    // -----------------------------------------------------------------
    // Evaluasi (pratinjau, tanpa menyimpan)
    // -----------------------------------------------------------------

    /**
     * Pratinjau keputusan untuk seluruh siswa aktif satu kelas.
     *
     * @return array<string, mixed>
     */
    public function evaluate(SchoolClass $class, ?int $semesterId = null): array
    {
        $rule = $this->requireRule($class);
        $students = $this->activeStudents($class);

        $rows = $students->map(function (Student $student) use ($class, $rule, $semesterId): array {
            $snapshot = $this->buildSnapshot($class, $student, $rule, $semesterId);

            $existing = PromotionDecision::query()
                ->where('student_id', $student->id)
                ->where('academic_year_id', $class->academic_year_id)
                ->first();

            return [
                'student' => [
                    'id' => (int) $student->id,
                    'nis' => $student->nis,
                    'full_name' => $student->full_name,
                ],
                'snapshot' => $snapshot,
                'suggested_decision' => $snapshot['passed_all']
                    ? PromotionDecision::DECISION_NAIK
                    : PromotionDecision::DECISION_TINGGAL,
                'existing_decision_id' => $existing?->id,
                'existing_status' => $existing?->status,
            ];
        })->values();

        return [
            'class' => [
                'id' => (int) $class->id,
                'name' => $class->name,
                'code' => $class->code,
                'academic_year_id' => (int) $class->academic_year_id,
                'education_level_id' => $class->education_level_id === null ? null : (int) $class->education_level_id,
            ],
            'academic_year_id' => (int) $class->academic_year_id,
            'semester_id' => $semesterId,
            'rule' => $rule,
            'student_count' => $rows->count(),
            'naik_count' => $rows->where('suggested_decision', PromotionDecision::DECISION_NAIK)->count(),
            'tinggal_count' => $rows->where('suggested_decision', PromotionDecision::DECISION_TINGGAL)->count(),
            'students' => $rows->all(),
        ];
    }

    /**
     * Susun snapshot kriteria seorang siswa terhadap aturan yang berlaku.
     *
     * Potret ini yang disimpan pada keputusan; sesudah disimpan tidak dihitung ulang.
     *
     * @return array<string, mixed>
     */
    public function buildSnapshot(SchoolClass $class, Student $student, PromotionRule $rule, ?int $semesterId = null): array
    {
        $subjects = $this->classSubjects($class);
        $semesters = $this->semestersFor($class, $semesterId);
        $minPassing = (float) $rule->min_passing_grade;

        $subjectRows = [];
        $scores = [];

        foreach ($subjects as $subject) {
            $finalScore = null;

            foreach ($semesters as $semester) {
                $gradebook = $this->grading->build($class, $subject, $semester);
                $row = collect($gradebook['students'] ?? [])->firstWhere('student_id', $student->id);

                if ($row !== null && ($row['final_score'] ?? null) !== null) {
                    // Semester terakhir yang punya nilai menjadi nilai akhir siswa.
                    $finalScore = round((float) $row['final_score'], 2);
                }
            }

            $passed = $finalScore !== null && $finalScore >= $minPassing;
            if ($finalScore !== null) {
                $scores[] = $finalScore;
            }

            $subjectRows[] = [
                'subject_id' => (int) $subject->id,
                'code' => $subject->code,
                'name' => $subject->name,
                'final_score' => $finalScore,
                'passed' => $passed,
            ];
        }

        $average = $scores === [] ? null : round(array_sum($scores) / count($scores), 2);
        $unpassed = count(array_filter($subjectRows, fn (array $row) => $row['final_score'] !== null && $row['passed'] === false));

        $attendance = $this->attendanceSummary($class, $student, $semesters);
        $violationPoints = $this->violationPoints($student, $class);

        $criteria = [
            'average_score' => $average !== null && $average >= (float) $rule->min_average_score,
            'attendance' => $attendance['percentage'] >= (float) $rule->min_attendance_percent,
            'unpassed_subjects' => $unpassed <= (int) $rule->max_unpassed_subjects,
            'violation_points' => $rule->violation_point_limit === null
                || $violationPoints <= (int) $rule->violation_point_limit,
        ];

        return [
            'captured_at' => now()->toIso8601String(),
            'academic_year_id' => (int) $class->academic_year_id,
            'class_id' => (int) $class->id,
            'rule_id' => (int) $rule->id,
            'rule' => [
                'min_average_score' => (float) $rule->min_average_score,
                'min_attendance_percent' => (float) $rule->min_attendance_percent,
                'max_unpassed_subjects' => (int) $rule->max_unpassed_subjects,
                'min_passing_grade' => (float) $rule->min_passing_grade,
                'violation_point_limit' => $rule->violation_point_limit === null ? null : (int) $rule->violation_point_limit,
            ],
            'average_score' => $average,
            'subject_count' => count($subjectRows),
            'unpassed_subjects' => $unpassed,
            'subjects' => $subjectRows,
            'attendance_percent' => $attendance['percentage'],
            'attendance_present' => $attendance['present'],
            'attendance_basis_days' => $attendance['basis_days'],
            'violation_points' => $violationPoints,
            'criteria' => $criteria,
            'passed_all' => ! in_array(false, $criteria, true),
        ];
    }

    // -----------------------------------------------------------------
    // Penyimpanan keputusan
    // -----------------------------------------------------------------

    /**
     * Simpan keputusan massal satu kelas sebagai draft.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, PromotionDecision>
     */
    public function bulk(SchoolClass $class, array $rows, ?int $userId = null): Collection
    {
        $rule = $this->requireRule($class);

        return DB::transaction(function () use ($class, $rows, $userId, $rule): Collection {
            $saved = collect();

            foreach ($rows as $row) {
                $student = Student::query()->find((int) ($row['student_id'] ?? 0));

                if ($student === null) {
                    throw ValidationException::withMessages([
                        'decisions' => 'Siswa pada daftar keputusan tidak ditemukan.',
                    ]);
                }

                if (! $this->studentInClass($class, (int) $student->id)) {
                    throw ValidationException::withMessages([
                        'decisions' => "Siswa {$student->full_name} bukan anggota aktif kelas ini.",
                    ]);
                }

                $exists = PromotionDecision::withTrashed()
                    ->where('student_id', $student->id)
                    ->where('academic_year_id', $class->academic_year_id)
                    ->exists();

                if ($exists) {
                    throw ValidationException::withMessages([
                        'decisions' => "Keputusan untuk {$student->full_name} pada tahun ajaran ini sudah ada.",
                    ]);
                }

                $snapshot = $this->buildSnapshot($class, $student, $rule, isset($row['semester_id']) ? (int) $row['semester_id'] : null);

                $decision = PromotionDecision::create([
                    'student_id' => $student->id,
                    'school_class_id' => $class->id,
                    'academic_year_id' => $class->academic_year_id,
                    'target_class_id' => $row['target_class_id'] ?? null,
                    'decision' => $row['decision'] ?? null,
                    'snapshot' => $snapshot,
                    'reason' => $row['reason'] ?? null,
                    'decided_by' => $userId,
                    'status' => PromotionDecision::STATUS_DRAFT,
                    'decided_at' => now(),
                ]);

                $saved->push($decision);
            }

            return $saved->map(fn (PromotionDecision $decision) => $decision->fresh([
                'student', 'schoolClass', 'targetClass', 'academicYear', 'approvals',
            ]));
        });
    }

    /**
     * Perbarui isi keputusan selama belum disetujui.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PromotionDecision $decision, array $data): PromotionDecision
    {
        if ($decision->isLocked()) {
            throw ValidationException::withMessages([
                'status' => 'Keputusan yang sudah disetujui atau difinalisasi tidak dapat diubah.',
            ]);
        }

        $decision->update($data);

        return $this->fresh($decision);
    }

    // -----------------------------------------------------------------
    // Alur persetujuan
    // -----------------------------------------------------------------

    public function submit(PromotionDecision $decision): PromotionDecision
    {
        $this->assertStatus($decision, [PromotionDecision::STATUS_DRAFT], 'Hanya keputusan berstatus draft yang dapat diajukan.');

        $decision->update(['status' => PromotionDecision::STATUS_REVIEWED]);

        return $this->fresh($decision);
    }

    /**
     * Setujui keputusan dengan peran tertentu.
     *
     * Baris persetujuan dibuat untuk setiap peran wajib; keputusan berubah
     * menjadi `approved` hanya setelah seluruh peran menyetujui.
     */
    public function approve(PromotionDecision $decision, string $approverRole, ?int $userId, ?string $note = null): PromotionDecision
    {
        $this->assertStatus($decision, [PromotionDecision::STATUS_REVIEWED], 'Keputusan harus berstatus diajukan sebelum disetujui.');

        if (! in_array($approverRole, PromotionApproval::REQUIRED_ROLES, true)) {
            throw ValidationException::withMessages([
                'approver_role' => 'Peran penyetuju tidak dikenal.',
            ]);
        }

        DB::transaction(function () use ($decision, $approverRole, $userId, $note): void {
            foreach (PromotionApproval::REQUIRED_ROLES as $role) {
                PromotionApproval::firstOrCreate(
                    ['promotion_decision_id' => $decision->id, 'approver_role' => $role],
                    ['status' => PromotionApproval::STATUS_PENDING],
                );
            }

            PromotionApproval::query()
                ->where('promotion_decision_id', $decision->id)
                ->where('approver_role', $approverRole)
                ->update([
                    'status' => PromotionApproval::STATUS_APPROVED,
                    'approved_by' => $userId,
                    'approved_at' => now(),
                    'note' => $note,
                ]);

            $pending = PromotionApproval::query()
                ->where('promotion_decision_id', $decision->id)
                ->where('status', '!=', PromotionApproval::STATUS_APPROVED)
                ->exists();

            if (! $pending) {
                $decision->update([
                    'status' => PromotionDecision::STATUS_APPROVED,
                    'decided_at' => now(),
                ]);
            }
        });

        return $this->fresh($decision);
    }

    /**
     * Finalisasi keputusan: tulis riwayat akademik & keanggotaan kelas tujuan.
     *
     * Idempoten — pemanggilan ulang pada keputusan yang sudah final tidak
     * menggandakan data.
     */
    public function finalize(PromotionDecision $decision, ?int $userId = null): PromotionDecision
    {
        if ($decision->isFinalized()) {
            return $this->fresh($decision);
        }

        $this->assertStatus($decision, [PromotionDecision::STATUS_APPROVED], 'Hanya keputusan yang sudah disetujui dapat difinalisasi.');

        $nextYear = $this->nextAcademicYear((int) $decision->academic_year_id);

        if ($nextYear === null) {
            throw ValidationException::withMessages([
                'academic_year_id' => 'Tahun ajaran berikutnya belum dibuat, keputusan belum dapat difinalisasi.',
            ]);
        }

        DB::transaction(function () use ($decision, $nextYear): void {
            $this->applyDecision($decision, $nextYear);
            $decision->update([
                'status' => PromotionDecision::STATUS_FINALIZED,
                'decided_at' => now(),
            ]);
        });

        return $this->fresh($decision);
    }

    /**
     * Batalkan hasil finalisasi dan kembalikan keputusan ke status disetujui.
     */
    public function rollback(PromotionDecision $decision): PromotionDecision
    {
        $this->assertStatus($decision, [PromotionDecision::STATUS_FINALIZED], 'Hanya keputusan yang sudah difinalisasi dapat dibatalkan.');

        DB::transaction(function () use ($decision): void {
            $this->removeFinalizeEffects($decision);
            $decision->update(['status' => PromotionDecision::STATUS_APPROVED]);
        });

        return $this->fresh($decision);
    }

    /**
     * Buka ulang keputusan (mis. karena keberatan) beserta alasan.
     *
     * Bila keputusan sudah difinalisasi, efek finalisasi dibatalkan lebih dulu.
     */
    public function reopen(PromotionDecision $decision, string $reason): PromotionDecision
    {
        $this->assertStatus(
            $decision,
            [PromotionDecision::STATUS_APPROVED, PromotionDecision::STATUS_FINALIZED],
            'Hanya keputusan yang sudah disetujui/difinalisasi yang dapat dibuka ulang.',
        );

        DB::transaction(function () use ($decision, $reason): void {
            if ($decision->isFinalized()) {
                $this->removeFinalizeEffects($decision);
            }

            PromotionApproval::query()->where('promotion_decision_id', $decision->id)->delete();
            $decision->update([
                'status' => PromotionDecision::STATUS_REVIEWED,
                'reason' => $reason,
            ]);
        });

        return $this->fresh($decision);
    }

    // -----------------------------------------------------------------
    // Penerapan & pembalikan keputusan
    // -----------------------------------------------------------------

    private function applyDecision(PromotionDecision $decision, AcademicYear $nextYear): void
    {
        $student = Student::query()->findOrFail($decision->student_id);

        if ($decision->decision === PromotionDecision::DECISION_NAIK) {
            $target = $decision->target_class_id === null
                ? null
                : SchoolClass::query()->find($decision->target_class_id);

            if ($target === null) {
                throw ValidationException::withMessages([
                    'target_class_id' => 'Kelas tujuan wajib dipilih untuk keputusan naik kelas.',
                ]);
            }

            $this->writeHistory($student, $nextYear, $target, 'naik');
            $this->joinClass($student, $target, $nextYear);
            $this->setStudentStatus($student, 'Aktif');

            return;
        }

        if ($decision->decision === PromotionDecision::DECISION_TINGGAL) {
            $current = SchoolClass::query()->find($decision->school_class_id);

            if ($current === null) {
                throw ValidationException::withMessages([
                    'school_class_id' => 'Kelas asal tidak ditemukan.',
                ]);
            }

            $this->writeHistory($student, $nextYear, $current, 'tinggal');
            $this->joinClass($student, $current, $nextYear);
            $this->setStudentStatus($student, 'Tinggal Kelas');

            return;
        }

        throw ValidationException::withMessages([
            'decision' => 'Keputusan lulus/tidak lulus diproses melalui modul kelulusan.',
        ]);
    }

    private function removeFinalizeEffects(PromotionDecision $decision): void
    {
        $nextYear = $this->nextAcademicYear((int) $decision->academic_year_id);

        if ($nextYear === null) {
            return;
        }

        $expectedClassId = $decision->decision === PromotionDecision::DECISION_NAIK
            ? $decision->target_class_id
            : $decision->school_class_id;

        StudentHistory::query()
            ->where('student_id', $decision->student_id)
            ->where('academic_year_id', $nextYear->id)
            ->whereIn('status', ['naik', 'tinggal'])
            ->when($expectedClassId !== null, fn ($query) => $query->where('school_class_id', $expectedClassId))
            ->delete();

        ClassStudent::query()
            ->where('student_id', $decision->student_id)
            ->where('academic_year_id', $nextYear->id)
            ->when($expectedClassId !== null, fn ($query) => $query->where('school_class_id', $expectedClassId))
            ->delete();
    }

    private function writeHistory(Student $student, AcademicYear $year, SchoolClass $class, string $status): void
    {
        StudentHistory::updateOrCreate(
            ['student_id' => $student->id, 'academic_year_id' => $year->id],
            [
                'school_class_id' => $class->id,
                'education_level_id' => $class->education_level_id,
                'status' => $status,
                'note' => $status === 'naik'
                    ? 'Naik kelas ke '.$class->name
                    : 'Tinggal kelas di '.$class->name,
            ],
        );
    }

    private function joinClass(Student $student, SchoolClass $class, AcademicYear $year): void
    {
        ClassStudent::updateOrCreate(
            ['student_id' => $student->id, 'academic_year_id' => $year->id],
            ['school_class_id' => $class->id, 'status' => 'aktif'],
        );
    }

    /**
     * Ubah status siswa bila master `student_statuses` memuat nama tersebut.
     *
     * Sengaja tidak memaksa: bila master belum diisi, keputusan tetap sah dan
     * hanya riwayat akademik yang menjadi sumber kebenaran.
     */
    private function setStudentStatus(Student $student, string $statusName): void
    {
        $statusId = StudentStatus::query()->where('name', $statusName)->value('id');

        if ($statusId !== null) {
            $student->update(['status_id' => $statusId]);
        }
    }

    // -----------------------------------------------------------------
    // Utilitas
    // -----------------------------------------------------------------

    /**
     * @return Collection<int, Student>
     */
    public function activeStudents(SchoolClass $class): Collection
    {
        return Student::query()
            ->whereIn('id', ClassStudent::query()
                ->where('school_class_id', $class->id)
                ->where('status', 'aktif')
                ->pluck('student_id'))
            ->orderBy('full_name')
            ->get();
    }

    public function studentInClass(SchoolClass $class, int $studentId): bool
    {
        return ClassStudent::query()
            ->where('school_class_id', $class->id)
            ->where('student_id', $studentId)
            ->where('status', 'aktif')
            ->exists();
    }

    public function nextAcademicYear(int $academicYearId): ?AcademicYear
    {
        $current = AcademicYear::query()->find($academicYearId);

        if ($current === null) {
            return null;
        }

        return AcademicYear::query()
            ->where('start_date', '>', $current->end_date)
            ->orderBy('start_date')
            ->first();
    }

    /**
     * @return Collection<int, \App\Models\Subject>
     */
    private function classSubjects(SchoolClass $class): Collection
    {
        return $class->classSubjects()
            ->with('subject')
            ->whereHas('subject')
            ->get()
            ->map(fn ($classSubject) => $classSubject->subject)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /**
     * @return Collection<int, Semester>
     */
    private function semestersFor(SchoolClass $class, ?int $semesterId): Collection
    {
        if ($semesterId !== null) {
            return Semester::query()->whereKey($semesterId)->get();
        }

        return Semester::query()
            ->where('academic_year_id', $class->academic_year_id)
            ->orderBy('start_date')
            ->get();
    }

    /**
     * @param  Collection<int, Semester>  $semesters
     * @return array{present: int, basis_days: int, percentage: float}
     */
    private function attendanceSummary(SchoolClass $class, Student $student, Collection $semesters): array
    {
        $educationLevelId = $class->education_level_id === null ? null : (int) $class->education_level_id;
        $present = 0;
        $basis = 0;

        foreach ($semesters as $semester) {
            $recap = $this->attendance->forStudent((int) $student->id, (int) $semester->id, $educationLevelId);
            $present += (int) $recap['total_present'];
            $basis += (int) ($recap['effective_days'] ?? $recap['recorded_days']);
        }

        return [
            'present' => $present,
            'basis_days' => $basis,
            'percentage' => $basis > 0 ? round($present / $basis * 100, 2) : 0.0,
        ];
    }

    /**
     * Total poin pelanggaran siswa pada rentang tahun ajaran kelas.
     */
    private function violationPoints(Student $student, SchoolClass $class): int
    {
        $year = AcademicYear::query()->find($class->academic_year_id);

        $query = DB::table('student_violations')->where('student_id', $student->id);

        if ($year !== null) {
            $query->whereBetween('date', [
                $year->start_date->toDateString(),
                $year->end_date->toDateString(),
            ]);
        }

        return (int) $query->sum('point');
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(PromotionDecision $decision, array $allowed, string $message): void
    {
        if (! in_array($decision->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function fresh(PromotionDecision $decision): PromotionDecision
    {
        return $decision->fresh(['student', 'schoolClass', 'targetClass', 'academicYear', 'approvals.approver']);
    }
}
