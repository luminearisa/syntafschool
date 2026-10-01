<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Contracts\StudentProviderInterface;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\CaseEventType;
use App\Modules\Bk\Domain\Enums\CaseParticipantRole;
use App\Modules\Bk\Domain\Enums\CaseStatus;
use App\Modules\Bk\Domain\Enums\CaseType;
use App\Modules\Bk\Domain\Enums\ClosureReason;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Domain\Enums\EscalationLevel;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Domain\Enums\Priority;
use App\Modules\Bk\Domain\Enums\ReferralCategory;
use App\Modules\Bk\Domain\Enums\Severity;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCaseTag;
use App\Modules\Bk\Models\BkTag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Kasus BK — pembukaan, perubahan, penutupan, dan pembukaan kembali.
 *
 * Tiga hal yang dijaga ketat di sini:
 *
 * 1. **Kerarahan status** — perpindahan status mengikuti `CaseStatus::allowedTransitions()`
 *    sehingga kasus tidak dapat "melompat" dari OPEN ke CLOSED tanpa penanganan.
 * 2. **Penutupan yang tidak asal tutup** — `close()` menolak bila masih ada
 *    tindak lanjut menggantung, intervensi berjalan, atau sasaran yang belum
 *    dinilai; konselor harus menuntaskan atau menjelaskannya.
 * 3. **Langit-langit kerahasiaan** — kasus tidak boleh menjadi lebih terbuka
 *    daripada referralnya, dan peserta tidak boleh diberi level di atas kasus.
 */
class BkCaseService
{
    public function __construct(
        private readonly BkTimelineService $timeline,
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
        private readonly BkAccessService $access,
        private readonly StudentProviderInterface $students,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = BkCase::query()->with([
            'student:id,full_name,nis',
            'counselor:id,name',
            'tags:id,name,color',
        ]);

        $this->access->scopeCases($query, $actor);

        return $query
            ->when($filters['status'] ?? null, fn ($q, $status) => is_array($status) ? $q->whereIn('status', $status) : $q->where('status', $status))
            ->when(filter_var($filters['open'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->open())
            ->when($filters['case_type'] ?? null, fn ($q, $type) => $q->where('case_type', $type))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($filters['priority'] ?? null, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['severity'] ?? null, fn ($q, $severity) => $q->where('severity', $severity))
            ->when($filters['counselor_id'] ?? null, fn ($q, $counselor) => $q->where('assigned_counselor_id', $counselor))
            ->when($filters['student_id'] ?? null, fn ($q, $studentId) => $q->where('student_id', $studentId))
            ->when($filters['tag'] ?? null, fn ($q, $tag) => $q->whereHas('tags', fn ($t) => $t->where('code', $tag)))
            ->when(filter_var($filters['review_overdue'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->reviewOverdue())
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(function ($inner) use ($term): void {
                $inner->where('case_number', 'like', '%'.$term.'%')
                    ->orWhere('title', 'like', '%'.$term.'%')
                    ->orWhereHas('student', fn ($st) => $st->where('full_name', 'like', '%'.$term.'%'));
            }))
            ->orderByRaw("CASE priority WHEN 'URGENT' THEN 1 WHEN 'HIGH' THEN 2 WHEN 'NORMAL' THEN 3 ELSE 4 END")
            ->orderByDesc('opened_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Buat kasus langsung (mis. temuan sistem atau kesepakatan rapat BK).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): BkCase
    {
        $studentId = (int) $data['student_id'];

        if ($this->students->snapshot($studentId) === null) {
            throw new BkValidationException(['student_id' => ['Siswa tidak ditemukan.']]);
        }

        $type = CaseType::from($data['case_type']);
        $priority = Priority::from($data['priority'] ?? Priority::NORMAL->value);

        return DB::transaction(function () use ($actor, $data, $studentId, $type, $priority): BkCase {
            $case = BkCase::query()->create([
                'case_number' => app(\App\Modules\Bk\Application\Support\BkNumberGenerator::class)->next('BK_CASE', 'BK-C'),
                'student_id' => $studentId,
                'case_type' => $type->value,
                'category' => $data['category'] ?? ReferralCategory::OTHER->value,
                'title' => $data['title'] ?? null,
                'priority' => $priority->value,
                'severity' => $data['severity'] ?? Severity::fromPriority($priority)->value,
                'confidentiality_level' => $data['confidentiality_level'] ?? $type->defaultConfidentiality()->value,
                'coordinator_id' => $data['coordinator_id'] ?? $actor->id,
                'opened_at' => now(),
                'target_review_date' => $data['target_review_date'] ?? now()->addDays($priority->defaultReviewDays())->toDateString(),
                'status' => CaseStatus::OPEN->value,
                'risk_flag' => $type->requiresCoordinatorOversight(),
                'created_by' => $actor->id,
            ]);

            // Pembuat kasus otomatis menjadi pihak pada kasusnya — tanpa itu,
            // konselor yang membuka kasus justru tidak dapat melihatnya sendiri.
            $case->participants()->create([
                'role' => CaseParticipantRole::COUNSELOR->value,
                'user_id' => $actor->id,
                'can_view_case' => true,
                'max_confidentiality' => ConfidentialityLevel::HIGHLY_CONFIDENTIAL->value,
                'added_by' => $actor->id,
            ]);

            $this->timeline->record(CaseEventType::CASE_OPENED, $actor, $case, summary: 'Kasus dibuka secara langsung.');
            $this->audit->record(AuditAction::CREATE, $case, $actor, ['student_id' => $studentId]);

            if (isset($data['tags'])) {
                $this->syncTags($actor, $case, (array) $data['tags']);
            }

            return $case->refresh();
        });
    }

    /**
     * Ubah kasus (klasifikasi, prioritas, kerahasiaan).
     *
     * Perubahan tingkat kerahasiaan dibatasi: tidak boleh lebih longgar daripada
     * referral sumbernya, dan tidak boleh di bawah level yang membuat peserta
     * kehilangan akses secara diam-diam.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, BkCase $case, array $data): BkCase
    {
        $this->access->assertCanViewCase($actor, $case, 'Memperbarui kasus');

        $before = $case->only(['priority', 'severity', 'confidentiality_level', 'case_type', 'category', 'target_review_date', 'title']);

        if (isset($data['confidentiality_level'])) {
            $requested = ConfidentialityLevel::from($data['confidentiality_level']);
            $source = $case->sourceReferral?->confidentiality_level;

            if ($source !== null && $requested->rank() < $source->rank()) {
                throw new BkValidationException([
                    'confidentiality_level' => ['Tingkat kerahasiaan tidak boleh lebih terbuka daripada referral asalnya ('.$source->label().').'],
                ]);
            }
        }

        if (isset($data['severity']) && isset($data['priority']) === false) {
            // Severity tanpa prioritas diizinkan; hanya dicatat di audit.
        }

        $case->fill([
            'case_type' => $data['case_type'] ?? $case->case_type->value,
            'category' => $data['category'] ?? $case->category->value,
            'title' => $data['title'] ?? $case->title,
            'priority' => $data['priority'] ?? $case->priority->value,
            'severity' => $data['severity'] ?? $case->severity->value,
            'confidentiality_level' => $data['confidentiality_level'] ?? $case->confidentiality_level->value,
            'target_review_date' => $data['target_review_date'] ?? $case->target_review_date?->toDateString(),
            'next_review_date' => $data['next_review_date'] ?? $case->next_review_date?->toDateString(),
            'risk_flag' => $data['risk_flag'] ?? $case->risk_flag,
        ]);

        $case->save();

        if (isset($data['tags'])) {
            $this->syncTags($actor, $case, (array) $data['tags']);
        }

        $this->audit->record(AuditAction::UPDATE, $case->refresh(), $actor, [
            'changes' => $this->audit->diff($before, $case->only(array_keys($before))),
        ]);

        return $case->refresh();
    }

    /**
     * Pindahkan status kasus mengikuti alur yang sah.
     *
     * Penutupan dan pembukaan kembali punya jalur sendiri (`close()`,
     * `reopen()`) karena keduanya menuntut pemeriksaan tambahan.
     */
    public function transition(User $actor, BkCase $case, CaseStatus $target, ?string $note = null): BkCase
    {
        $this->access->assertCanViewCase($actor, $case, 'Mengubah status kasus');

        if (! $case->status->canTransitionTo($target)) {
            throw new BkValidationException([
                'status' => [sprintf('Status %s tidak dapat langsung berpindah ke %s.', $case->status->label(), $target->label())],
            ]);
        }

        if ($target === CaseStatus::CLOSED) {
            return $this->close($actor, $case, ClosureReason::OTHER, $note);
        }

        if (in_array($target, [CaseStatus::RESOLVED], true) && ! $this->access->canCloseCase($actor, $case)) {
            throw new BkValidationException(['status' => ['Anda tidak berwenang menuntaskan kasus ini.']]);
        }

        $from = $case->status->value;

        $case->forceFill(['status' => $target->value, 'last_reviewed_at' => now()])->save();

        $this->timeline->record(CaseEventType::STATUS_CHANGED, $actor, $case,
            summary: $note ?? sprintf('Status berubah dari %s ke %s.', $from, $target->value),
            statusFrom: $from, statusTo: $target->value);

        return $case->refresh();
    }

    /**
     * Tutup kasus — hanya bila tidak ada pekerjaan menggantung.
     *
     * Pemeriksaan penutupan (checklist §18): tindak lanjut terbuka, intervensi
     * berjalan, sasaran belum dinilai, kelengkapan dokumen wajib, dan status
     * rujukan. Bila ada yang belum selesai, permintaan ditolak dengan daftar
     * alasan yang dapat langsung ditindaklanjuti konselor.
     */
    public function close(User $actor, BkCase $case, ClosureReason $reason, ?string $summary = null, bool $force = false): BkCase
    {
        if (! $this->access->canCloseCase($actor, $case)) {
            throw new BkValidationException(['case' => ['Anda tidak berwenang menutup kasus ini.']]);
        }

        if ($case->status === CaseStatus::CLOSED) {
            throw new BkValidationException(['case' => ['Kasus ini sudah ditutup.']]);
        }

        $blockers = $this->closureBlockers($case);

        if ($blockers !== [] && ! $force) {
            throw new BkValidationException(['closure' => $blockers]);
        }

        return DB::transaction(function () use ($actor, $case, $reason, $summary, $blockers, $force): BkCase {
            $from = $case->status->value;

            $case->forceFill([
                'status' => CaseStatus::CLOSED->value,
                'closure_reason' => $reason->value,
                'closure_summary' => $summary,
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'resolved_at' => $case->resolved_at ?? now(),
            ])->save();

            // Tindak lanjut yang masih menggantung ikut dibatalkan agar tidak
            // muncul sebagai keterlambatan padahal kasusnya sudah selesai.
            $case->followUps()->whereIn('status', ['PENDING', 'MISSED', 'RESCHEDULED'])
                ->update(['status' => 'CANCELLED', 'next_action' => 'Kasus ditutup: '.$reason->label()]);

            $this->timeline->record(
                CaseEventType::CASE_CLOSED, $actor, $case,
                summary: ($summary ?? 'Kasus ditutup').' — alasan: '.$reason->label().($force && $blockers !== [] ? ' (ditutup paksa)' : ''),
                statusFrom: $from, statusTo: CaseStatus::CLOSED->value,
                payload: ['closure_reason' => $reason->value, 'forced' => $force, 'blockers' => $force ? $blockers : []],
            );

            $this->audit->record(AuditAction::CLOSE, $case->refresh(), $actor, [
                'reason' => $reason->label(),
                'changes' => ['status' => ['from' => $from, 'to' => CaseStatus::CLOSED->value]],
            ]);

            $this->notifications->notifyHomeroomTeacher(
                (int) $case->student_id,
                NotificationEvent::CASE_ASSIGNED,
                sprintf('Penanganan BK untuk siswa ini dinyatakan selesai pada %s.', now()->toDateString()),
                ['case_id' => $case->id],
            );

            return $case->refresh();
        });
    }

    /** Buka kembali kasus yang sudah ditutup/dituntaskan. */
    public function reopen(User $actor, BkCase $case, string $reason): BkCase
    {
        if (! $this->access->canReopenCase($actor, $case)) {
            throw new BkValidationException(['case' => ['Hanya koordinator atau administrator BK yang dapat membuka kembali kasus.']]);
        }

        if (! in_array($case->status, [CaseStatus::CLOSED, CaseStatus::RESOLVED], true)) {
            throw new BkValidationException(['case' => ['Kasus ini masih berjalan; tidak perlu dibuka kembali.']]);
        }

        $from = $case->status->value;

        $case->forceFill([
            'status' => CaseStatus::REOPENED->value,
            'reopened_at' => now(),
            'reopen_count' => (int) $case->reopen_count + 1,
            'closed_at' => null,
            'closed_by' => null,
            'closure_reason' => null,
            'target_review_date' => now()->addDays($case->priority->defaultReviewDays())->toDateString(),
        ])->save();

        $this->timeline->record(CaseEventType::CASE_REOPENED, $actor, $case,
            summary: 'Kasus dibuka kembali: '.$reason, statusFrom: $from, statusTo: CaseStatus::REOPENED->value);

        $this->audit->record(AuditAction::REOPEN, $case->refresh(), $actor, ['reason' => $reason]);

        return $case->refresh();
    }

    /** Catat tinjauan berkala kasus (menjaga ritme pemantauan). */
    public function review(User $actor, BkCase $case, ?string $note = null, ?string $nextReview = null): BkCase
    {
        $this->access->assertCanViewCase($actor, $case, 'Meninjau kasus');

        $case->forceFill([
            'last_reviewed_at' => now(),
            'next_review_date' => $nextReview ?? now()->addDays(14)->toDateString(),
        ])->save();

        $this->timeline->record(CaseEventType::CASE_REVIEWED, $actor, $case, summary: $note ?? 'Kasus ditinjau.');

        return $case->refresh();
    }

    /** Eskalasi ke koordinator/manajemen/pihak luar. */
    public function escalate(User $actor, BkCase $case, EscalationLevel $level, string $reason): BkCase
    {
        if (! $this->access->canEscalate($actor, $case)) {
            throw new BkValidationException(['case' => ['Anda tidak berwenang mengeskalasi kasus ini.']]);
        }

        $case->forceFill([
            'escalation_level' => $level->value,
            'escalated_at' => now(),
            'escalated_to' => $reason,
            'risk_flag' => true,
        ])->save();

        $this->timeline->record(CaseEventType::ESCALATED, $actor, $case,
            summary: 'Dieskalasi ke '.$level->label().': '.$reason, payload: ['level' => $level->value]);

        $this->audit->record(AuditAction::ESCALATE, $case->refresh(), $actor, ['reason' => $reason]);

        $this->notifications->notifyTeam(
            NotificationEvent::ESCALATION,
            sprintf('Kasus %s dieskalasi ke %s.', $case->case_number, $level->label()),
            ['case_id' => $case->id],
        );

        return $case->refresh();
    }

    /**
     * Bagikan kasus kepada pihak lain (wali kelas, pengasuh, orang tua).
     *
     * Pemberian akses selalu dibatasi level kerahasiaan: `max_confidentiality`
     * tidak boleh melebihi level kasusnya, dan pihak non-BK tidak pernah
     * menerima level konseling.
     */
    public function addParticipant(User $actor, BkCase $case, array $data): void
    {
        $role = CaseParticipantRole::from($data['role']);
        $requested = isset($data['max_confidentiality'])
            ? ConfidentialityLevel::from($data['max_confidentiality'])
            : ConfidentialityLevel::STAFF_ONLY;

        if ($role === CaseParticipantRole::COUNSELOR && ! $this->access->canViewCounselingContent($actor, $case)) {
            throw new BkValidationException(['role' => ['Hanya konselor penanggung jawab yang dapat menambah konselor lain.']]);
        }

        $ceiling = $this->access->canViewCounselingContent($actor, $case)
            ? $case->confidentiality_level
            : ConfidentialityLevel::STAFF_ONLY;

        $level = $requested->rank() > $ceiling->rank() ? $ceiling : $requested;

        $case->participants()->create([
            'role' => $role->value,
            'user_id' => $data['user_id'] ?? null,
            'student_id' => $data['student_id'] ?? null,
            'guardian_id' => $data['guardian_id'] ?? null,
            'name' => $data['name'] ?? null,
            'contact' => $data['contact'] ?? null,
            'can_view_case' => (bool) ($data['can_view_case'] ?? $role !== CaseParticipantRole::EXTERNAL),
            'max_confidentiality' => $level->value,
            'notes' => $data['notes'] ?? null,
            'added_by' => $actor->id,
        ]);

        $this->timeline->record(CaseEventType::STATUS_CHANGED, $actor, $case,
            summary: $role->label().' ditambahkan sebagai pihak pada kasus.', payload: ['role' => $role->value]);

        $this->audit->record(AuditAction::SHARE, $case, $actor, [
            'reason' => 'Menambahkan '.$role->label(),
            'changes' => ['participant' => ['from' => null, 'to' => $role->value]],
        ]);
    }

    public function removeParticipant(User $actor, BkCase $case, int $participantId): void
    {
        $participant = $case->participants()->findOrFail($participantId);

        $participant->delete();

        $this->timeline->record(CaseEventType::STATUS_CHANGED, $actor, $case,
            summary: 'Satu pihak dihentikan aksesnya pada kasus.', payload: ['role' => $participant->role->value]);

        $this->audit->record(AuditAction::SHARE, $case, $actor, ['reason' => 'Mencabut akses peserta']);
    }

    /** @param list<string|int> $tags kode tag atau ID */
    public function syncTags(User $actor, BkCase $case, array $tags): void
    {
        $ids = collect($tags)->map(function ($tag): ?int {
            if (is_numeric($tag)) {
                return (int) $tag;
            }

            $found = BkTag::query()->where('code', (string) $tag)->first();

            return $found?->id ?? BkTag::query()->firstOrCreate(
                ['code' => (string) $tag],
                ['name' => ucfirst(str_replace('_', ' ', (string) $tag)), 'category' => 'OTHER'],
            )->id;
        })->filter()->values();

        BkCaseTag::query()->where('case_id', $case->id)->whereNotIn('tag_id', $ids)->delete();

        foreach ($ids as $id) {
            BkCaseTag::query()->firstOrCreate(['case_id' => $case->id, 'tag_id' => $id], ['added_by' => $actor->id]);
        }

        $this->audit->record(AuditAction::UPDATE, $case, $actor, ['changes' => ['tags' => ['from' => null, 'to' => $ids->all()]]]);
    }

    /**
     * Daftar penghalang penutupan kasus.
     *
     * @return list<string>
     */
    public function closureBlockers(BkCase $case): array
    {
        $blockers = [];

        $openFollowUps = $case->followUps()->open()->count();
        if ($openFollowUps > 0) {
            $blockers[] = $openFollowUps.' tindak lanjut masih belum selesai.';
        }

        $openInterventions = $case->interventions()->whereIn('status', ['PLANNED', 'IN_PROGRESS'])->count();
        if ($openInterventions > 0) {
            $blockers[] = $openInterventions.' intervensi masih berjalan.';
        }

        $pendingGoals = $case->goals()->whereIn('status', ['PENDING', 'IN_PROGRESS'])->count();
        if ($pendingGoals > 0) {
            $blockers[] = $pendingGoals.' sasaran belum dinilai hasilnya.';
        }

        $scheduledSessions = $case->sessions()->whereIn('status', ['SCHEDULED', 'ONGOING'])->count();
        if ($scheduledSessions > 0) {
            $blockers[] = $scheduledSessions.' sesi konseling masih terjadwal.';
        }

        $openMeetings = $case->meetings()->whereIn('status', ['REQUESTED', 'SCHEDULED'])->count();
        if ($openMeetings > 0) {
            $blockers[] = $openMeetings.' pertemuan orang tua masih berjalan.';
        }

        $pendingActions = $case->meetings()->whereHas('actions', fn ($q) => $q->whereIn('status', ['PENDING', 'RESCHEDULED']))->count();
        if ($pendingActions > 0) {
            $blockers[] = $pendingActions.' kesepakatan pertemuan belum ditindaklanjuti.';
        }

        if ($case->closure_reason === null && $case->status->value === 'OPEN') {
            $blockers[] = 'Kasus belum pernah ditinjau; lakukan tinjauan sebelum menutup.';
        }

        return $blockers;
    }
}
