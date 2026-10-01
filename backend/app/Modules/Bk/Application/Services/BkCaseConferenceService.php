<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\CaseParticipantRole;
use App\Modules\Bk\Domain\Enums\ConferenceActionStatus;
use App\Modules\Bk\Domain\Enums\ConferenceParticipantType;
use App\Modules\Bk\Domain\Enums\ConferenceStatus;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Domain\Enums\FollowUpType;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Domain\Enums\Priority;
use App\Modules\Bk\Domain\Enums\ScreeningOutcome;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCaseConference;
use App\Modules\Bk\Models\BkCaseConferenceAction;
use App\Modules\Bk\Models\BkCaseConferenceParticipant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Konferensi kasus.
 *
 * Konferensi adalah ruang kerja bersama, bukan pintu belakang menuju catatan
 * konseling. Karena itu peserta dari luar tim BK — termasuk manajemen yang
 * memimpin rapat — **tidak otomatis** memperoleh akses konseling: kewenangan
 * membaca ditentukan per peserta, dan tetap dibatasi kewenangan konseling kasus.
 *
 * Keputusan konferensi wajib berujung pada tindak lanjut yang punya penanggung
 * jawab dan tenggat. Konferensi tanpa tindak lanjut adalah rapat.
 */
class BkCaseConferenceService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
        private readonly BkAccessService $access,
        private readonly BkFollowUpService $followUps,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = BkCaseConference::query()
            ->with(['case:id,case_number,student_id', 'student:id,full_name', 'facilitator:id,name'])
            ->withCount(['participants', 'actions']);

        $this->access->scopeConferences($query, $actor);

        return $query
            ->when($filters['case_id'] ?? null, fn ($q, $id) => $q->where('case_id', (int) $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $status === 'open' ? $q->open() : $q->where('status', $status))
            ->when($filters['upcoming'] ?? null, fn ($q) => $q->upcoming())
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('scheduled_start', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('scheduled_start', '<=', $to))
            ->orderByDesc('scheduled_start')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Menyelenggarakan konferensi untuk sebuah kasus.
     *
     * @param array<string, mixed> $data
     */
    public function create(User $actor, BkCase $case, array $data): BkCaseConference
    {
        $this->access->assertCanViewCase($actor, $case, 'Anda tidak memiliki akses ke kasus konferensi ini.');

        // Konferensi tidak boleh lebih terbuka daripada kasusnya.
        $level = isset($data['confidentiality_level'])
            ? ConfidentialityLevel::from((string) $data['confidentiality_level'])
            : ConfidentialityLevel::RESTRICTED;

        if ($level->rank() > $case->confidentiality_level->rank()) {
            throw new BkValidationException([
                'confidentiality_level' => ['Konferensi tidak boleh lebih terbuka daripada kasusnya.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $case, $data, $level): BkCaseConference {
            $conference = BkCaseConference::query()->create([
                'conference_number' => $this->numbers->next('BK_CONFERENCE', 'BK-CF'),
                'case_id' => $case->id,
                'student_id' => $case->student_id,
                'title' => $data['title'] ?? 'Konferensi kasus '.$case->case_number,
                'purpose' => $data['purpose'] ?? null,
                'status' => ConferenceStatus::from((string) ($data['status'] ?? ConferenceStatus::SCHEDULED->value)),
                'confidentiality_level' => $level,
                'mode' => $data['mode'] ?? 'IN_PERSON',
                'location' => $data['location'] ?? null,
                'scheduled_start' => $data['scheduled_start'] ?? null,
                'scheduled_end' => $data['scheduled_end'] ?? null,
                'agenda' => $data['agenda'] ?? null,
                'facilitator_id' => $data['facilitator_id'] ?? $actor->id,
                'requested_by' => $actor->id,
                'created_by' => $actor->id,
            ]);

            // Peserta bawaan: tim BK yang menangani kasus. Guru/asrama tidak
            // ditambahkan otomatis — undangan adalah keputusan, bukan bawaan.
            $defaults = [ConferenceParticipantType::COUNSELOR, ConferenceParticipantType::BK_COORDINATOR];

            foreach ($defaults as $type) {
                $this->addParticipant($actor, $conference, ['participant_type' => $type->value], silent: true);
            }

            foreach (($data['participants'] ?? []) as $participant) {
                $this->addParticipant($actor, $conference, (array) $participant, silent: true);
            }

            $this->audit->record(AuditAction::CONFERENCE_CREATE, $conference, $actor, [
                'case_id' => $case->id,
                'participants' => $conference->participants()->count(),
                'confidentiality_level' => $level->value,
            ]);

            return $conference->refresh()->load(['participants.user:id,name', 'case:id,case_number']);
        });
    }

    /**
     * Menambahkan peserta.
     *
     * Kerahasiaan maksimum peserta diambil dari jenisnya, lalu **diturunkan** bila
     * peserta itu bukan pemegang akses konseling kasus. Dengan begitu undangan
     * rapat tidak pernah menjadi pemberian akses catatan.
     *
     * @param array<string, mixed> $data
     */
    public function addParticipant(User $actor, BkCaseConference $conference, array $data, bool $silent = false): BkCaseConferenceParticipant
    {
        $type = ConferenceParticipantType::from((string) $data['participant_type']);

        $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;
        $guardianId = isset($data['guardian_id']) ? (int) $data['guardian_id'] : null;
        $studentId = isset($data['student_id']) ? (int) $data['student_id'] : null;

        if ($userId === null && $guardianId === null && $studentId === null && empty($data['external_name'])) {
            throw new BkValidationException([
                'participant_type' => ['Peserta harus berupa pengguna, wali, siswa, atau pihak luar bernama.'],
            ]);
        }

        $maxLevel = $type->defaultMaxConfidentiality();
        $canViewConfidential = false;

        if ($userId !== null && $type->isBkTeam()) {
            $case = $conference->case;

            if ($case !== null) {
                $target = User::query()->find($userId);
                $canViewConfidential = $target !== null && $this->access->canViewCounselingContent($target, $case);
                $maxLevel = $this->access->visibleConfidentiality($target ?? $actor, $case);
            }
        } elseif ($userId !== null && $type->isExternal()) {
            // Guru/wali/siswa: peserta konferensi tetap hanya melihat materi
            // konferensi, bukan catatan konseling.
            $maxLevel = ConfidentialityLevel::STAFF_ONLY;
        }

        if (! $silent) {
            $this->assertCanFacilitate($actor, $conference);
        }

        $participant = BkCaseConferenceParticipant::query()->create([
            'conference_id' => $conference->id,
            'participant_type' => $type,
            'user_id' => $userId,
            'guardian_id' => $guardianId,
            'student_id' => $studentId,
            'external_provider_id' => $data['external_provider_id'] ?? null,
            'external_name' => $data['external_name'] ?? null,
            'external_organization' => $data['external_organization'] ?? null,
            'external_email' => $data['external_email'] ?? null,
            'role_label' => $data['role_label'] ?? $type->label(),
            'invitation_status' => $data['invitation_status'] ?? 'INVITED',
            'is_required' => (bool) ($data['is_required'] ?? false),
            'can_view_case' => (bool) ($data['can_view_case'] ?? true),
            'can_view_confidential' => $canViewConfidential,
            'max_confidentiality' => $maxLevel,
            'invited_at' => now(),
            'added_by' => $actor->id,
        ]);

        if ($userId !== null) {
            $this->notifications->notifyUser(
                $userId,
                NotificationEvent::CONFERENCE_SCHEDULED,
                "Undangan konferensi kasus: {$conference->conference_number}",
                [
                    'conference_id' => $conference->id,
                    'scheduled_start' => $conference->scheduled_start?->toIso8601String(),
                    'mode' => $conference->mode?->value,
                    'note' => 'Undangan konferensi tidak memberi akses catatan konseling.',
                ],
            );
        }

        $this->audit->record(AuditAction::UPDATE, $conference, $actor, [
            'participant_added' => $type->value,
            'participant_user_id' => $userId,
            'can_view_confidential' => $canViewConfidential,
        ]);

        return $participant;
    }

    public function removeParticipant(User $actor, BkCaseConference $conference, BkCaseConferenceParticipant $participant): void
    {
        $this->assertCanFacilitate($actor, $conference);

        $this->audit->record(AuditAction::UPDATE, $conference, $actor, [
            'participant_removed' => $participant->id,
            'participant_type' => $participant->participant_type->value,
        ]);

        $participant->delete();
    }

    /**
     * Mencatat jalannya konferensi: agenda yang dibahas, diskusi, dan keputusan.
     *
     * @param array<string, mixed> $data
     */
    public function update(User $actor, BkCaseConference $conference, array $data): BkCaseConference
    {
        $this->assertCanFacilitate($actor, $conference);

        $before = $conference->only(['status', 'discussion', 'decision', 'agenda']);

        $conference->fill([
            'title' => $data['title'] ?? $conference->title,
            'purpose' => $data['purpose'] ?? $conference->purpose,
            'location' => $data['location'] ?? $conference->location,
            'scheduled_start' => $data['scheduled_start'] ?? $conference->scheduled_start,
            'scheduled_end' => $data['scheduled_end'] ?? $conference->scheduled_end,
            'agenda' => $data['agenda'] ?? $conference->agenda,
            'discussion' => $data['discussion'] ?? $conference->discussion,
            'decision' => $data['decision'] ?? $conference->decision,
            'decision_outcome' => $data['decision_outcome'] ?? $conference->decision_outcome,
            'follow_up_required' => $data['follow_up_required'] ?? $conference->follow_up_required,
            'follow_up_summary' => $data['follow_up_summary'] ?? $conference->follow_up_summary,
            'minutes_document_id' => $data['minutes_document_id'] ?? $conference->minutes_document_id,
            'status' => $data['status'] ?? $conference->status,
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->record(AuditAction::UPDATE, $conference, $actor, [
            'changed' => array_keys($this->audit->diff($before, $conference->only(array_keys($before)))),
        ]);

        return $conference->refresh();
    }

    /** Menandai pertemuan dimulai. */
    public function start(User $actor, BkCaseConference $conference): BkCaseConference
    {
        $this->assertCanFacilitate($actor, $conference);

        $conference->forceFill(['status' => ConferenceStatus::IN_PROGRESS, 'started_at' => now()])->save();

        return $conference->refresh();
    }

    /**
     * Menutup konferensi beserta keputusannya.
     *
     * Konferensi hanya boleh ditutup bila keputusan dan bentuk tindak lanjutnya
     * sudah jelas — supaya tidak ada rapat yang "selesai" tanpa akibat.
     *
     * @param array<string, mixed> $data
     */
    public function complete(User $actor, BkCaseConference $conference, array $data): BkCaseConference
    {
        $this->assertCanFacilitate($actor, $conference);

        if (empty($data['decision'])) {
            throw new BkValidationException(['decision' => ['Keputusan konferensi wajib diisi sebelum ditutup.']]);
        }

        $outcome = ScreeningOutcome::from((string) ($data['decision_outcome'] ?? ScreeningOutcome::COUNSELING->value));
        $requiresFollowUp = (bool) ($data['follow_up_required'] ?? true);

        if ($requiresFollowUp && empty($data['follow_up_summary'])) {
            throw new BkValidationException([
                'follow_up_summary' => ['Sebutkan bentuk tindak lanjut yang disepakati, atau tandai tidak diperlukan.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $conference, $data, $outcome, $requiresFollowUp): BkCaseConference {
            $conference->forceFill([
                'status' => ConferenceStatus::COMPLETED,
                'completed_at' => now(),
                'decision' => $data['decision'],
                'decision_outcome' => $outcome,
                'discussion' => $data['discussion'] ?? $conference->discussion,
                'follow_up_required' => $requiresFollowUp,
                'follow_up_summary' => $data['follow_up_summary'] ?? null,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(AuditAction::UPDATE, $conference, $actor, [
                'completed' => true,
                'decision_outcome' => $outcome->value,
                'follow_up_required' => $requiresFollowUp,
            ]);

            return $conference->refresh();
        });
    }

    /**
     * Membuat tindak lanjut hasil konferensi.
     *
     * Bila diminta, tindak lanjut juga dibuat di kasus (`bk_follow_ups`) supaya
     * ikut terpantau pada ritme kasus, bukan hidup sendiri di dalam konferensi.
     *
     * @param array<string, mixed> $data
     */
    public function createAction(User $actor, BkCaseConference $conference, array $data): BkCaseConferenceAction
    {
        $this->assertCanFacilitate($actor, $conference);

        if (empty($data['title'])) {
            throw new BkValidationException(['title' => ['Tindak lanjut wajib memiliki judul yang jelas.']]);
        }

        if (empty($data['responsible_user_id']) && empty($data['responsible_role'])) {
            throw new BkValidationException([
                'responsible_user_id' => ['Tindak lanjut wajib memiliki penanggung jawab.'],
            ]);
        }

        if (empty($data['due_date'])) {
            throw new BkValidationException(['due_date' => ['Tindak lanjut wajib memiliki tenggat.']]);
        }

        return DB::transaction(function () use ($actor, $conference, $data): BkCaseConferenceAction {
            $action = BkCaseConferenceAction::query()->create([
                'conference_id' => $conference->id,
                'case_id' => $conference->case_id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'responsible_user_id' => $data['responsible_user_id'] ?? null,
                'responsible_role' => $data['responsible_role'] ?? null,
                'due_date' => $data['due_date'],
                'status' => ConferenceActionStatus::PENDING,
                'priority' => Priority::tryFrom((string) ($data['priority'] ?? 'NORMAL')) ?? Priority::NORMAL,
                'document_id' => $data['document_id'] ?? null,
                'created_by' => $actor->id,
            ]);

            if (($data['create_case_follow_up'] ?? false) && $conference->case !== null) {
                $followUp = $this->followUps->create($actor, $conference->case, [
                    'title' => $data['title'],
                    'type' => FollowUpType::OTHER->value,
                    'description' => $data['description'] ?? 'Tindak lanjut konferensi '.$conference->conference_number,
                    'assigned_to' => $data['responsible_user_id'] ?? null,
                    'due_date' => $data['due_date'],
                ]);

                $action->forceFill(['follow_up_id' => $followUp->id])->save();
            }

            if ($action->responsible_user_id !== null) {
                $this->notifications->notifyUser(
                    (int) $action->responsible_user_id,
                    NotificationEvent::CONFERENCE_ACTION_DUE,
                    "Tindak lanjut konferensi: {$action->title}",
                    [
                        'action_id' => $action->id,
                        'conference_id' => $conference->id,
                        'due_date' => $action->due_date?->toDateString(),
                    ],
                );
            }

            $this->audit->record(AuditAction::CREATE, $action, $actor, [
                'conference_id' => $conference->id,
                'has_follow_up' => $action->follow_up_id !== null,
            ]);

            return $action->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function completeAction(User $actor, BkCaseConferenceAction $action, array $data): BkCaseConferenceAction
    {
        $action->forceFill([
            'status' => ConferenceActionStatus::COMPLETED,
            'completed_at' => now(),
            'outcome' => $data['outcome'] ?? $action->outcome,
            'updated_by' => $actor->id,
        ])->save();

        if ($action->follow_up_id !== null) {
            $followUp = $action->followUp;

            if ($followUp !== null && ! $followUp->status->isCompleted()) {
                $this->followUps->complete($actor, $followUp, ['outcome' => $data['outcome'] ?? 'Selesai melalui konferensi kasus.']);
            }
        }

        $this->audit->record(AuditAction::UPDATE, $action, $actor, ['completed' => true]);

        return $action->refresh();
    }

    /** Peserta konferensi untuk satu kasus (dibolongkan oleh kewenangan konferensi). */
    public function participants(User $actor, BkCaseConference $conference): Collection
    {
        $this->assertCanView($actor, $conference);

        return $conference->participants()
            ->with(['user:id,name', 'guardian:id,full_name', 'student:id,full_name', 'provider:id,name'])
            ->orderBy('id')
            ->get();
    }

    /** Tindak lanjut konferensi; peserta non-tim hanya melihat miliknya sendiri. */
    public function actions(User $actor, BkCaseConference $conference): Collection
    {
        $this->assertCanView($actor, $conference);

        return $conference->actions()
            ->with(['responsible:id,name', 'followUp:id,title,status,due_date'])
            ->orderBy('due_date')
            ->get();
    }

    /** Boleh melihat konferensi: tim BK, peserta terdaftar, atau pemegang akses kasus. */
    public function canView(User $actor, BkCaseConference $conference): bool
    {
        if ($this->access->isBkStaff($actor)) {
            return true;
        }

        $isParticipant = $conference->participants()
            ->where(fn ($query) => $query->where('user_id', $actor->id)->orWhere('student_id', $this->access->studentIdForUser($actor)))
            ->exists();

        if ($isParticipant) {
            return true;
        }

        $case = $conference->case;

        return $case !== null && $this->access->canViewCase($actor, $case);
    }

    public function assertCanView(User $actor, BkCaseConference $conference): void
    {
        if (! $this->canView($actor, $conference)) {
            $this->audit->logAccess($actor, 'VIEW_CONFIDENTIAL', $conference, false, 'Konferensi di luar cakupan.');

            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Anda tidak memiliki akses ke konferensi ini.');
        }

        $this->audit->record(AuditAction::CONFERENCE_VIEW, $conference, $actor, ['participant' => true]);
    }

    /** Mengubah isi konferensi hanya boleh fasilitator atau tim BK. */
    public function assertCanFacilitate(User $actor, BkCaseConference $conference): void
    {
        $isFacilitator = $conference->facilitator_id !== null && (int) $conference->facilitator_id === $actor->id;

        if (! $isFacilitator && ! $this->access->isBkStaff($actor)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Hanya fasilitator atau tim BK yang dapat mengubah konferensi ini.');
        }
    }

    /**
     * Batas kerahasiaan yang berlaku bagi seorang peserta.
     *
     * Inilah yang menjawab "peserta konferensi tidak otomatis memperoleh akses
     * catatan rahasia": batasnya adalah tingkat yang tercatat pada pesertanya,
     * dan pemegang akses konseling kasus tetap satu-satunya yang boleh membuka isi
     * konseling.
     */
    public function confidentialityCeiling(User $actor, BkCaseConference $conference): ConfidentialityLevel
    {
        if ($this->access->isBkStaff($actor) && $conference->case !== null && $this->access->canViewCounselingContent($actor, $conference->case)) {
            return $conference->confidentiality_level;
        }

        $participant = $conference->participants()->where('user_id', $actor->id)->first();

        return $participant?->max_confidentiality ?? ConfidentialityLevel::STAFF_ONLY;
    }
}
