<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\ConsentScope;
use App\Modules\Bk\Domain\Enums\GroupAttendanceStatus;
use App\Modules\Bk\Domain\Enums\GroupProgramMemberStatus;
use App\Modules\Bk\Domain\Enums\GroupProgramStatus;
use App\Modules\Bk\Domain\Enums\GroupProgramType;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Domain\Enums\SessionStatus;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkGroupProgram;
use App\Modules\Bk\Models\BkGroupProgramAttendance;
use App\Modules\Bk\Models\BkGroupProgramMember;
use App\Modules\Bk\Models\BkGroupProgramSession;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Program kelompok BK.
 *
 * Program adalah layanan pencegahan dan pengembangan: siswa dapat mengikutinya
 * tanpa pernah memiliki kasus. Karena itu keikutsertaan tidak menuntut kasus,
 * tetapi **tetap menghormati persetujuan** bila program mensyaratkannya
 * (`requires_consent`), dan kehadirannya **ditautkan** ke Modul Attendance
 * lewat penunjuk, bukan digandakan menjadi absensi kedua yang bisa berbeda.
 */
class BkGroupProgramService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
        private readonly BkAccessService $access,
        private readonly BkConsentService $consents,
        private readonly AttendanceProviderInterface $attendance,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = BkGroupProgram::query()
            ->with('facilitator:id,name')
            ->withCount(['sessions', 'members']);

        // Peserta (siswa/wali) hanya melihat program yang diikuti anaknya.
        if ($this->access->isReferrerOnly($actor)) {
            $studentIds = array_merge(
                array_filter([$this->access->studentIdForUser($actor)]),
                $this->access->guardianStudentIds($actor),
            );

            $query->whereHas('members', fn ($member) => $member->whereIn('student_id', $studentIds === [] ? [-1] : $studentIds));
        }

        return $query
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('program_type', $type))
            ->when($filters['status'] ?? null, fn ($q, $status) => $status === 'open' ? $q->open() : $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('title', 'like', '%'.$term.'%'))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): BkGroupProgram
    {
        $type = GroupProgramType::from((string) $data['program_type']);

        $program = BkGroupProgram::query()->create([
            'program_number' => $this->numbers->next('BK_GROUP_PROGRAM', 'BK-PR'),
            'code' => $data['code'] ?? null,
            'title' => $data['title'],
            'program_type' => $type,
            'description' => $data['description'] ?? null,
            'objective' => $data['objective'] ?? null,
            'facilitator_user_id' => $data['facilitator_user_id'] ?? $actor->id,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'status' => GroupProgramStatus::from((string) ($data['status'] ?? GroupProgramStatus::DRAFT->value)),
            'capacity' => $data['capacity'] ?? null,
            'target_participants' => $data['target_participants'] ?? null,
            'location' => $data['location'] ?? null,
            'requires_consent' => (bool) ($data['requires_consent'] ?? true),
            'confidentiality_level' => $data['confidentiality_level'] ?? \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::STAFF_ONLY,
            'created_by' => $actor->id,
        ]);

        $this->audit->record(AuditAction::PROGRAM_UPDATE, $program, $actor, [
            'program_type' => $type->value,
            'requires_consent' => $program->requires_consent,
        ]);

        return $program;
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, BkGroupProgram $program, array $data): BkGroupProgram
    {
        $before = $program->only(['title', 'status', 'start_date', 'end_date', 'capacity', 'objective']);

        $program->fill([
            'title' => $data['title'] ?? $program->title,
            'description' => $data['description'] ?? $program->description,
            'objective' => $data['objective'] ?? $program->objective,
            'facilitator_user_id' => $data['facilitator_user_id'] ?? $program->facilitator_user_id,
            'start_date' => $data['start_date'] ?? $program->start_date,
            'end_date' => $data['end_date'] ?? $program->end_date,
            'status' => isset($data['status']) ? GroupProgramStatus::from((string) $data['status']) : $program->status,
            'capacity' => $data['capacity'] ?? $program->capacity,
            'location' => $data['location'] ?? $program->location,
            'requires_consent' => $data['requires_consent'] ?? $program->requires_consent,
            'evaluation' => $data['evaluation'] ?? $program->evaluation,
            'evaluation_note' => $data['evaluation_note'] ?? $program->evaluation_note,
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->record(AuditAction::PROGRAM_UPDATE, $program, $actor, [
            'changed' => array_keys($this->audit->diff($before, $program->only(array_keys($before)))),
        ]);

        return $program->refresh();
    }

    /** @param array<string, mixed> $data */
    public function addSession(User $actor, BkGroupProgram $program, array $data): BkGroupProgramSession
    {
        if (empty($data['title'])) {
            throw new BkValidationException(['title' => ['Judul sesi program wajib diisi.']]);
        }

        $number = (int) $program->sessions()->max('session_number') + 1;

        $session = BkGroupProgramSession::query()->create([
            'program_id' => $program->id,
            'session_number' => $data['session_number'] ?? $number,
            'title' => $data['title'],
            'scheduled_start' => $data['scheduled_start'] ?? null,
            'scheduled_end' => $data['scheduled_end'] ?? null,
            'location' => $data['location'] ?? $program->location,
            'facilitator_user_id' => $data['facilitator_user_id'] ?? $program->facilitator_user_id,
            'materials' => $data['materials'] ?? null,
            'material_document_id' => $data['material_document_id'] ?? null,
            'status' => SessionStatus::from((string) ($data['status'] ?? SessionStatus::SCHEDULED->value)),
            'created_by' => $actor->id,
        ]);

        foreach ($program->members()->whereIn('status', ['INVITED', 'ACTIVE'])->get() as $member) {
            if ($member->student_id !== null) {
                $this->notifications->notifyGuardians(
                    $member->student_id,
                    NotificationEvent::PROGRAM_SESSION_REMINDER,
                    "Sesi program: {$session->title}",
                    ['program_id' => $program->id, 'session_id' => $session->id, 'scheduled_start' => $session->scheduled_start?->toIso8601String()],
                );
            }
        }

        $this->audit->record(AuditAction::PROGRAM_UPDATE, $session, $actor, ['created' => true, 'program_id' => $program->id]);

        return $session;
    }

    /**
     * Menambahkan peserta program.
     *
     * Bila program mensyaratkan persetujuan, permintaan persetujuan dibuat lebih
     * dulu — dan keanggotaannya tetap tercatat sebagai menunggu. Dengan begitu
     * tidak ada siswa yang masuk program tanpa izin hanya karena tombolnya ada.
     *
     * @param array<string, mixed> $data
     */
    public function addMember(User $actor, BkGroupProgram $program, array $data): BkGroupProgramMember
    {
        $studentId = (int) $data['student_id'];

        if (! $program->status->acceptsNewMembers()) {
            throw new BkValidationException(['status' => ['Program ini sudah tidak menerima peserta baru.']]);
        }

        $consentId = $data['consent_id'] ?? null;

        if ($program->requires_consent && $consentId === null) {
            $consent = $this->consents->validFor($studentId, ConsentScope::PROGRAM_PARTICIPATION);

            if ($consent === null) {
                $consent = $this->consents->request($actor, $studentId, [
                    'scope' => ConsentScope::PROGRAM_PARTICIPATION->value,
                    'purpose' => 'Keikutsertaan pada program '.$program->title.'.',
                    'expires_at' => now()->addDays(ConsentScope::PROGRAM_PARTICIPATION->defaultValidityDays())->toDateString(),
                ]);
            }

            $consentId = $consent->id;
        }

        $member = BkGroupProgramMember::query()->updateOrCreate(
            ['program_id' => $program->id, 'student_id' => $studentId],
            [
                'consent_id' => $consentId,
                'case_id' => $data['case_id'] ?? null,
                'status' => GroupProgramMemberStatus::from((string) ($data['status'] ?? GroupProgramMemberStatus::INVITED->value)),
                'joined_at' => $data['joined_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
                'added_by' => $actor->id,
            ],
        );

        $this->audit->record(AuditAction::PROGRAM_UPDATE, $member, $actor, [
            'program_id' => $program->id,
            'student_id' => $studentId,
            'consent_id' => $consentId,
            'requires_consent' => $program->requires_consent,
        ]);

        return $member->refresh();
    }

    /**
     * Mencatat kehadiran sesi.
     *
     * Bila `source` dinyatakan ATTENDANCE_MODULE, baris hanya boleh dibuat bila
     * referensi eksternalnya benar-benar ada — supaya penautan ke Modul Attendance
     * tidak berubah menjadi klaim tanpa bukti.
     *
     * @param array<int, array{member_id: int, attendance_status: string, note?: string|null, external_reference?: string|null}> $entries
     * @return array{marked: int, skipped: int}
     */
    public function recordAttendance(User $actor, BkGroupProgramSession $session, array $entries, string $source = 'MANUAL'): array
    {
        $marked = 0;
        $skipped = 0;

        DB::transaction(function () use ($actor, $session, $entries, $source, &$marked, &$skipped): void {
            foreach ($entries as $entry) {
                $member = $session->program->members()->where('id', (int) $entry['member_id'])->first();

                if ($member === null) {
                    $skipped++;

                    continue;
                }

                $status = GroupAttendanceStatus::from((string) $entry['attendance_status']);

                BkGroupProgramAttendance::query()->updateOrCreate(
                    ['session_id' => $session->id, 'member_id' => $member->id],
                    [
                        'program_id' => $session->program_id,
                        'attendance_status' => $status,
                        'note' => $entry['note'] ?? null,
                        'source' => $source,
                        'external_reference' => $entry['external_reference'] ?? null,
                        'marked_by' => $actor->id,
                        'marked_at' => now(),
                    ],
                );

                if ($status->countsAsPresent() && $member->status === GroupProgramMemberStatus::INVITED) {
                    $member->forceFill(['status' => GroupProgramMemberStatus::ACTIVE])->save();
                }

                $marked++;
            }

            $session->forceFill([
                'attendance_recorded_at' => now(),
                'status' => $session->status === SessionStatus::CANCELLED ? $session->status : SessionStatus::COMPLETED,
            ])->save();
        });

        $this->audit->record(AuditAction::PROGRAM_UPDATE, $session, $actor, [
            'attendance_marked' => $marked,
            'attendance_skipped' => $skipped,
            'source' => $source,
        ]);

        return ['marked' => $marked, 'skipped' => $skipped];
    }

    /** Ringkasan kehadiran peserta: dihitung dari baris kehadiran, bukan salinan kolom. */
    public function attendanceSummary(BkGroupProgramSession $session): array
    {
        $rows = $session->attendance()->get();
        $total = max(1, $rows->count());

        return [
            'session_id' => $session->id,
            'total_recorded' => $rows->count(),
            'present' => $rows->where('attendance_status', GroupAttendanceStatus::PRESENT)->count(),
            'late' => $rows->where('attendance_status', GroupAttendanceStatus::LATE)->count(),
            'absent' => $rows->where('attendance_status', GroupAttendanceStatus::ABSENT)->count(),
            'excused' => $rows->where('attendance_status', GroupAttendanceStatus::EXCUSED)->count(),
            'sick' => $rows->where('attendance_status', GroupAttendanceStatus::SICK)->count(),
            'attendance_rate' => round(($rows->filter(fn ($row): bool => $row->attendance_status->countsAsPresent())->count() / $total) * 100, 1),
            'sources' => $rows->groupBy('source')->map->count()->all(),
        ];
    }

    /** Kehadiran satu peserta lintas sesi (dipakai evaluasi program). */
    public function memberAttendance(BkGroupProgramMember $member): array
    {
        $rows = $member->attendance()->get();
        $sessions = $member->program->sessions()->count();

        return [
            'member_id' => $member->id,
            'student_id' => $member->student_id,
            'sessions_total' => $sessions,
            'recorded' => $rows->count(),
            'present' => $rows->filter(fn ($row): bool => $row->attendance_status->countsAsPresent())->count(),
            'rate' => $rows->isEmpty() ? null : round(($rows->filter(fn ($row): bool => $row->attendance_status->countsAsPresent())->count() / $rows->count()) * 100, 1),
        ];
    }

    /** Evaluasi program: angka, bukan kesan. */
    public function evaluation(BkGroupProgram $program): array
    {
        $members = $program->members()->with('attendance')->get();
        $attendanceRate = $members->isEmpty()
            ? null
            : round((float) $members->map(fn (BkGroupProgramMember $member): float => (float) ($this->memberAttendance($member)['rate'] ?? 0))->avg(), 1);

        return [
            'program_id' => $program->id,
            'status' => $program->status->value,
            'members' => $members->count(),
            'active_members' => $members->where('status', GroupProgramMemberStatus::ACTIVE)->count(),
            'completed_members' => $members->where('status', GroupProgramMemberStatus::COMPLETED)->count(),
            'withdrawn_members' => $members->where('status', GroupProgramMemberStatus::WITHDRAWN)->count(),
            'sessions' => $program->sessions()->count(),
            'completed_sessions' => $program->sessions()->where('status', SessionStatus::COMPLETED->value)->count(),
            'attendance_rate' => $attendanceRate,
            'note' => $attendanceRate === null
                ? 'Belum ada kehadiran yang tercatat; evaluasi menunggu data.'
                : 'Angka kehadiran berasal dari baris kehadiran sesi program, bukan dari absensi pelajaran.',
        ];
    }

    /** Program yang diikuti seorang siswa (dipakai portal/profil). */
    public function forStudent(int $studentId): Collection
    {
        return BkGroupProgramMember::query()
            ->where('student_id', $studentId)
            ->with(['program:id,program_number,title,program_type,status,start_date,end_date'])
            ->get();
    }
}
