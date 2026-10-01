<?php

namespace App\Modules\Activity\Application\Services;

use App\Modules\Activity\Domain\Contracts\AcademicProviderInterface;
use App\Modules\Activity\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\Activity\Domain\Contracts\StudentProviderInterface;
use App\Modules\Activity\Domain\Enums\MembershipStatus;
use App\Modules\Activity\Domain\Enums\RequirementOperator;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivityRegistrationRequirement;
use App\Modules\Activity\Models\ActivitySchedule;
use Illuminate\Support\Facades\DB;

/**
 * Aturan penerimaan anggota (§21–23, §28, §70, §71).
 *
 * Tiga kelompok pemeriksaan, semuanya **dapat dikonfigurasi** dan tidak ada yang
 * ditulis tetap di kode:
 *
 *  1. **Syarat program** — baris `activity_registration_requirements`
 *     (usia, jenjang, gender, prasyarat, keterampilan, rekomendasi, kehadiran).
 *  2. **Kebijakan sekolah** — batas jumlah aktivitas aktif, kebijakan siswa
 *     belum membayar, dan apakah bentrok jadwal menggagalkan pendaftaran.
 *  3. **Keadaan saat ini** — kapasitas tersisa dan bentrok jadwal nyata.
 *
 * Hasilnya selalu berupa daftar `failures` (menggagalkan) dan `warnings`
 * (dicatat tetapi tidak menggagalkan) — supaya sekolah dapat memilih tegas
 * atau longgar tanpa mengubah kode.
 */
class ActivityRequirementService
{
    public function __construct(
        private readonly StudentProviderInterface $students,
        private readonly AcademicProviderInterface $academics,
        private readonly AttendanceProviderInterface $attendance,
        private readonly ActivityIntegrationService $integrations,
    ) {}

    /** Aturan yang berlaku untuk satu program — disalin ke pendaftaran saat diajukan. */
    public function snapshot(ActivityProgram $program): array
    {
        $program->loadMissing('requirements');

        return [
            'program_id' => (int) $program->id,
            'rules' => $program->requirements
                ->sortBy('sort_order')
                ->map(fn (ActivityRegistrationRequirement $rule) => [
                    'type' => $rule->type?->value,
                    'operator' => $rule->operator?->value,
                    'value' => $rule->value,
                    'is_mandatory' => (bool) $rule->is_mandatory,
                    'message' => $rule->message,
                ])
                ->values()
                ->all(),
            'minimum_attendance_percentage' => $program->minimum_attendance_percentage,
            'requires_parent_consent' => (bool) $program->requires_parent_consent,
            'requires_payment' => (bool) $program->requires_payment,
            'selection_method' => $program->selection_method?->value,
            'min_grade_level' => $program->minimum_grade_level,
            'max_grade_level' => $program->maximum_grade_level,
            'gender' => $program->gender?->value,
        ];
    }

    /**
     * Evaluasi lengkap satu pendaftaran.
     *
     * @return array{passed: bool, failures: list<array>, warnings: list<array>, checks: list<array>, capacity: array, conflicts: list<array>, snapshot: array}
     */
    public function evaluate(ActivityProgram $program, int $studentId, array $options = []): array
    {
        $student = $this->students->snapshot($studentId);
        $failures = [];
        $warnings = [];
        $checks = [];

        if ($student === null) {
            return $this->result(
                false,
                [['code' => 'STUDENT_NOT_FOUND', 'message' => 'Siswa tidak ditemukan pada data induk.']],
                [],
                [],
                $this->capacity($program),
                [],
                $this->snapshot($program),
            );
        }

        if (($student['is_active'] ?? true) === false) {
            $failures[] = ['code' => 'STUDENT_INACTIVE', 'message' => 'Siswa berstatus tidak aktif.'];
        }

        // ---- aturan program
        $checks = array_merge($checks, $this->applyRules($program, $student, $studentId, $options, $failures, $warnings));

        // ---- batas jumlah aktivitas
        $maxActive = (int) config('activity.limits.max_active_activities', 3);
        $activeCount = $this->activeMembershipCount($studentId);

        if ($maxActive > 0 && $activeCount >= $maxActive && ! ($options['allow_over_limit'] ?? false)) {
            $message = "Siswa sudah mengikuti {$activeCount} aktivitas aktif (batas {$maxActive}).";
            if ((bool) config('activity.limits.max_activities_is_hard', true)) {
                $failures[] = ['code' => 'MAX_ACTIVITIES', 'message' => $message];
            } else {
                $warnings[] = ['code' => 'MAX_ACTIVITIES', 'message' => $message];
            }
        }

        // ---- kapasitas (bukan kegagalan: menjadi daftar tunggu)
        $capacity = $this->capacity($program);

        if ($capacity['is_full'] && ! ($options['allow_over_capacity'] ?? false)) {
            $warnings[] = [
                'code' => 'CAPACITY_FULL',
                'message' => 'Kursi sudah penuh; pendaftaran akan masuk daftar tunggu.',
                'details' => $capacity,
            ];
        }

        // ---- bentrok jadwal
        $conflicts = $this->conflicts($studentId, $program);

        if ($conflicts !== []) {
            $messages = array_map(fn (array $conflict) => $conflict['message'], $conflicts);

            // Bentrok disebut lawannya: "bentrok dengan kegiatan asrama Kajian
            // Ba'da Ashar (Senin 15:30–16:30)" lebih dapat ditindaklanjuti
            // daripada sekadar "jadwal bentrok".
            $summary = 'Jadwal bentrok: '.implode('; ', $messages);

            if ((bool) config('activity.conflict.block_enrollment', true)) {
                $failures[] = ['code' => 'SCHEDULE_CONFLICT', 'message' => $summary, 'details' => $messages];
            } else {
                $warnings[] = ['code' => 'SCHEDULE_CONFLICT', 'message' => $summary, 'details' => $messages];
            }
        }

        // ---- kebijakan keuangan
        if ($program->requires_payment && (float) $program->fee_amount > 0) {
            $policy = $this->integrations->finance()->unpaidPolicy();
            $entry = [
                'code' => 'PAYMENT_REQUIRED',
                'message' => 'Aktivitas ini berbiaya '.number_format((float) $program->fee_amount, 0, ',', '.').'; tagihan akan diajukan ke Keuangan.',
                'details' => ['policy' => $policy['policy'] ?? 'WARN', 'amount' => (float) $program->fee_amount],
            ];

            if (($policy['policy'] ?? 'WARN') === 'RESTRICT') {
                $warnings[] = $entry;
            } else {
                $checks[] = $entry;
            }
        }

        // ---- persetujuan orang tua
        if ($program->requires_parent_consent) {
            $checks[] = [
                'code' => 'CONSENT_REQUIRED',
                'message' => 'Persetujuan orang tua diperlukan sebelum keanggotaan aktif.',
            ];
        }

        return $this->result($failures === [], $failures, $warnings, $checks, $capacity, $conflicts, $this->snapshot($program), $student);
    }

    /** @return array{active: int, waitlisted: int, maximum: int|null, minimum: int|null, is_full: bool, seats_left: int|null} */
    public function capacity(ActivityProgram $program): array
    {
        $active = ActivityMembership::query()
            ->where('program_id', $program->id)
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
            ->count();

        $maximum = $program->maximum_capacity === null ? null : (int) $program->maximum_capacity;

        return [
            'active' => $active,
            'waitlisted' => DB::table('activity_waitlist_entries')->where('program_id', $program->id)->where('status', 'WAITING')->count(),
            'maximum' => $maximum,
            'minimum' => $program->minimum_capacity === null ? null : (int) $program->minimum_capacity,
            'is_full' => $maximum !== null && $active >= $maximum,
            'seats_left' => $maximum === null ? null : max(0, $maximum - $active),
        ];
    }

    public function activeMembershipCount(int $studentId): int
    {
        return ActivityMembership::query()
            ->where('student_id', $studentId)
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
            ->count();
    }

    /**
     * Deteksi bentrok: aktivitas lain, kelas akademik, dan kewajiban asrama.
     *
     * @return list<array{type: string, message: string, details: array}>
     */
    public function conflicts(int $studentId, ActivityProgram $program, ?int $ignoreProgramId = null): array
    {
        $slots = $this->programSlots($program);

        if ($slots === []) {
            return [];
        }

        $conflicts = [];

        // ---- aktivitas lain yang diikuti siswa
        $otherPrograms = ActivityMembership::query()
            ->where('student_id', $studentId)
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
            ->where('program_id', '!=', $program->id)
            ->when($ignoreProgramId !== null, fn ($query) => $query->where('program_id', '!=', $ignoreProgramId))
            ->with('program:id,name')
            ->get();

        foreach ($otherPrograms as $membership) {
            if ($membership->program === null) {
                continue;
            }

            $otherSlots = $this->programSlots($membership->program);

            foreach ($this->overlaps($slots, $otherSlots) as $hit) {
                $conflicts[] = [
                    'type' => 'ACTIVITY',
                    'message' => 'Bentrok dengan aktivitas "'.$membership->program->name.'" ('.$hit.')',
                    'details' => ['program_id' => (int) $membership->program_id],
                ];
            }
        }

        // ---- jadwal kelas akademik
        if ((bool) config('activity.conflict.check_academic', true)) {
            foreach ($this->academicSlots($studentId) as $academic) {
                foreach ($this->overlaps($slots, [$academic]) as $hit) {
                    $conflicts[] = [
                        'type' => 'ACADEMIC',
                        'message' => 'Bentrok dengan pelajaran '.$academic['label'].' ('.$hit.')',
                        'details' => ['subject' => $academic['label'], 'day' => $academic['day']],
                    ];
                }
            }
        }

        // ---- kewajiban asrama
        if ((bool) config('activity.conflict.check_boarding', true)) {
            foreach ($this->boardingslots() as $obligation) {
                foreach ($this->overlaps($slots, [$obligation]) as $hit) {
                    $conflicts[] = [
                        'type' => 'BOARDING',
                        'message' => 'Bentrok dengan kegiatan asrama '.$obligation['label'].' ('.$hit.')',
                        'details' => ['activity' => $obligation['label']],
                    ];
                }
            }
        }

        return $conflicts;
    }

    /** Apakah dua kumpulan slot beririsan; mengembalikan deskripsi bentrok yang terjadi. */
    public function overlaps(array $slotsA, array $slotsB): array
    {
        $hits = [];

        foreach ($slotsA as $a) {
            foreach ($slotsB as $b) {
                if ((int) $a['day'] !== (int) $b['day']) {
                    continue;
                }

                if ($a['start'] < $b['end'] && $b['start'] < $a['end']) {
                    $hits[] = $this->dayName((int) $a['day']).' '.substr($a['start'], 0, 5).'–'.substr($a['end'], 0, 5);
                }
            }
        }

        return array_values(array_unique($hits));
    }

    /** @return list<array{day: int, start: string, end: string, label: string}> */
    public function programSlots(ActivityProgram $program): array
    {
        return $program->schedules()
            ->where('status', 'ACTIVE')
            ->get()
            ->map(fn (ActivitySchedule $schedule) => [
                'day' => (int) $schedule->day_of_week,
                'start' => (string) $schedule->start_time,
                'end' => (string) $schedule->end_time,
                'label' => 'jadwal '.$this->dayName((int) $schedule->day_of_week),
            ])
            ->all();
    }

    /** @return list<array{day: int, start: string, end: string, label: string}> */
    protected function academicSlots(int $studentId): array
    {
        $students = $this->students;
        $class = $students->currentClass($studentId);

        if ($class === null) {
            return [];
        }

        $rows = DB::table('schedules')
            ->leftJoin('subjects', 'subjects.id', '=', 'schedules.subject_id')
            ->leftJoin('days', 'days.id', '=', 'schedules.day_id')
            ->where('schedules.school_class_id', $class['id'])
            ->get([
                'schedules.start_time', 'schedules.end_time',
                'days.name as day_name', 'subjects.name as subject_name',
            ]);

        return $rows->map(fn ($row) => [
            'day' => $this->dayNumber((string) $row->day_name),
            'start' => (string) $row->start_time,
            'end' => (string) $row->end_time,
            'label' => (string) ($row->subject_name ?? 'pelajaran'),
        ])->filter(fn (array $slot) => $slot['day'] > 0)->values()->all();
    }

    /** @return list<array{day: int, start: string, end: string, label: string}> */
    protected function boardingSlots(): array
    {
        return collect($this->integrations->boarding()->weeklyObligations())
            ->map(fn (array $row) => [
                'day' => (int) ($row['day_of_week'] ?? 0),
                'start' => (string) ($row['start_time'] ?? '00:00'),
                'end' => (string) ($row['end_time'] ?? '00:00'),
                'label' => (string) ($row['activity'] ?? 'kegiatan asrama'),
            ])
            ->filter(fn (array $slot) => $slot['day'] > 0)
            ->values()
            ->all();
    }

    protected function applyRules(
        ActivityProgram $program,
        array $student,
        int $studentId,
        array $options,
        array &$failures,
        array &$warnings,
    ): array {
        $checks = [];

        // jenjang
        $level = $student['class_level'] ?? null;

        if ($program->minimum_grade_level !== null && $level !== null && $level < (int) $program->minimum_grade_level) {
            $failures[] = ['code' => 'GRADE', 'message' => 'Jenjang kelas belum memenuhi syarat minimum aktivitas.'];
        }

        if ($program->maximum_grade_level !== null && $level !== null && $level > (int) $program->maximum_grade_level) {
            $failures[] = ['code' => 'GRADE', 'message' => 'Jenjang kelas melampaui batas maksimum aktivitas.'];
        }

        // gender
        $policy = $program->gender?->value ?? 'ANY';

        if ($policy !== 'ANY') {
            $gender = strtolower((string) ($student['gender'] ?? ''));
            $isMale = str_contains($gender, 'laki') || str_contains($gender, 'putra') || $gender === 'male';
            $wantsMale = $policy === 'MALE';

            if ($gender !== '' && $isMale !== $wantsMale) {
                $failures[] = ['code' => 'GENDER', 'message' => 'Aktivitas ini ditujukan untuk '.($wantsMale ? 'putra' : 'putri').'.'];
            }
        }

        // aturan terkonfigurasi
        foreach ($program->requirements()->orderBy('sort_order')->get() as $rule) {
            $outcome = $this->applyRule($rule, $student, $studentId, $options);

            if ($outcome === null) {
                continue;
            }

            $checks[] = $outcome;

            if (($outcome['passed'] ?? true) === false) {
                if ($rule->is_mandatory) {
                    $failures[] = ['code' => $rule->type?->value, 'message' => $outcome['message'], 'details' => $outcome['details'] ?? []];
                } else {
                    $warnings[] = ['code' => $rule->type?->value, 'message' => $outcome['message'], 'details' => $outcome['details'] ?? []];
                }
            }
        }

        return $checks;
    }

    /** @return array{code: string, passed: bool, message: string, details?: array}|null */
    protected function applyRule(ActivityRegistrationRequirement $rule, array $student, int $studentId, array $options): ?array
    {
        $value = $rule->value;
        $operator = $rule->operator?->value ?? 'GTE';

        return match ($rule->type?->value) {
            'AGE' => $this->compare('AGE', $student['age_years'] ?? null, $operator, $this->scalar($value), 'Usia belum memenuhi syarat aktivitas.'),
            'GRADE' => $this->compare('GRADE', $student['class_level'] ?? null, $operator, $this->scalar($value), 'Jenjang kelas belum memenuhi syarat aktivitas.'),
            'PROGRAM' => $this->compare('PROGRAM', $student['program'] ?? null, $operator, $this->scalar($value), 'Program/jurusan belum sesuai.'),
            'ATTENDANCE' => $this->attendanceRule($studentId, $operator, $this->scalar($value)),
            'ACADEMIC_STANDING' => $this->academicRule($studentId, $operator, $this->scalar($value)),
            'PREREQUISITE' => $this->prerequisiteRule($studentId, $value),
            'SKILL' => $this->skillRule($studentId, $value, $operator),
            'RECOMMENDATION' => [
                'code' => 'RECOMMENDATION',
                'passed' => trim((string) ($options['recommendation'] ?? '')) !== '',
                'message' => 'Memerlukan rekomendasi guru — lampirkan atau mintakan lebih dahulu.',
            ],
            'CAPACITY' => null,
            'CONSENT' => null,
            'AUDITION', 'TRIAL' => [
                'code' => $rule->type->value,
                'passed' => true,
                'message' => 'Pendaftar akan melalui tahap '.strtolower($rule->type->label()).'.',
            ],
            'BOARDING' => null,
            'MAX_ACTIVITIES' => null,
            'SCHEDULE_CONFLICT' => null,
            default => null,
        };
    }

    protected function compare(string $code, mixed $actual, string $operator, mixed $expected, string $message): array
    {
        if ($actual === null || $expected === null) {
            return ['code' => $code, 'passed' => true, 'message' => $message.' (data pembanding tidak tersedia — dilewati).'];
        }

        $passed = match ($operator) {
            'EQ' => (string) $actual === (string) $expected,
            'NEQ' => (string) $actual !== (string) $expected,
            'GT' => $actual > $expected,
            'GTE' => $actual >= $expected,
            'LT' => $actual < $expected,
            'LTE' => $actual <= $expected,
            'IN' => in_array($actual, (array) $expected, false),
            'NOT_IN' => ! in_array($actual, (array) $expected, false),
            default => true,
        };

        return ['code' => $code, 'passed' => $passed, 'message' => $message, 'details' => ['actual' => $actual, 'expected' => $expected, 'operator' => $operator]];
    }

    protected function attendanceRule(int $studentId, string $operator, mixed $expected): array
    {
        $summary = $this->integrations->call('ATTENDANCE', 'summary', ['student_id' => $studentId], fn () => [
            'status' => 'OK',
            'summary' => $this->attendance->summary($studentId, now()->subMonths(3)->toDateString(), now()->toDateString()),
        ]);

        $percentage = $summary['summary']['percentage'] ?? null;

        return $this->compare('ATTENDANCE', $percentage, $operator, $expected, 'Kehadiran akademik belum memenuhi syarat.');
    }

    protected function academicRule(int $studentId, string $operator, mixed $expected): array
    {
        $standing = $this->academics->standing($studentId);
        $value = $standing['activity_average_score'] ?? null;

        if ($value === null) {
            return ['code' => 'ACADEMIC_STANDING', 'passed' => true, 'message' => 'Nilai pembanding belum tersedia — aturan dilewati.'];
        }

        return $this->compare('ACADEMIC_STANDING', $value, $operator, $expected, 'Capaian akademik/aktivitas belum memenuhi syarat.');
    }

    protected function prerequisiteRule(int $studentId, mixed $value): array
    {
        $programIds = ActivityProgram::query()
            ->whereIn('code', (array) $value)
            ->pluck('id')
            ->all();

        if ($programIds === []) {
            $programIds = array_filter(array_map('intval', (array) $value));
        }

        if ($programIds === []) {
            return ['code' => 'PREREQUISITE', 'passed' => true, 'message' => 'Prasyarat tidak terpasang pada program ini.'];
        }

        $completed = ActivityMembership::query()
            ->where('student_id', $studentId)
            ->whereIn('program_id', $programIds)
            ->where('status', MembershipStatus::COMPLETED->value)
            ->exists();

        return [
            'code' => 'PREREQUISITE',
            'passed' => $completed,
            'message' => 'Belum menyelesaikan aktivitas prasyarat.',
            'details' => ['prerequisite_program_ids' => $programIds],
        ];
    }

    protected function skillRule(int $studentId, mixed $value, string $operator): array
    {
        $skillIds = DB::table('activity_skills')
            ->whereIn('code', (array) $value)
            ->pluck('id')
            ->all();

        if ($skillIds === []) {
            return ['code' => 'SKILL', 'passed' => true, 'message' => 'Keterampilan pembanding tidak ditemukan — aturan dilewati.'];
        }

        $evidence = DB::table('activity_skill_evidence')
            ->where('student_id', $studentId)
            ->whereIn('skill_id', $skillIds)
            ->orderByDesc('score')
            ->value('score');

        return $this->compare('SKILL', $evidence === null ? null : (float) $evidence, $operator === 'EXISTS' ? 'GTE' : $operator, 0, 'Belum ada bukti keterampilan yang diminta.');
    }

    protected function result(
        bool $passed,
        array $failures,
        array $warnings,
        array $checks,
        array $capacity,
        array $conflicts,
        array $snapshot,
        ?array $student = null,
    ): array {
        return [
            'passed' => $passed,
            'failures' => $failures,
            'warnings' => $warnings,
            'checks' => $checks,
            'capacity' => $capacity,
            'conflicts' => $conflicts,
            'snapshot' => $snapshot,
            'student' => $student,
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    protected function scalar(mixed $value): mixed
    {
        if (is_array($value) && count($value) === 1) {
            return reset($value);
        }

        return is_array($value) ? array_values($value) : $value;
    }

    protected function dayName(int $day): string
    {
        return ['1' => 'Senin', '2' => 'Selasa', '3' => 'Rabu', '4' => 'Kamis', '5' => 'Jumat', '6' => 'Sabtu', '7' => 'Minggu'][(string) $day] ?? 'Hari';
    }

    protected function dayNumber(string $name): int
    {
        $map = ['senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4, 'jumat' => 5, 'sabtu' => 6, 'minggu' => 7, 'ahad' => 7];

        return $map[strtolower(trim($name))] ?? 0;
    }
}
