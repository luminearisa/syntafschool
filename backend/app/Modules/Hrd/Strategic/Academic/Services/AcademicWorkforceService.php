<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Academic\Services;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Strategic\Skills\Models\HrSkillDemand;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforcePlan;
use App\Modules\Hrd\Strategic\Workforce\Services\ScenarioService;
use Illuminate\Support\Facades\DB;

/**
 * Academic Workforce Intelligence: siswa → kelas → jam mengajar → guru.
 * Inilah pembeda HRD sekolah/pesantren dari HRIS generik.
 *
 * Seluruh data siswa/kelas/dorm/klinik dibaca dari modul asalnya (Academic,
 * Boarding, Clinic) lewat pembacaan yang tahan gagal; tidak ada master siswa
 * atau guru kedua di HRD.
 */
class AcademicWorkforceService
{
    public function __construct(
        private readonly Phase3Context $ctx,
        private readonly ScenarioService $scenarios,
    ) {}

    /**
     * Modul luar mana yang tersedia, target rasio yang dipakai, dan asal data.
     *
     * @return array<string, mixed>
     */
    public function integrationStatus(): array
    {
        $probe = function (array $tables): ?string {
            foreach ($tables as $table) {
                if ($this->ctx->hasTable($table)) {
                    return $table;
                }
            }

            return null;
        };

        return [
            'academic' => [
                'source' => $probe(['ac_students', 'students', 'academic_students', 'student_profiles']),
                'classes' => $probe(['ac_classes', 'classes', 'academic_classes']),
                'assignments' => $probe(['ac_teacher_assignments', 'teacher_assignments', 'ac_schedules']),
                'students_per_teacher_target' => (float) $this->ctx->config('integrations.academic.students_per_teacher_target', 18),
                'weekly_teaching_hours' => (float) $this->ctx->config('integrations.academic.default_weekly_teaching_hours', 24),
            ],
            'boarding' => [
                'source' => $probe(['boarding_residents', 'boarding_students', 'asrama_residents']),
                'dormitories' => $probe(['boarding_dormitories', 'dorms', 'asrama_rooms']),
                'santri_per_musyrif_target' => (float) $this->ctx->config('integrations.boarding.santri_per_musyrif_target', 25),
            ],
            'clinic' => [
                'source' => $probe(['clinic_visits', 'clinic_encounters', 'uks_visits']),
                'visits_per_staff_per_day' => (float) $this->ctx->config('integrations.clinic.visits_per_staff_per_day', 30),
            ],
            'finance' => ['provider' => 'FinanceProviderInterface (Phase 2)', 'status' => $this->ctx->hasTable('fin_journals') ? 'TERDETEKSI' : 'BELUM_TERPASANG'],
            'note' => 'Bila modul sumber belum terpasang, angka diambil dari asumsi skenario yang dinyatakan eksplisit.',
        ];
    }

    /**
     * Siswa → kelas → jam mengajar → kebutuhan guru → gap → rekrutmen/pelatihan.
     *
     * @return array<string, mixed>
     */
    public function studentToWorkforceDemand(array $drivers = []): array
    {
        $students = (int) ($drivers['students_current'] ?? $this->currentStudents());
        $delta = (float) ($drivers['students_delta_percent'] ?? 0.0);
        $target = (int) ($drivers['students_target'] ?? round($students * (1 + $delta / 100)));
        $perTeacher = (float) ($drivers['students_per_teacher'] ?? $this->ctx->config('integrations.academic.students_per_teacher_target', 18));
        $classesPerTeacher = (float) ($drivers['classes_per_teacher'] ?? 5.0);
        $weeklyHours = (float) ($drivers['weekly_teaching_hours'] ?? $this->ctx->config('integrations.academic.default_weekly_teaching_hours', 24));

        $classesNow = (int) ceil($students / max((float) ($drivers['students_per_class'] ?? 28), 1));
        $classesTarget = (int) ceil($target / max((float) ($drivers['students_per_class'] ?? 28), 1));
        $teachersRequiredByRatio = (int) ceil($target / max($perTeacher, 1));
        $teachersRequiredByLoad = (int) ceil(($classesTarget / max($classesPerTeacher, 1)));
        $teacherDemand = max($teachersRequiredByRatio, $teachersRequiredByLoad);

        $currentTeachers = $this->teacherCount();
        $teachingHoursRequired = $teacherDemand * $weeklyHours;
        $gap = max($teacherDemand - $currentTeachers, 0);

        $support = [
            'ADMINISTRATION' => (int) ceil($target / 100 * (float) ($drivers['admin_per_100_students'] ?? 2.5)),
            'IT' => (int) ceil($target / 500 * (float) ($drivers['it_per_500_students'] ?? 0.5)),
            'COUNSELOR' => (int) ceil($target / 400 * (float) ($drivers['counselor_per_400_students'] ?? 1.0)),
        ];

        $route = [
            'recruitment' => $gap > 0 && ($drivers['prefer'] ?? 'BUILD') === 'BUY' ? $gap : 0,
            'training' => ($drivers['prefer'] ?? 'BUILD') !== 'BUY' ? (int) ceil($gap * 0.6) : 0,
            'mobility' => (int) min($gap, $this->internalMobilityCandidates()),
        ];

        $this->recordSkillDemand($drivers, $teacherDemand, $gap);

        return [
            'students' => ['current' => $students, 'target' => $target, 'delta_percent' => $this->ctx->change($students, $target)],
            'classes' => ['current' => $classesNow, 'target' => $classesTarget],
            'teachers' => [
                'current' => $currentTeachers,
                'required_by_ratio' => $teachersRequiredByRatio,
                'required_by_load' => $teachersRequiredByLoad,
                'required' => $teacherDemand,
                'gap' => $gap,
                'student_teacher_ratio_now' => $currentTeachers > 0 ? round($students / $currentTeachers, 2) : null,
                'student_teacher_ratio_target' => $target > 0 ? round($target / max($teacherDemand, 1), 2) : null,
            ],
            'capacity' => [
                'teaching_hours_required_weekly' => $teachingHoursRequired,
                'teaching_hours_available_weekly' => round($currentTeachers * $weeklyHours, 2),
                'gap_hours_weekly' => round($teachingHoursRequired - ($currentTeachers * $weeklyHours), 2),
            ],
            'support_demand' => $support,
            'action_route' => $route,
            'evidence' => [
                'source' => $this->integrationStatus()['academic'],
                'assumptions' => $drivers,
                'period' => $drivers['period'] ?? now()->format('Y'),
            ],
        ];
    }

    /**
     * Kapasitas guru: beban mengajar, rasio, cakupan mapel.
     *
     * @return array<string, mixed>
     */
    public function teacherCapacity(?int $organizationId = null, ?string $period = null): array
    {
        $teachers = $this->teacherQuery($organizationId)->get(['id', 'employee_number', 'position_id', 'department_id']);
        $weeklyHours = (float) $this->ctx->config('integrations.academic.default_weekly_teaching_hours', 24);
        $assignedHours = $this->ctx->safe(function () use ($teachers): array {
            foreach (['ac_teacher_assignments', 'teacher_assignments'] as $table) {
                if (! $this->ctx->hasTable($table)) {
                    continue;
                }
                $hoursColumn = $this->ctx->safe(fn (): ?string => DB::getSchemaBuilder()->hasColumn($table, 'weekly_hours') ? 'weekly_hours' : null, null);

                return DB::table($table)
                    ->whereIn('employee_id', $teachers->pluck('id')->all())
                    ->when($hoursColumn, fn ($q) => $q->selectRaw('employee_id, sum('.$hoursColumn.') as hours')->groupBy('employee_id'))
                    ->when(! $hoursColumn, fn ($q) => $q->selectRaw('employee_id, count(*) as hours')->groupBy('employee_id'))
                    ->pluck('hours', 'employee_id')->all();
            }

            return [];
        }, []);

        $rows = [];
        $totalAssigned = 0.0;
        foreach ($teachers as $teacher) {
            $hours = (float) ($assignedHours[$teacher->getKey()] ?? 0);
            $totalAssigned += $hours;
            $rows[] = [
                'employee_id' => $teacher->getKey(), 'employee_number' => $teacher->employee_number,
                'assigned_hours_weekly' => $hours, 'capacity_hours_weekly' => $weeklyHours,
                'utilization_percent' => $this->ctx->percent($hours, $weeklyHours),
                'over_capacity' => $hours > $weeklyHours,
            ];
        }

        $students = $this->currentStudents();
        $available = count($rows) * $weeklyHours;

        return [
            'teachers' => count($rows),
            'available_hours_weekly' => $available,
            'assigned_hours_weekly' => $this->ctx->money($totalAssigned),
            'utilization_percent' => $this->ctx->percent($totalAssigned, max($available, 1)),
            'students_per_teacher' => count($rows) > 0 ? round($students / count($rows), 2) : null,
            'students' => $students,
            'period' => $period ?? now()->format('Y-m'),
            'note' => 'Utilisasi adalah sinyal, bukan ukuran produktivitas.',
            'rows' => $rows,
        ];
    }

    /**
     * Cakupan mapel & kualifikasi guru: mapel vs guru berkualifikasi/sertifikat.
     *
     * @return array<string, mixed>
     */
    public function subjectCoverage(?int $organizationId = null): array
    {
        $subjects = $this->ctx->safe(function (): array {
            foreach (['ac_subjects', 'subjects', 'ac_courses'] as $table) {
                if ($this->ctx->hasTable($table)) {
                    return DB::table($table)->limit(50)->get()->map(fn ($row): array => [
                        'id' => (int) ($row->id ?? 0),
                        'code' => (string) ($row->code ?? ''),
                        'name' => (string) ($row->name ?? $row->title ?? ''),
                        'required_teachers' => (int) ($row->required_teachers ?? 0),
                    ])->all();
                }
            }

            return [];
        }, []);

        $certifications = $this->ctx->safe(function () use ($organizationId): array {
            if (! $this->ctx->hasTable('hr_certifications')) {
                return [];
            }

            return DB::table('hr_certifications as c')
                ->join('hr_employees as e', 'e.id', '=', 'c.employee_id')
                ->where('e.is_active', true)
                ->when($organizationId, fn ($q) => $q->where('e.organization_id', $organizationId))
                ->get(['c.name', 'c.employee_id'])
                ->groupBy(fn ($row): string => mb_strtolower((string) $row->name))
                ->map->count()->all();
        }, []);

        $subjectsWithGap = [];
        foreach ($subjects as $subject) {
            $key = mb_strtolower($subject['name']);
            $qualified = 0;
            foreach ($certifications as $name => $count) {
                if ($key !== '' && str_contains($name, $key)) {
                    $qualified += (int) $count;
                }
            }
            $required = max($subject['required_teachers'], 1);
            $subjectsWithGap[] = [
                'subject' => $subject['name'], 'qualified_teachers' => $qualified,
                'required_teachers' => $required, 'gap' => max($required - $qualified, 0),
                'certification_gap' => $qualified === 0 ? 'TIDAK_ADA_GURU_BERSERTIFIKAT' : ($qualified < $required ? 'KURANG' : 'CUKUP'),
            ];
        }

        $gaps = array_values(array_filter($subjectsWithGap, fn (array $row): bool => $row['gap'] > 0));

        return [
            'subjects' => count($subjects),
            'with_gap' => count($gaps),
            'rows' => $subjectsWithGap,
            'gaps' => $gaps,
            'note' => $subjects === [] ? 'Modul Academic belum terpasang: cakupan mapel menunggu data sumber.' : null,
        ];
    }

    /**
     * Asrama: santri → dorm → rasio musyrif → shift & jaga malam.
     *
     * @return array<string, mixed>
     */
    public function boardingWorkforce(array $drivers = []): array
    {
        $santri = (int) ($drivers['santri_current'] ?? $this->santriCount());
        $target = (int) ($drivers['santri_target'] ?? round($santri * (1 + (float) ($drivers['santri_delta_percent'] ?? 0) / 100)));
        $ratio = (float) ($drivers['santri_per_musyrif'] ?? $this->ctx->config('integrations.boarding.santri_per_musyrif_target', 25));
        $shifts = (int) ($drivers['shifts_per_day'] ?? 3);
        $dormCapacity = (int) ($drivers['dorm_capacity'] ?? $this->dormCapacity());
        $occupancy = $dormCapacity > 0 ? $this->ctx->percent($santri, $dormCapacity) : null;

        $requiredPerShift = (int) ceil($target / max($ratio, 1) / max($shifts, 1));
        $requiredTotal = $requiredPerShift * $shifts;
        $currentMusyrif = $this->musyrifCount();
        $gap = max($requiredTotal - $currentMusyrif, 0);
        $avgCost = (float) $this->ctx->config('cost.fallback_monthly_per_employee', 5000000);

        return [
            'santri' => ['current' => $santri, 'target' => $target],
            'dorm' => [
                'capacity' => $dormCapacity, 'occupancy_percent' => $occupancy,
                'additional_dorms_needed' => $dormCapacity > 0 && $target > $dormCapacity
                    ? (int) ceil(($target - $dormCapacity) / max((float) ($drivers['dorm_size'] ?? 40), 1)) : 0,
            ],
            'musyrif' => [
                'current' => $currentMusyrif, 'required_per_shift' => $requiredPerShift,
                'required_total' => $requiredTotal, 'gap' => $gap,
                'shift_coverage_percent' => $this->ctx->percent($currentMusyrif, max($requiredTotal, 1)),
                'night_coverage_ok' => $currentMusyrif >= $requiredPerShift,
            ],
            'cost' => [
                'monthly_gap_cost' => $this->ctx->money($gap * $avgCost),
                'annual_gap_cost' => $this->ctx->money($gap * $avgCost * 12),
            ],
            'evidence' => ['ratio' => $ratio, 'shifts_per_day' => $shifts, 'assumptions' => $drivers],
        ];
    }

    /**
     * Klinik: kunjungan pasien → kebutuhan tenaga medis.
     *
     * @return array<string, mixed>
     */
    public function clinicWorkforce(array $drivers = []): array
    {
        $visitsPerDay = (float) ($drivers['visits_per_day'] ?? $this->clinicVisitsPerDay());
        $perStaff = (float) ($drivers['visits_per_staff_per_day'] ?? $this->ctx->config('integrations.clinic.visits_per_staff_per_day', 30));
        $required = (int) ceil($visitsPerDay / max($perStaff, 1));
        $current = $this->clinicStaffCount();
        $gap = max($required - $current, 0);
        $avgCost = (float) $this->ctx->config('cost.fallback_monthly_per_employee', 5000000);

        return [
            'patients' => ['visits_per_day' => $visitsPerDay, 'source' => $this->integrationStatus()['clinic']['source']],
            'staff' => [
                'current' => $current, 'required' => $required, 'gap' => $gap,
                'doctor_demand' => (int) max(1, ceil($required * 0.25)), 'nurse_demand' => (int) max(1, ceil($required * 0.75)),
            ],
            'cost' => ['monthly_gap_cost' => $this->ctx->money($gap * $avgCost)],
            'evidence' => ['visits_per_staff_per_day' => $perStaff, 'assumptions' => $drivers],
        ];
    }

    /**
     * Kampus baru / ekspansi program: template staf → skenario biaya.
     *
     * @return array<string, mixed>
     */
    public function campusExpansionScenario(HrWorkforcePlan $plan, array $drivers = [], mixed $actor = null): array
    {
        $students = (int) ($drivers['students'] ?? 300);
        $template = [
            'TEACHER' => (int) ceil($students / max((float) ($drivers['students_per_teacher'] ?? $this->ctx->config('integrations.academic.students_per_teacher_target', 18)), 1)),
            'ADMINISTRATION' => (int) ceil($students / 100 * 2.5),
            'IT' => (int) ceil($students / 500 * 0.5),
            'SECURITY' => (int) ($drivers['security'] ?? 4),
            'FACILITY' => (int) ceil($students / 150),
            'CLINIC' => (int) max(1, ceil($students / 600 * 1.5)),
            'COUNSELOR' => (int) max(1, ceil($students / 400)),
            'BOARDING' => (int) ($drivers['boarding'] ?? 0),
        ];

        $scenario = $this->scenarios->createScenario($plan, [
            'name' => 'Kampus baru: '.($drivers['name'] ?? 'Ekspansi'),
            'scenario_type' => 'NEW_CAMPUS',
            'assumptions' => array_merge($drivers, [
                'students_current' => 0, 'students_target' => $students, 'campus_count' => 1,
                'hires' => array_sum($template),
            ]),
        ], $actor);

        $results = $this->scenarios->simulate($scenario, $actor);

        return [
            'scenario_code' => $scenario->code,
            'staffing_template' => $template,
            'total_headcount' => array_sum($template),
            'cost' => $results['cost'],
            'headcount' => $results['headcount'],
            'support_demand' => $results['demand']['support_required'] ?? [],
            'results' => $results,
        ];
    }

    /* ------------------------------------------------------------------ */

    private function teacherQuery(?int $organizationId = null): \Illuminate\Database\Eloquent\Builder
    {
        return HrEmployee::query()->where('is_active', true)
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->where(function ($query): void {
                // Nilai referensi Phase 1 berkapital (mis. "Teacher").
                $query->whereRaw("lower(worker_type) in ('teacher','guru','ustadz','lecturer')")
                    ->orWhereRaw("lower(employment_type) = 'teaching'")
                    ->orWhereHas('position', fn ($q) => $q->where('title', 'like', '%guru%')
                        ->orWhere('title', 'like', '%teacher%')
                        ->orWhere('title', 'like', '%ustadz%')
                        ->orWhere('title', 'like', '%dosen%'));
            });
    }

    private function teacherCount(?int $organizationId = null): int
    {
        return (int) $this->ctx->safe(fn (): int => (int) $this->teacherQuery($organizationId)->count(), 0);
    }

    private function currentStudents(): int
    {
        foreach (['ac_students', 'students', 'academic_students', 'student_profiles'] as $table) {
            if ($this->ctx->hasTable($table)) {
                return $this->ctx->probeCount($table, ['status' => 'ACTIVE'], 0) ?: $this->ctx->probeCount($table, [], 0);
            }
        }

        return (int) $this->ctx->config('integrations.academic.fallback_students', 0);
    }

    private function santriCount(): int
    {
        foreach (['boarding_residents', 'boarding_students', 'asrama_residents'] as $table) {
            if ($this->ctx->hasTable($table)) {
                return $this->ctx->probeCount($table, [], 0);
            }
        }

        return (int) $this->ctx->config('integrations.boarding.fallback_santri', 0);
    }

    private function dormCapacity(): int
    {
        foreach (['boarding_dormitories', 'dorms', 'asrama_rooms'] as $table) {
            if ($this->ctx->hasTable($table)) {
                $capacity = (int) $this->ctx->safe(fn (): int => (int) DB::table($table)->sum('capacity'), 0);
                if ($capacity > 0) {
                    return $capacity;
                }
            }
        }

        return (int) $this->ctx->config('integrations.boarding.fallback_capacity', 0);
    }

    private function musyrifCount(): int
    {
        return (int) $this->ctx->safe(fn (): int => (int) HrEmployee::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereRaw("lower(worker_type) in ('boarding','musyrif','asrama')")
                ->orWhereHas('position', fn ($sq) => $sq->where('title', 'like', '%musyrif%')
                    ->orWhere('title', 'like', '%asrama%')))
            ->count(), 0);
    }

    private function clinicStaffCount(): int
    {
        return (int) $this->ctx->safe(fn (): int => (int) HrEmployee::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereRaw("lower(worker_type) in ('clinic','medical','health')")
                ->orWhereHas('position', fn ($sq) => $sq->where('title', 'like', '%dokter%')
                    ->orWhere('title', 'like', '%perawat%')
                    ->orWhere('title', 'like', '%klinik%')))
            ->count(), 0);
    }

    private function clinicVisitsPerDay(): float
    {
        foreach (['clinic_visits', 'clinic_encounters', 'uks_visits'] as $table) {
            if ($this->ctx->hasTable($table)) {
                $count = $this->ctx->probeCount($table, [], 0);
                if ($count > 0) {
                    return round($count / 30, 2);
                }
            }
        }

        return (float) $this->ctx->config('integrations.clinic.fallback_visits_per_day', 20);
    }

    private function internalMobilityCandidates(): int
    {
        return (int) $this->ctx->safe(fn (): int => (int) DB::table('hr_talent_pool_members')->where('status', 'ACTIVE')->count(), 0);
    }

    private function recordSkillDemand(array $drivers, int $teacherDemand, int $gap): void
    {
        $this->ctx->safe(function () use ($drivers, $teacherDemand, $gap): void {
            foreach ((array) ($drivers['subject_skills'] ?? $drivers['critical_skills'] ?? []) as $skillCode) {
                HrSkillDemand::query()->updateOrCreate(
                    ['skill_code' => (string) $skillCode, 'organization_id' => $drivers['organization_id'] ?? null, 'period' => (string) ($drivers['period'] ?? now()->format('Y'))],
                    [
                        'demand_type' => 'FUTURE', 'required_headcount' => $teacherDemand,
                        'required_level' => 'ADVANCED', 'criticality' => $gap > 0 ? 'CRITICAL' : 'NORMAL',
                        'source' => 'ACADEMIC',
                        'evidence' => ['teacher_demand' => $teacherDemand, 'gap' => $gap, 'drivers' => $drivers],
                    ]
                );
            }
        });
    }
}
