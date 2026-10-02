<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\{
    AttendanceGatewayInterface, BoardingGatewayInterface, DisciplineGatewayInterface, StudentGatewayInterface
};
use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{
    PrayerAttendanceAdjustment, PrayerAttendancePolicy, PrayerAttendanceRecord, PrayerSchedule, ReligiousDailyClosing
};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Presensi shalat (§22-§26, §70).
 *
 * Batasan yang ditegakkan di sini:
 * - Aturan 5: presensi hanya dalam jendela waktu yang sah, kecuali penyesuaian
 *   berwenang.
 * - Aturan 12 / §26: presensi keagamaan TIDAK otomatis menjadi sanksi
 *   kedisiplinan; penerusan hanya bila institusi mengaktifkannya.
 * - Modul ini bukan mesin presensi baru: catatan operasional disimpan lokal,
 *   lalu dipantulkan ke Attendance Provider institusi bila terpasang.
 */
class PrayerAttendanceService
{
    public const STATUSES = ['PRESENT', 'LATE', 'ABSENT', 'EXCUSED', 'AUTHORIZED_ABSENCE', 'UNKNOWN'];

    public function __construct(
        protected ReligiousContext $context,
        protected AttendanceGatewayInterface $attendance,
        protected BoardingGatewayInterface $boarding,
        protected StudentGatewayInterface $students,
        protected DisciplineGatewayInterface $discipline,
    ) {
    }

    // ── Kebijakan & jendela (§25) ───────────────────────────────────────

    /**
     * Pilih kebijakan presensi yang paling spesifik untuk konteks tertentu.
     * Urutan prioritas: asrama → kelas/tingkat → kampus → organisasi → global.
     */
    public function policyFor(array $context): ?PrayerAttendancePolicy
    {
        $candidates = PrayerAttendancePolicy::query()->where('active', true)
            ->where('status', 'ACTIVE')
            ->orderByDesc('priority')->get();

        $best = null;
        $bestScore = -1;

        foreach ($candidates as $policy) {
            $score = 0;

            if ($policy->scope_type === 'GLOBAL') {
                $score = 1;
            }
            if ($policy->scope_type === 'ORGANIZATION' && ! empty($context['organization_id'])
                && (int) $policy->organization_id === (int) $context['organization_id']) {
                $score = 2;
            }
            if ($policy->scope_type === 'CAMPUS' && ! empty($context['campus_id'])
                && (int) $policy->campus_id === (int) $context['campus_id']) {
                $score = 3;
            }
            if ($policy->scope_type === 'GRADE' && ! empty($context['grade_level'])
                && (string) $policy->grade_level === (string) $context['grade_level']) {
                $score = 4;
            }
            if ($policy->scope_type === 'DORMITORY' && ! empty($context['dormitory_id'])
                && (int) $policy->dormitory_id === (int) $context['dormitory_id']) {
                $score = 5;
            }

            // Kebijakan yang menyebut jenis shalat tertentu lebih diutamakan.
            if ($score > 0 && ! empty($context['prayer_type'])) {
                $types = array_map('strtoupper', (array) ($policy->prayer_types ?? []));
                if ($types !== [] && in_array(strtoupper((string) $context['prayer_type']), $types, true)) {
                    $score += 10;
                }
            }

            if ($score > $bestScore) {
                $best = $policy;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Evaluasi apakah pencatatan berada dalam jendela yang sah (Aturan 5).
     *
     * @return array{allowed:bool, status_hint:?string, reason:?string, policy_id:?int}
     */
    public function windowCheck(array $context, ?string $at = null): array
    {
        $policy = $this->policyFor($context);
        $now = $at ? \Carbon\Carbon::parse($at) : now();

        $schedule = ! empty($context['prayer_schedule_id'])
            ? PrayerSchedule::query()->find($context['prayer_schedule_id'])
            : null;

        $adhan = $schedule?->adhan_time ? substr((string) $schedule->adhan_time, 0, 5)
            : ($context['adhan_time'] ?? null);

        if ($policy === null || $adhan === null) {
            // Tanpa kebijakan & tanpa jadwal, pencatatan tetap diizinkan sebagai
            // data operasional (mis. shalat sunnah atau kegiatan khusus).
            return [
                'allowed' => true,
                'status_hint' => null,
                'reason' => $policy === null
                    ? 'Belum ada kebijakan presensi; dicatat sebagai data operasional.'
                    : null,
                'policy_id' => null,
            ];
        }

        $adhanMinutes = $this->context->timeToMinutes($adhan);
        $nowMinutes = ((int) $now->format('H')) * 60 + ((int) $now->format('i'));

        $opens = $adhanMinutes - (int) $policy->window_before_minutes;
        $closes = $adhanMinutes + (int) $policy->window_after_minutes;
        $lateAfter = $adhanMinutes + (int) $policy->late_threshold_minutes;

        if ($nowMinutes < $opens || $nowMinutes > $closes) {
            return [
                'allowed' => false,
                'status_hint' => null,
                'reason' => 'Di luar jendela presensi ('.$opens.'-'.$closes.' menit dari adhan). '
                    .'Gunakan penyesuaian berwenang (Aturan 5).',
                'policy_id' => $policy->getKey(),
            ];
        }

        return [
            'allowed' => true,
            'status_hint' => $nowMinutes > $lateAfter ? 'LATE' : 'PRESENT',
            'reason' => null,
            'policy_id' => $policy->getKey(),
        ];
    }

    // ── Pencatatan (§22, §23, §39, §70) ───────────────────

    /**
     * Catat satu presensi shalat.
     *
     * @param bool $authorized melewati validasi jendela (Aturan 5, penyesuaian)
     */
    public function record(array $payload, bool $authorized = false): array
    {
        $status = strtoupper((string) ($payload['status'] ?? 'PRESENT'));
        if (! in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Status presensi tidak dikenal: '.$status,
            ]);
        }

        $date = $payload['attendance_date'] ?? now()->toDateString();
        $prayerType = strtoupper((string) $payload['prayer_type']);
        $personKey = ! empty($payload['student_id']) ? 'student:'.$payload['student_id'] : 'employee:'.($payload['employee_id'] ?? '-');

        $check = $this->windowCheck($payload + ['attendance_date' => $date], $payload['recorded_at'] ?? null);
        if (! $check['allowed'] && ! $authorized) {
            throw ValidationException::withMessages(['status' => $check['reason']]);
        }

        // Idempotensi: satu orang, satu shalat, satu hari = satu catatan.
        $scopeKey = implode('|', [$personKey, $prayerType, $date, (string) ($payload['mosque_id'] ?? '-')]);

        $outcome = $this->context->idempotent('prayer_attendance', $scopeKey, function () use ($payload, $status, $date, $prayerType, $check) {
            $record = PrayerAttendanceRecord::query()->create([
                'mosque_id' => $payload['mosque_id'] ?? null,
                'campus_id' => $payload['campus_id'] ?? null,
                'prayer_schedule_id' => $payload['prayer_schedule_id'] ?? null,
                'congregational_prayer_id' => $payload['congregational_prayer_id'] ?? null,
                'prayer_type' => $prayerType,
                'attendance_date' => $date,
                'participant_type' => strtoupper((string) ($payload['participant_type'] ?? 'STUDENT')),
                'student_id' => $payload['student_id'] ?? null,
                'employee_id' => $payload['employee_id'] ?? null,
                'person_name' => $payload['person_name'] ?? null,
                'organization_id' => $payload['organization_id'] ?? null,
                'dormitory_id' => $payload['dormitory_id'] ?? null,
                'grade_level' => $payload['grade_level'] ?? null,
                'class_id' => $payload['class_id'] ?? null,
                'status' => $status,
                'method' => strtoupper((string) ($payload['method'] ?? 'MANUAL')),
                'recorded_by' => $this->context->actorId(),
                'recorded_at' => $payload['recorded_at'] ?? now(),
                'minutes_late' => $payload['minutes_late'] ?? null,
                'device_code' => $payload['device_code'] ?? null,
                'policy_id' => $check['policy_id'],
                'external_reference' => $payload['external_reference'] ?? null,
                'is_adjusted' => false,
                'notes' => $payload['notes'] ?? null,
                'meta' => $payload['meta'] ?? null,
            ]);

            $this->context->audit('religious.prayer_attendance.recorded', $record, [
                'mosque_id' => $record->mosque_id, 'campus_id' => $record->campus_id,
                'status' => $status, 'method' => $record->method,
            ]);
            $this->context->publish('PrayerAttendanceRecorded', $record, [
                'prayer_type' => $prayerType, 'date' => $date, 'status' => $status,
            ]);

            return [
                'id' => $record->getKey(),
                'status' => $status,
                'prayer_type' => $prayerType,
                'attendance_date' => $date,
            ];
        }, ['source' => $payload['method'] ?? 'MANUAL', 'device_id' => $payload['device_code'] ?? null]);

        $mirrored = null;
        if (! $outcome['duplicate']) {
            // Pantulkan ke mesin presensi institusi bila terpasang (§22).
            $mirrored = $this->attendance->record([
                'person_type' => $payload['participant_type'] ?? 'STUDENT',
                'student_id' => $payload['student_id'] ?? null,
                'employee_id' => $payload['employee_id'] ?? null,
                'date' => $date,
                'time' => $payload['recorded_at'] ?? now()->format('H:i'),
                'status' => $status,
                'method' => $payload['method'] ?? 'MANUAL',
                'reference_type' => 'PRAYER',
                'prayer_type' => $prayerType,
                'notes' => 'Presensi shalat '.$prayerType,
            ]);
        }

        return [
            'recorded' => true,
            'duplicate' => $outcome['duplicate'],
            'attendance' => $outcome['result'],
            'mirrored_to_attendance_provider' => $mirrored,
            'window' => ['checked' => true, 'authorized_override' => $authorized],
        ];
    }

    /** Catat massal (mis. satu asrama sekaligus). */
    public function recordBulk(array $payloads, bool $authorized = false): array
    {
        $results = ['recorded' => 0, 'duplicates' => 0, 'failed' => 0, 'rows' => []];

        foreach ($payloads as $payload) {
            try {
                $result = $this->record($payload, $authorized);
                $result['duplicate'] ? $results['duplicates']++ : $results['recorded']++;
                $results['rows'][] = $result['attendance'];
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['rows'][] = ['error' => $e->getMessage(), 'payload' => $payload['student_id'] ?? null];
            }
        }

        return $results;
    }

    // ── Asrama (§24, §69) ───────────────────────────────────────────

    /** Kewajiban shalat berjamaah penghuni asrama pada tanggal tertentu. */
    public function boardingRequirements(int $studentId, string $date): array
    {
        $isResident = $this->boarding->isResident($studentId, $date);
        if (! $isResident) {
            return ['is_resident' => false, 'required_prayers' => [], 'rules' => []];
        }

        $rules = $this->context->safe(fn () => DB::table('religious_boarding_prayer_rules')
            ->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->where('required', true))
            ->get()->all(), []);

        $prayers = [];
        foreach ($rules as $rule) {
            $prayers = array_merge($prayers, array_map('strtoupper', (array) json_decode((string) ($rule->prayer_types ?? '[]'), true)));
        }

        return [
            'is_resident' => true,
            'required_prayers' => array_values(array_unique($prayers)),
            'rules' => array_map(fn ($r): array => (array) $r, $rules),
            'obligations' => $this->boarding->obligationsFor($studentId, $date),
        ];
    }

    /** Tetapkan aturan shalat berjamaah untuk asrama/tingkat (§24). */
    public function setBoardingRule(array $payload): array
    {
        $id = DB::table('religious_boarding_prayer_rules')->insertGetId([
            'dormitory_id' => $payload['dormitory_id'] ?? null,
            'grade_level' => $payload['grade_level'] ?? null,
            'program_id' => $payload['program_id'] ?? null,
            'prayer_types' => json_encode(array_map('strtoupper', (array) ($payload['prayer_types'] ?? []))),
            'policy_id' => $payload['policy_id'] ?? null,
            'required' => (bool) ($payload['required'] ?? true),
            'effective_from' => $payload['effective_from'] ?? now()->toDateString(),
            'effective_until' => $payload['effective_until'] ?? null,
            'status' => 'ACTIVE',
            'notes' => $payload['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->context->audit('religious.boarding_prayer_rule.created', null, $payload + ['rule_id' => $id]);

        return ['id' => $id, 'created' => true];
    }

    // ── Penyesuaian (§95, Aturan 5) ─────────────────────────────────────

    /** Ajukan penyesuaian presensi; wajib beralasan. */
    public function requestAdjustment(array $payload): PrayerAttendanceAdjustment
    {
        $adjustment = PrayerAttendanceAdjustment::query()->create([
            'attendance_id' => $payload['attendance_id'] ?? null,
            'student_id' => $payload['student_id'] ?? null,
            'employee_id' => $payload['employee_id'] ?? null,
            'prayer_type' => isset($payload['prayer_type']) ? strtoupper((string) $payload['prayer_type']) : null,
            'attendance_date' => $payload['attendance_date'] ?? now()->toDateString(),
            'from_status' => $payload['from_status'] ?? null,
            'to_status' => strtoupper((string) $payload['to_status']),
            'reason' => $payload['reason'] ?? null,
            'adjusted_by' => $this->context->actorId() ?? 0,
            'adjusted_at' => now(),
            'status' => 'PENDING',
            'evidence_document_id' => $payload['evidence_document_id'] ?? null,
            'is_authorized' => (bool) ($payload['is_authorized'] ?? false),
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->context->audit('religious.prayer_attendance.adjustment_requested', $adjustment, [], $payload['reason'] ?? null);

        return $adjustment;
    }

    /** Setujui penyesuaian → tulis ulang status presensi (tercatat sebagai disesuaikan). */
    public function approveAdjustment(int $adjustmentId, string $approverNote = ''): array
    {
        $adjustment = PrayerAttendanceAdjustment::query()->findOrFail($adjustmentId);

        $adjustment->forceFill([
            'status' => 'APPROVED',
            'approved_by' => $this->context->actorId(),
            'approved_at' => now(),
            'is_authorized' => true,
            'notes' => trim($adjustment->notes."
".$approverNote) ?: null,
        ])->save();

        $applied = false;
        if ($adjustment->attendance_id) {
            $applied = (bool) PrayerAttendanceRecord::query()->whereKey($adjustment->attendance_id)
                ->update([
                    'status' => $adjustment->to_status,
                    'is_adjusted' => true,
                    'updated_at' => now(),
                ]);
        }

        $this->context->audit('religious.prayer_attendance.adjustment_approved', $adjustment, [
            'applied_to_record' => $applied, 'to_status' => $adjustment->to_status,
        ], $approverNote);

        return ['adjustment_id' => $adjustment->getKey(), 'status' => 'APPROVED', 'applied' => $applied];
    }

    // ── Penutupan harian (§75) & dashboard (§70) ────────────────────

    public function closeDay(string $date, array $scope = []): ReligiousDailyClosing
    {
        $stats = $this->statistics($date, $scope);

        $closing = ReligiousDailyClosing::query()->updateOrCreate(
            [
                'closing_date' => $date,
                'campus_id' => $scope['campus_id'] ?? null,
                'mosque_id' => $scope['mosque_id'] ?? null,
                'scope_type' => strtoupper((string) ($scope['scope_type'] ?? 'CAMPUS')),
                'scope_id' => $scope['scope_id'] ?? null,
            ],
            [
                'total_expected' => $stats['total'],
                'present_count' => $stats['present'],
                'late_count' => $stats['late'],
                'absent_count' => $stats['absent'],
                'excused_count' => $stats['excused'],
                'authorized_absence_count' => $stats['authorized_absence'],
                'unknown_count' => $stats['unknown'],
                'attendance_rate' => $stats['attendance_rate'],
                'closed_by' => $this->context->actorId(),
                'closed_at' => now(),
                'status' => 'CLOSED',
                'summary' => $stats['by_prayer'],
                'notes' => $scope['notes'] ?? null,
            ]
        );

        $this->context->audit('religious.daily_closing.created', $closing, [
            'campus_id' => $closing->campus_id, 'mosque_id' => $closing->mosque_id,
            'attendance_rate' => $closing->attendance_rate,
        ]);

        return $closing;
    }

    /** Statistik presensi satu hari dengan pemecahan per shalat (§70). */
    public function statistics(string $date, array $filters = []): array
    {
        $query = PrayerAttendanceRecord::query()->whereDate('attendance_date', $date)
            ->when(! empty($filters['campus_id']), fn ($q) => $q->where('campus_id', $filters['campus_id']))
            ->when(! empty($filters['mosque_id']), fn ($q) => $q->where('mosque_id', $filters['mosque_id']))
            ->when(! empty($filters['dormitory_id']), fn ($q) => $q->where('dormitory_id', $filters['dormitory_id']))
            ->when(! empty($filters['grade_level']), fn ($q) => $q->where('grade_level', $filters['grade_level']))
            ->when(! empty($filters['class_id']), fn ($q) => $q->where('class_id', $filters['class_id']))
            ->when(! empty($filters['prayer_type']), fn ($q) => $q->where('prayer_type', strtoupper((string) $filters['prayer_type'])))
            ->when(! empty($filters['participant_type']), fn ($q) => $q->where('participant_type', strtoupper((string) $filters['participant_type'])));

        $rows = (clone $query)->get();
        $total = $rows->count();

        $counts = [];
        foreach (self::STATUSES as $status) {
            $counts[strtolower($status)] = $rows->where('status', $status)->count();
        }

        $byPrayer = [];
        foreach ($rows->groupBy('prayer_type') as $prayerType => $group) {
            $byPrayer[$prayerType] = [
                'total' => $group->count(),
                'present' => $group->where('status', 'PRESENT')->count(),
                'late' => $group->where('status', 'LATE')->count(),
                'absent' => $group->where('status', 'ABSENT')->count(),
                'excused' => $group->where('status', 'EXCUSED')->count(),
            ];
        }

        $attended = $counts['present'] + $counts['late'];

        return array_merge($counts, [
            'date' => $date,
            'total' => $total,
            'by_prayer' => $byPrayer,
            'attendance_rate' => $total > 0 ? round(($attended / $total) * 100, 2) : null,
            'late_rate' => $total > 0 ? round(($counts['late'] / $total) * 100, 2) : null,
            'absence_rate' => $total > 0 ? round((($counts['absent'] + $counts['authorized_absence']) / $total) * 100, 2) : null,
        ]);
    }

    /** Dashboard presensi lengkap dengan pemecahan per shalat (§70). */
    public function dashboard(string $date, array $filters = []): array
    {
        $stats = $this->statistics($date, $filters);

        return [
            'date' => $date,
            'filters' => $filters,
            'summary' => $stats,
            'by_prayer' => $stats['by_prayer'],
            'by_dormitory' => $this->breakdown($date, 'dormitory_id', $filters),
            'by_grade' => $this->breakdown($date, 'grade_level', $filters),
            'provider' => $this->attendance->capabilities(),
            'note' => 'Angka ini adalah fakta presensi, bukan penilaian karakter atau kualitas moral siswa (§71).',
        ];
    }

    protected function breakdown(string $date, string $column, array $filters = []): array
    {
        return $this->context->safe(function () use ($date, $column, $filters) {
            return PrayerAttendanceRecord::query()->whereDate('attendance_date', $date)
                ->when(! empty($filters['campus_id']), fn ($q) => $q->where('campus_id', $filters['campus_id']))
                ->selectRaw($column.', status, count(*) as total')
                ->groupBy($column, 'status')->get()
                ->groupBy($column)->map(fn ($rows): array => [
                    'total' => (int) $rows->sum('total'),
                    'present' => (int) $rows->where('status', 'PRESENT')->sum('total'),
                    'late' => (int) $rows->where('status', 'LATE')->sum('total'),
                    'absent' => (int) $rows->where('status', 'ABSENT')->sum('total'),
                    'excused' => (int) $rows->where('status', 'EXCUSED')->sum('total'),
                ])->all();
        }, []);
    }

    /**
     * Teruskan FAKTA presensi ke alur kedisiplinan — hanya bila institusi
     * mengaktifkannya (Aturan 12 / §26).
     */
    public function forwardToDiscipline(string $from, string $to, array $filters = []): array
    {
        if (! $this->discipline->isEnabled()) {
            return [
                'forwarded' => false,
                'reason' => 'Aturan 12: presensi keagamaan tidak otomatis menjadi sanksi disiplin. '
                    .'Aktifkan `religious.integrations.discipline_enabled` bila institusi menghendaki.',
            ];
        }

        $facts = PrayerAttendanceRecord::query()
            ->whereDate('attendance_date', '>=', $from)->whereDate('attendance_date', '<=', $to)
            ->when(! empty($filters['student_id']), fn ($q) => $q->where('student_id', $filters['student_id']))
            ->whereIn('status', ['LATE', 'ABSENT', 'AUTHORIZED_ABSENCE'])
            ->limit(500)->get()
            ->map(fn (PrayerAttendanceRecord $r): array => [
                'student_id' => $r->student_id,
                'date' => (string) $r->attendance_date,
                'prayer_type' => $r->prayer_type,
                'status' => $r->status,
            ])->all();

        return $this->discipline->forwardAttendanceFacts([
            'source' => 'RELIGIOUS_PRAYER_ATTENDANCE',
            'from' => $from,
            'to' => $to,
            'facts' => $facts,
        ]);
    }
}
