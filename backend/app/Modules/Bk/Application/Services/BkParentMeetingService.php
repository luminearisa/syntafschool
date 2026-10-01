<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Enums\AttendanceStatus;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\CaseEventType;
use App\Modules\Bk\Domain\Enums\FollowUpStatus;
use App\Modules\Bk\Domain\Enums\ParentMeetingStatus;
use App\Modules\Bk\Domain\Enums\SessionMode;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkFollowUp;
use App\Modules\Bk\Models\BkParentMeeting;
use Illuminate\Support\Facades\DB;

/**
 * Pertemuan orang tua.
 *
 * Alur §19: kasus → permintaan pertemuan → jadwal → notifikasi → pertemuan →
 * notulen → daftar tindakan → tindak lanjut. Setiap kesepakatan dapat langsung
 * menjadi tindak lanjut BK, sehingga hasil rapat tidak berhenti di notulen.
 */
class BkParentMeetingService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkTimelineService $timeline,
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
        private readonly BkAccessService $access,
    ) {
    }

    /** Ajukan/rencanakan pertemuan orang tua. @param array<string, mixed> $data */
    public function request(User $actor, BkCase $case, array $data): BkParentMeeting
    {
        $this->access->assertCanViewCase($actor, $case, 'Menjadwalkan pertemuan orang tua');

        $meeting = BkParentMeeting::query()->create([
            'meeting_number' => $this->numbers->next('BK_PARENT_MEETING', 'BK-PM'),
            'case_id' => $case->id,
            'student_id' => $case->student_id,
            'requested_by' => $actor->id,
            'counselor_id' => $data['counselor_id'] ?? $case->assigned_counselor_id ?? $actor->id,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'mode' => $data['mode'] ?? SessionMode::IN_PERSON->value,
            'location' => $data['location'] ?? null,
            'agenda' => $data['agenda'] ?? null,
            'status' => $data['scheduled_at'] === null ? ParentMeetingStatus::REQUESTED->value : ParentMeetingStatus::SCHEDULED->value,
            'confidentiality_level' => $case->confidentiality_level->value,
        ]);

        foreach ((array) ($data['participants'] ?? []) as $participant) {
            $meeting->participants()->create([
                'role' => $participant['role'],
                'user_id' => $participant['user_id'] ?? null,
                'guardian_id' => $participant['guardian_id'] ?? null,
                'name' => $participant['name'] ?? null,
                'contact' => $participant['contact'] ?? null,
            ]);
        }

        $this->timeline->record(CaseEventType::PARENT_MEETING_HELD, $actor, $case,
            summary: 'Pertemuan orang tua '.(($data['scheduled_at'] ?? null) ? 'dijadwalkan '.$meeting->scheduled_at?->format('d M Y H:i') : 'diajukan').'.',
            related: $meeting);

        $this->audit->record(AuditAction::CREATE, $meeting, $actor);

        $this->notifications->notifyGuardians(
            (int) $case->student_id,
            NotificationEvent::APPOINTMENT_CREATED,
            'Sekolah mengundang Anda untuk pertemuan BK'.($meeting->scheduled_at ? ' pada '.$meeting->scheduled_at->format('d M Y H:i') : '').'.',
            ['meeting_id' => $meeting->id, 'case_id' => $case->id],
        );

        return $meeting->refresh();
    }

    /** Catat notulen & kesepakatan pertemuan. @param array<string, mixed> $data */
    public function recordMinutes(User $actor, BkParentMeeting $meeting, array $data): BkParentMeeting
    {
        if ($meeting->case !== null) {
            $this->access->assertCanViewCase($actor, $meeting->case, 'Mencatat notulen pertemuan');
        }

        return DB::transaction(function () use ($actor, $meeting, $data): BkParentMeeting {
            $from = $meeting->status->value;

            $meeting->fill([
                'discussion' => $data['discussion'] ?? $meeting->discussion,
                'agreed_actions' => $data['agreed_actions'] ?? $meeting->agreed_actions,
                'started_at' => $data['started_at'] ?? $meeting->started_at,
                'ended_at' => $data['ended_at'] ?? $meeting->ended_at,
                'next_meeting_on' => $data['next_meeting_on'] ?? $meeting->next_meeting_on?->toDateString(),
                'status' => $data['status'] ?? ParentMeetingStatus::COMPLETED->value,
                'minutes_recorded_at' => now(),
                'minutes_recorded_by' => $actor->id,
            ]);

            $meeting->save();

            foreach ((array) ($data['actions'] ?? []) as $action) {
                $meeting->actions()->create([
                    'case_id' => $meeting->case_id,
                    'description' => $action['description'],
                    'responsible_user_id' => $action['responsible_user_id'] ?? null,
                    'responsible_name' => $action['responsible_name'] ?? null,
                    'due_date' => $action['due_date'] ?? null,
                    'status' => FollowUpStatus::PENDING->value,
                ]);
            }

            if ($meeting->case !== null) {
                $this->timeline->record(CaseEventType::PARENT_MEETING_HELD, $actor, $meeting->case,
                    summary: 'Pertemuan orang tua selesai'.($meeting->actions()->count() > 0 ? ' dengan '.$meeting->actions()->count().' kesepakatan.' : '.'),
                    related: $meeting, statusFrom: $from, statusTo: $meeting->status->value);
            }

            $this->audit->record(AuditAction::UPDATE, $meeting->refresh(), $actor, [
                'changes' => ['status' => ['from' => $from, 'to' => $meeting->status->value]],
            ]);

            return $meeting->refresh();
        });
    }

    /** Tandai kehadiran peserta pertemuan. */
    public function recordAttendance(User $actor, BkParentMeeting $meeting, array $attendance): BkParentMeeting
    {
        if ($meeting->case !== null) {
            $this->access->assertCanViewCase($actor, $meeting->case, 'Mencatat kehadiran pertemuan');
        }

        foreach ($attendance as $row) {
            $meeting->participants()->whereKey((int) $row['participant_id'])->update([
                'attendance_status' => AttendanceStatus::from($row['status'])->value,
                'notes' => $row['notes'] ?? null,
            ]);
        }

        $this->audit->record(AuditAction::UPDATE, $meeting, $actor, ['reason' => 'Mencatat kehadiran pertemuan']);

        return $meeting->refresh();
    }

    /** Ubah kesepakatan menjadi tindak lanjut BK. */
    public function convertAction(User $actor, BkParentMeeting $meeting, int $actionId, ?string $dueDate = null): BkFollowUp
    {
        $action = $meeting->actions()->findOrFail($actionId);

        if ($action->follow_up_id !== null) {
            throw new BkValidationException(['action' => ['Kesepakatan ini sudah memiliki tindak lanjut.']]);
        }

        if ($meeting->case === null) {
            throw new BkValidationException(['action' => ['Pertemuan ini tidak tertaut pada kasus.']]);
        }

        $followUp = app(BkFollowUpService::class)->create($actor, $meeting->case, [
            'title' => $action->description,
            'due_date' => $dueDate ?? $action->due_date?->toDateString() ?? now()->addDays(7)->toDateString(),
            'assigned_to' => $action->responsible_user_id ?? $meeting->counselor_id,
            'type' => 'OTHER',
        ]);

        $action->forceFill(['follow_up_id' => $followUp->id])->save();

        return $followUp;
    }

    /** @return list<BkParentMeeting> */
    public function forCase(BkCase $case): array
    {
        return $case->meetings()
            ->with(['participants', 'actions'])
            ->orderByDesc('scheduled_at')
            ->get()
            ->all();
    }

    /** Pertemuan yang perlu perhatian (belum ada notulen setelah lewat jadwal). */
    public function withoutMinutes(): array
    {
        return BkParentMeeting::query()
            ->whereIn('status', [ParentMeetingStatus::SCHEDULED->value, ParentMeetingStatus::REQUESTED->value])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<', now()->subDays(3))
            ->get()
            ->all();
    }
}
