<?php

namespace App\Services\Tahfiz;

use App\Models\Employee;
use App\Models\TahfizAttendance;
use App\Models\TahfizEnrollment;
use App\Models\TahfizExam;
use App\Models\TahfizExamParticipant;
use App\Models\TahfizHalaqah;
use App\Models\TahfizIntervention;
use App\Models\TahfizMemorizationRecord;
use App\Models\TahfizMistakeType;
use App\Models\TahfizMutabaah;
use App\Models\TahfizParticipant;
use App\Models\TahfizProgram;
use App\Models\TahfizRevisionRecord;
use App\Models\TahfizTahsinRecord;
use App\Models\TahfizTasmiRecord;
use App\Models\TahfizTilawahRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Laporan & rapor Tahfiz.
 *
 * Laporan menghitung ulang dari data mentah (setoran, kehadiran, ujian) —
 * tidak menyimpan ringkasan yang bisa menjadi usang. Rapor adalah laporan
 * formal per peserta per periode, dipakai untuk rapor sekolah/madrasah.
 */
class ReportService
{
    public function __construct(protected ProgressService $progress)
    {
    }

    // ------------------------------------------------------------------
    // Laporan individual
    // ------------------------------------------------------------------

    public function individual(int $participantId, ?string $from = null, ?string $to = null): array
    {
        $participant = TahfizParticipant::query()->with(['enrollments.program', 'enrollments.halaqah'])
            ->find($participantId);

        if ($participant === null) {
            return ['exists' => false];
        }

        $enrollment = $participant->enrollments()
            ->whereIn('status', [TahfizEnrollment::STATUS_ACTIVE, TahfizEnrollment::STATUS_COMPLETED])
            ->latest('id')
            ->first();

        $memorization = $this->rangedQuery(TahfizMemorizationRecord::query()->where('participant_id', $participantId), $from, $to);
        $revisions = $this->rangedQuery(TahfizRevisionRecord::query()->where('participant_id', $participantId), $from, $to);
        $tilawah = $this->rangedQuery(TahfizTilawahRecord::query()->where('participant_id', $participantId), $from, $to);
        $tasmi = $this->rangedQuery(TahfizTasmiRecord::query()->where('participant_id', $participantId), $from, $to);
        $tahsin = $this->rangedQuery(TahfizTahsinRecord::query()->where('participant_id', $participantId), $from, $to);

        $attendanceRows = $this->rangedQuery(
            TahfizAttendance::query()->where('participant_id', $participantId),
            $from,
            $to
        )->get();

        $totalSessions = $attendanceRows->count();
        $presentSessions = $attendanceRows->whereNotNull('arrival_time')->count();

        return [
            'participant' => [
                'id' => $participant->id,
                'name' => $participant->name,
                'participant_type' => $participant->participant_type,
                'status' => $participant->status,
            ],
            'enrollment' => $enrollment !== null ? [
                'id' => $enrollment->id,
                'code' => $enrollment->code,
                'status' => $enrollment->status,
                'enrolled_at' => $enrollment->enrolled_at?->toDateString(),
                'program' => $enrollment->program?->only(['id', 'name', 'code']),
                'halaqah' => $enrollment->halaqah?->only(['id', 'name', 'code']),
            ] : null,
            'period' => ['from' => $from, 'to' => $to],
            'progress' => $this->progress->memorizationMap($participantId),
            'setoran' => $this->setoranStats($memorization),
            'mistake_patterns' => $this->mistakePatterns($memorization),
            'murojaah' => $this->revisionStats($revisions),
            'tilawah' => $this->countAndSum($tilawah),
            'tasmi' => $this->countAndSum($tasmi),
            'tahsin' => $this->tahsinStats($tahsin),
            'attendance' => [
                'total_sessions' => $totalSessions,
                'present' => $presentSessions,
                'percent' => $totalSessions > 0 ? round(($presentSessions / $totalSessions) * 100, 1) : 0.0,
            ],
            'exams' => $this->examResults($participantId),
            'interventions' => TahfizIntervention::query()
                ->where('participant_id', $participantId)
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
        ];
    }

    // ------------------------------------------------------------------
    // Laporan halaqah
    // ------------------------------------------------------------------

    public function halaqah(int $halaqahId, ?string $from = null, ?string $to = null): array
    {
        $halaqah = TahfizHalaqah::query()->with(['program', 'level', 'instructor', 'musyrif'])->find($halaqahId);

        if ($halaqah === null) {
            return ['exists' => false];
        }

        $enrollments = $halaqah->activeMembers()->with('participant')->get();
        $participantIds = $enrollments->pluck('participant_id')->all();

        $setoran = $this->rangedQuery(
            TahfizMemorizationRecord::query()->whereIn('participant_id', $participantIds),
            $from,
            $to
        );

        return [
            'halaqah' => $halaqah->only(['id', 'name', 'code', 'capacity', 'status'])
                + [
                    'program' => $halaqah->program?->only(['id', 'name']),
                    'level' => $halaqah->level?->only(['id', 'name']),
                    'instructor_name' => $halaqah->instructor?->full_name,
                    'musyrif_name' => $halaqah->musyrif?->full_name,
                ],
            'period' => ['from' => $from, 'to' => $to],
            'members_count' => count($participantIds),
            'setoran' => $this->setoranStats($setoran),
            'participants' => $enrollments->map(fn ($e) => [
                'enrollment_id' => $e->id,
                'participant_id' => $e->participant_id,
                'name' => $e->participant?->name,
                'status' => $e->status,
                'progress' => $this->progressSummary($e->participant_id),
            ]),
        ];
    }

    // ------------------------------------------------------------------
    // Laporan program
    // ------------------------------------------------------------------

    public function program(int $programId, ?string $from = null, ?string $to = null): array
    {
        $program = TahfizProgram::query()->withCount(['enrollments', 'halaqahs', 'levels'])->find($programId);

        if ($program === null) {
            return ['exists' => false];
        }

        $enrollmentBreakdown = TahfizEnrollment::query()
            ->where('program_id', $programId)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $participantIds = TahfizEnrollment::query()
            ->where('program_id', $programId)
            ->pluck('participant_id')
            ->unique()
            ->all();

        $setoran = $this->rangedQuery(
            TahfizMemorizationRecord::query()->whereIn('participant_id', $participantIds),
            $from,
            $to
        );

        $halaqahs = $program->halaqahs()->active()->withCount('activeMembers')->get();

        return [
            'program' => $program->only(['id', 'name', 'code', 'status', 'participant_type', 'target_type']),
            'period' => ['from' => $from, 'to' => $to],
            'counts' => [
                'enrollments' => $program->enrollments_count,
                'halaqahs' => $program->halaqahs_count,
                'levels' => $program->levels_count,
            ],
            'enrollment_breakdown' => $enrollmentBreakdown,
            'halaqahs' => $halaqahs->map(fn ($h) => [
                'id' => $h->id,
                'name' => $h->name,
                'code' => $h->code,
                'active_members' => $h->active_members_count,
                'capacity' => $h->capacity,
            ]),
            'setoran' => $this->setoranStats($setoran),
            'avg_progress' => $this->groupProgress($participantIds),
        ];
    }

    // ------------------------------------------------------------------
    // Laporan guru/pegawai
    // ------------------------------------------------------------------

    public function teacher(int $employeeId, ?string $from = null, ?string $to = null): array
    {
        $employee = Employee::query()->find($employeeId);

        if ($employee === null) {
            return ['exists' => false];
        }

        $halaqahs = TahfizHalaqah::query()
            ->where(fn (Builder $q) => $q->where('instructor_id', $employeeId)->orWhere('musyrif_id', $employeeId))
            ->with(['program', 'level'])
            ->get();

        $halaqahIds = $halaqahs->pluck('id')->all();
        $participantIds = $halaqahIds === []
            ? []
            : TahfizEnrollment::query()
                ->whereIn('halaqah_id', $halaqahIds)
                ->pluck('participant_id')
                ->unique()
                ->all();

        $setoran = $this->rangedQuery(
            TahfizMemorizationRecord::query()->whereIn('participant_id', $participantIds),
            $from,
            $to
        );

        return [
            'teacher' => [
                'id' => $employee->id,
                'name' => $employee->full_name,
            ],
            'period' => ['from' => $from, 'to' => $to],
            'halaqahs' => $halaqahs->map(fn ($h) => [
                'id' => $h->id,
                'name' => $h->name,
                'code' => $h->code,
                'role' => $h->instructor_id === $employeeId ? 'instructor' : 'musyrif',
                'active_members' => $h->activeMembersCount(),
            ]),
            'participants_count' => count($participantIds),
            'setoran_reviewed' => $this->rangedQuery(
                TahfizMemorizationRecord::query()
                    ->whereIn('participant_id', $participantIds)
                    ->where('reviewed_by', $employee->user_id),
                $from,
                $to
            )->count(),
            'setoran' => $this->setoranStats($setoran),
            'avg_progress' => $this->groupProgress($participantIds),
        ];
    }

    // ------------------------------------------------------------------
    // Rapor Tahfiz
    // ------------------------------------------------------------------

    /**
     * Rapor formal per peserta per periode.
     *
     * Berbeda dari laporan individual: rapor adalah dokumen penilaian
     * berkala (nilai, mutqin, kehadiran, capaian target, catatan), bukan
     * audit data lengkap.
     */
    public function rapor(int $participantId, ?string $from = null, ?string $to = null): array
    {
        $individual = $this->individual($participantId, $from, $to);

        if (($individual['participant'] ?? null) === null) {
            return ['exists' => false];
        }

        $map = $individual['progress'];
        $attendance = $individual['attendance'];
        $setoran = $individual['setoran'];
        $target = $this->progress->targetProgress($participantId, $this->activeEnrollmentId($participantId));

        $mutabaahCount = $this->rangedQuery(
            TahfizMutabaah::query()->where('participant_id', $participantId),
            $from,
            $to
        )->count();

        return [
            'participant' => $individual['participant'],
            'enrollment' => $individual['enrollment'],
            'period' => ['from' => $from, 'to' => $to],
            'hafalan' => [
                'ayahs_memorized' => $map['ayahs_memorized'] ?? 0,
                'ayahs_mutqin' => $map['ayahs_mutqin'] ?? 0,
                'memorized_percent' => $map['memorized_percent'] ?? 0.0,
                'mutqin_percent' => $map['mutqin_percent'] ?? 0.0,
                // `juz_memorized` = juz yang seluruh surah awalnya telah
                // dihafal lengkap; `juz` hanya rekap juz yang disentuh.
                'juz_completed' => $map['juz_memorized'] ?? 0,
                'surahs_memorized' => $map['surahs_memorized'] ?? 0,
            ],
            'setoran' => [
                'total' => $setoran['total'] ?? 0,
                'accepted' => $setoran['accepted'] ?? 0,
                'avg_score' => $setoran['avg_score'] ?? null,
            ],
            'target' => [
                'value' => $target['target']['value'] ?? null,
                'period' => $target['target']['period'] ?? null,
                'achievement_percent' => $target['achievement_percent'] ?? null,
                'is_achieved' => $target['is_achieved'] ?? false,
            ],
            'attendance' => $attendance,
            'mutabaah_days' => $mutabaahCount,
            'murojaah' => $individual['murojaah'],
            'exams' => $individual['exams'],
            'interventions' => $individual['interventions'],
            'notes' => $this->raporNotes($map, $attendance, $target),
        ];
    }

    /**
     * Catatan ringkas rapor — pembobotan, bukan aturan tetap.
     */
    protected function raporNotes(array $map, array $attendance, array $target): array
    {
        $mutqinPercent = (float) ($map['mutqin_percent'] ?? 0);
        $memorizedPercent = (float) ($map['memorized_percent'] ?? 0);
        $attendancePercent = (float) ($attendance['percent'] ?? 0);

        $predicate = $mutqinPercent >= 90 ? 'sangat_baik'
            : ($mutqinPercent >= 75 ? 'baik'
            : ($mutqinPercent >= 50 ? 'cukup' : 'perlu_bimbingan'));

        return [
            'predicate' => $predicate,
            'predicate_label' => [
                'sangat_baik' => 'Sangat Baik',
                'baik' => 'Baik',
                'cukup' => 'Cukup',
                'perlu_bimbingan' => 'Perlu Bimbingan',
            ][$predicate],
            'strengths' => array_values(array_filter([
                $mutqinPercent >= 75 ? 'Hafalan sudah mutqin pada sebagian besar ayat yang dihafal.' : null,
                $attendancePercent >= 90 ? 'Kehadiran sangat baik.' : null,
                ($target['is_achieved'] ?? false) ? 'Mencapai target periode berjalan.' : null,
            ])),
            'improvements' => array_values(array_filter([
                $mutqinPercent < 50 ? 'Perlu memperbanyak murojaah agar hafalan menjadi mutqin.' : null,
                $memorizedPercent < 50 ? 'Progress hafalan masih kurang dari setengah target.' : null,
                $attendancePercent < 75 ? 'Kehadiran perlu ditingkatkan.' : null,
            ])),
        ];
    }

    // ------------------------------------------------------------------
    // Pembantu agregasi
    // ------------------------------------------------------------------

    protected function rangedQuery(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from !== null) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to !== null) {
            $query->whereDate('date', '<=', $to);
        }

        return $query;
    }

    protected function setoranStats(Builder $query): array
    {
        $rows = (clone $query)->get();

        $scored = $rows->filter(fn ($r) => $r->score !== null);

        return [
            'total' => $rows->count(),
            'accepted' => $rows->whereIn('status', TahfizMemorizationRecord::COUNTED_STATUSES)->count(),
            'pending' => $rows->filter(fn ($r) => in_array($r->status, [TahfizMemorizationRecord::STATUS_SUBMITTED, TahfizMemorizationRecord::STATUS_REVIEWED], true))->count(),
            'rejected' => $rows->where('status', TahfizMemorizationRecord::STATUS_REJECTED)->count(),
            'avg_score' => $scored->isNotEmpty() ? round((float) $scored->avg('score'), 2) : null,
            'ayahs' => (int) $rows->whereIn('status', TahfizMemorizationRecord::COUNTED_STATUSES)
                ->sum(fn ($r) => max(0, (int) $r->ayah_end - (int) $r->ayah_start + 1)),
        ];
    }

    protected function mistakePatterns(Builder $query): array
    {
        $rows = (clone $query)
            ->with('mistakes')
            ->get()
            ->flatMap(fn ($r) => $r->mistakes);

        if ($rows->isEmpty()) {
            return [];
        }

        $grouped = $rows->groupBy(fn ($m) => $m->mistake_type_id ?? $m->type_label ?? 'lainnya');

        return $grouped->map(fn ($items, $key) => [
            'key' => $key,
            'label' => $items->first()->type_label
                ?? TahfizMistakeType::query()->whereKey($key)->value('label')
                ?? (string) $key,
            'count' => (int) $items->sum('count'),
            'occurrences' => $items->count(),
        ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    protected function revisionStats(Builder $query): array
    {
        $rows = (clone $query)->get();
        $scored = $rows->filter(fn ($r) => $r->score !== null);

        return [
            'total' => $rows->count(),
            'avg_score' => $scored->isNotEmpty() ? round((float) $scored->avg('score'), 2) : null,
        ];
    }

    protected function countAndSum(Builder $query): array
    {
        $rows = (clone $query)->get();
        $scored = $rows->filter(fn ($r) => $r->score !== null);

        return [
            'total' => $rows->count(),
            'avg_score' => $scored->isNotEmpty() ? round((float) $scored->avg('score'), 2) : null,
        ];
    }

    protected function tahsinStats(Builder $query): array
    {
        $rows = (clone $query)->get();
        $scored = $rows->filter(fn ($r) => $r->overall_score !== null);

        return [
            'total' => $rows->count(),
            'avg_score' => $scored->isNotEmpty() ? round((float) $scored->avg('overall_score'), 2) : null,
        ];
    }

    protected function examResults(int $participantId)
    {
        return TahfizExamParticipant::query()
            ->where('participant_id', $participantId)
            ->with(['exam', 'items.surah'])
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    protected function progressSummary(int $participantId): array
    {
        $map = $this->progress->memorizationMap($participantId);

        return [
            'ayahs_memorized' => $map['ayahs_memorized'] ?? 0,
            'ayahs_mutqin' => $map['ayahs_mutqin'] ?? 0,
            'memorized_percent' => $map['memorized_percent'] ?? 0.0,
            'mutqin_percent' => $map['mutqin_percent'] ?? 0.0,
        ];
    }

    protected function groupProgress(array $participantIds): array
    {
        if ($participantIds === []) {
            return ['avg_memorized_percent' => 0.0, 'avg_mutqin_percent' => 0.0];
        }

        $rows = DB::table('tahfiz_participant_progress')
            ->whereIn('participant_id', $participantIds)
            ->selectRaw('
                AVG(CASE WHEN total_ayahs > 0 THEN (ayahs_memorized * 100.0) / total_ayahs ELSE 0 END) as avg_memorized,
                AVG(CASE WHEN total_ayahs > 0 THEN (ayahs_mutqin * 100.0) / total_ayahs ELSE 0 END) as avg_mutqin
            ')
            ->first();

        return [
            'avg_memorized_percent' => round((float) ($rows->avg_memorized ?? 0), 1),
            'avg_mutqin_percent' => round((float) ($rows->avg_mutqin ?? 0), 1),
        ];
    }

    protected function activeEnrollmentId(int $participantId): ?int
    {
        return (int) (TahfizEnrollment::query()
            ->where('participant_id', $participantId)
            ->whereIn('status', [TahfizEnrollment::STATUS_ACTIVE, TahfizEnrollment::STATUS_APPROVED])
            ->latest('id')
            ->value('id') ?? 0) ?: null;
    }
}
