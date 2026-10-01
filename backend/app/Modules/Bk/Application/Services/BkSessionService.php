<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Enums\AttendanceStatus;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\CaseEventType;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Domain\Enums\SessionMode;
use App\Modules\Bk\Domain\Enums\SessionStatus;
use App\Modules\Bk\Domain\Enums\SessionType;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkAppointment;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCounselingSession;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sesi konseling.
 *
 * Pemisahan terpenting modul BK ada di sini: `session_summary` adalah ringkasan
 * yang dapat dibagikan sesuai tingkat kerahasiaan, sedangkan `counselor_note`
 * adalah catatan pribadi konselor yang **hanya** dapat ditulis dan dibaca
 * pemegang akses konseling. Guru, siswa, dan orang tua tidak pernah menerimanya —
 * bukan karena disaring di tampilan, melainkan karena tidak pernah dikirim.
 */
class BkSessionService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkTimelineService $timeline,
        private readonly BkAuditService $audit,
        private readonly BkAccessService $access,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = BkCounselingSession::query()->with(['student:id,full_name,nis', 'counselor:id,name', 'case:id,case_number']);

        if (! $this->access->isBkStaff($actor)) {
            $query->whereHas('case', fn ($q) => $q->whereIn('student_id', $this->access->visibleStudentIds($actor)));
        } elseif (! $this->access->canMonitorWorkload($actor)) {
            $query->where('counselor_id', $actor->id);
        }

        return $query
            ->when($filters['case_id'] ?? null, fn ($q, $caseId) => $q->where('case_id', $caseId))
            ->when($filters['counselor_id'] ?? null, fn ($q, $id) => $q->where('counselor_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['session_type'] ?? null, fn ($q, $type) => $q->where('session_type', $type))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('scheduled_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('scheduled_at', '<=', $to))
            ->orderByDesc('scheduled_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @return Collection<int, BkCounselingSession> */
    public function forCase(BkCase $case): Collection
    {
        return $case->sessions()->with(['counselor:id,name'])->orderByDesc('scheduled_at')->get();
    }

    /**
     * Jadwalkan sesi konseling (dari janji temu atau langsung).
     *
     * @param  array<string, mixed>  $data
     */
    public function schedule(User $actor, BkCase $case, array $data): BkCounselingSession
    {
        $this->access->assertCanViewCase($actor, $case, 'Menjadwalkan sesi konseling');

        if (! $this->access->canViewCounselingContent($actor, $case) && ! $this->access->isCoordinator($actor)) {
            throw new BkValidationException(['session' => ['Hanya konselor penanggung jawab yang dapat menjadwalkan sesi konseling.']]);
        }

        $appointment = isset($data['appointment_id']) ? BkAppointment::query()->find($data['appointment_id']) : null;

        return DB::transaction(function () use ($actor, $case, $data, $appointment): BkCounselingSession {
            $session = BkCounselingSession::query()->create([
                'session_number' => $this->numbers->next('BK_SESSION', 'BK-SES'),
                'case_id' => $case->id,
                'student_id' => $case->student_id,
                'counselor_id' => $data['counselor_id'] ?? $appointment?->counselor_id ?? $case->assigned_counselor_id ?? $actor->id,
                'appointment_id' => $appointment?->id,
                'session_type' => $data['session_type'] ?? SessionType::INDIVIDUAL->value,
                'mode' => $data['mode'] ?? $appointment?->mode->value ?? SessionMode::IN_PERSON->value,
                'scheduled_at' => $data['scheduled_at'] ?? $appointment?->scheduled_start,
                'location' => $data['location'] ?? $appointment?->location,
                'status' => SessionStatus::SCHEDULED->value,
                'objective' => $data['objective'] ?? null,
                'confidentiality_level' => $data['confidentiality_level'] ?? $case->confidentiality_level->value,
                'created_by' => $actor->id,
            ]);

            $this->timeline->record(CaseEventType::SESSION_SCHEDULED, $actor, $case,
                summary: $session->session_type->label().' dijadwalkan.', related: $session,
                payload: ['session_number' => $session->session_number]);

            $this->audit->record(AuditAction::CREATE, $session, $actor);

            return $session->refresh();
        });
    }

    /**
     * Catat pelaksanaan & hasil sesi.
     *
     * `counselor_note` hanya diterima dari pemegang akses konseling; bila
     * pengguna lain mencoba mengirimnya, permintaan ditolak — bukan diabaikan
     * diam-diam, supaya tidak ada catatan yang "hilang" tanpa penjelasan.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(User $actor, BkCounselingSession $session, array $data): BkCounselingSession
    {
        $case = $session->case;

        if ((int) $session->counselor_id !== (int) $actor->id && ! $this->access->isCoordinator($actor) && ! $this->access->isBkAdmin($actor)) {
            throw new BkValidationException(['session' => ['Hanya konselor pelaksana yang dapat mengisi hasil sesi ini.']]);
        }

        if (array_key_exists('counselor_note', $data) && ! $this->access->canViewCounselingContent($actor, $case)) {
            throw new BkValidationException(['counselor_note' => ['Catatan konselor hanya dapat diisi pemegang akses konseling.']]);
        }

        $before = [
            'status' => $session->status->value,
            'session_summary' => $session->session_summary,
            'counselor_note' => $session->counselor_note,
        ];

        $startedAt = $data['started_at'] ?? $session->started_at?->toDateTimeString();
        $endedAt = $data['ended_at'] ?? $session->ended_at?->toDateTimeString();

        $session->fill([
            'status' => $data['status'] ?? SessionStatus::COMPLETED->value,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_minutes' => ($startedAt !== null && $endedAt !== null)
                ? (int) round((strtotime($endedAt) - strtotime($startedAt)) / 60)
                : $session->duration_minutes,
            'attendance_status' => $data['attendance_status'] ?? $session->attendance_status->value,
            'objective' => $data['objective'] ?? $session->objective,
            'session_summary' => $data['session_summary'] ?? $session->session_summary,
            'next_action' => $data['next_action'] ?? $session->next_action,
            'risk_indicator' => $data['risk_indicator'] ?? $session->risk_indicator,
            'parent_informed' => $data['parent_informed'] ?? $session->parent_informed,
        ]);

        if (array_key_exists('counselor_note', $data)) {
            $session->counselor_note = $data['counselor_note'];
        }

        $session->save();

        $this->timeline->record(CaseEventType::SESSION_COMPLETED, $actor, $case,
            summary: $session->session_type->label().' selesai'.($session->next_action ? ' dengan tindak lanjut.' : '.'),
            related: $session,
            statusFrom: $before['status'], statusTo: $session->status->value);

        $this->audit->record(AuditAction::SESSION_RECORD, $session->refresh(), $actor, [
            'changes' => $this->audit->diff($before, [
                'status' => $session->status->value,
                'session_summary' => $session->session_summary,
                'counselor_note' => $session->counselor_note,
            ]),
        ]);

        return $session->refresh();
    }

    /** Ubah catatan konselor pada sesi yang sudah tercatat (selalu diaudit). */
    public function amendNote(User $actor, BkCounselingSession $session, string $reason, ?string $newNote): BkCounselingSession
    {
        if (! $this->access->canViewCounselingContent($actor, $session->case)) {
            throw new BkValidationException(['counselor_note' => ['Anda tidak berwenang mengubah catatan konseling.']]);
        }

        $before = $session->counselor_note;

        $session->forceFill(['counselor_note' => $newNote])->save();

        $this->audit->record(AuditAction::UPDATE, $session->refresh(), $actor, [
            'reason' => $reason,
            'changes' => ['counselor_note' => ['from' => $before === null ? null : '[disunting]', 'to' => $newNote === null ? null : '[disunting]']],
        ]);

        if ($session->case !== null) {
            $this->timeline->record(CaseEventType::NOTE_ADDED, $actor, $session->case,
                summary: 'Catatan konselor pada '.$session->session_number.' diperbaiki: '.$reason, related: $session);
        }

        return $session->refresh();
    }

    /** Sesi yang ditandai berisiko tinggi — perhatian koordinator. */
    public function highRiskSessions(): Collection
    {
        return BkCounselingSession::query()
            ->whereIn('risk_indicator', ['HIGH', 'MODERATE'])
            ->where('status', SessionStatus::COMPLETED->value)
            ->orderByDesc('ended_at')
            ->limit(20)
            ->get();
    }

    /** Sesi yang dijadwalkan tetapi tidak pernah diisi hasilnya. */
    public function overdueSessions(): Collection
    {
        return BkCounselingSession::query()
            ->where('status', SessionStatus::SCHEDULED->value)
            ->where('scheduled_at', '<', now()->subDay())
            ->get();
    }

    /** Kehadiran sesi terakhir kali ini — dasar tindak lanjut otomatis. */
    public function attendanceForCase(BkCase $case): array
    {
        $sessions = $case->sessions()->get(['attendance_status']);

        return [
            'total' => $sessions->count(),
            'present' => $sessions->where('attendance_status', AttendanceStatus::PRESENT)->count(),
            'absent' => $sessions->where('attendance_status', AttendanceStatus::ABSENT)->count(),
        ];
    }

    /** Label kerahasiaan bawaan untuk sesi baru pada sebuah kasus. */
    public function defaultConfidentiality(BkCase $case): ConfidentialityLevel
    {
        return $case->confidentiality_level->rank() >= ConfidentialityLevel::COUNSELOR_ONLY->rank()
            ? $case->confidentiality_level
            : ConfidentialityLevel::COUNSELOR_ONLY;
    }
}
