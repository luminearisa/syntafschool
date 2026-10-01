<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Domain\Enums\ReferralCategory;
use App\Modules\Bk\Domain\Enums\ReferralSource;
use App\Modules\Bk\Domain\Enums\SupportRequestStatus;
use App\Modules\Bk\Domain\Enums\SupportRequestType;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkSupportRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Permintaan dukungan dari portal siswa & orang tua.
 *
 * Formulirnya sengaja ringan: cukup topik dan waktu yang disukai. Detail
 * sensitif dibicarakan langsung dengan konselor — bukan diketik di formulir
 * yang jejaknya lebih sulit dijaga.
 */
class BkSupportRequestService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
        private readonly BkAccessService $access,
        private readonly BkReferralService $referrals,
    ) {
    }

    /**
     * Siswa mengajukan permintaan konseling untuk dirinya sendiri.
     *
     * @param  array<string, mixed>  $data
     */
    public function requestByStudent(User $actor, array $data): BkSupportRequest
    {
        $studentId = $this->access->studentIdForUser($actor) ?? (int) ($data['student_id'] ?? 0);

        if ($studentId === 0) {
            throw new BkValidationException(['student_id' => ['Akun ini tidak tertaut pada data siswa.']]);
        }

        return $this->create($actor, $studentId, SupportRequestType::STUDENT_SELF, $data);
    }

    /** Orang tua mengajukan dukungan untuk anaknya (relasi diverifikasi). */
    public function requestByGuardian(User $actor, array $data): BkSupportRequest
    {
        $studentId = (int) ($data['student_id'] ?? 0);

        if (! $this->access->isGuardianOf($actor, $studentId)) {
            throw new BkValidationException(['student_id' => ['Anda bukan wali sah dari siswa tersebut.']]);
        }

        return $this->create($actor, $studentId, SupportRequestType::PARENT, $data);
    }

    /** @param array<string, mixed> $data */
    private function create(User $actor, int $studentId, SupportRequestType $type, array $data): BkSupportRequest
    {
        $request = BkSupportRequest::query()->create([
            'request_number' => $this->numbers->next('BK_SUPPORT_REQUEST', 'BK-SR'),
            'request_type' => $type->value,
            'student_id' => $studentId,
            'requested_by' => $actor->id,
            'guardian_id' => $type === SupportRequestType::PARENT ? $actor->userable_id : null,
            'topic' => $data['topic'],
            'preferred_time_slot' => $data['preferred_time_slot'] ?? null,
            'message' => $data['message'] ?? null,
            'urgent' => (bool) ($data['urgent'] ?? false),
            'status' => SupportRequestStatus::QUEUED->value,
        ]);

        $this->audit->record(AuditAction::REQUEST_SUBMIT, $request, $actor, ['student_id' => $studentId]);

        $this->notifications->notifyTeam(
            NotificationEvent::NEW_REFERRAL,
            sprintf('Permintaan dukungan %s dari %s menunggu penugasan.', $request->request_number, $type->label()),
            ['support_request_id' => $request->id, 'student_id' => $studentId, 'urgent' => $request->urgent],
        );

        return $request;
    }

    /**
     * Telaah permintaan; dapat langsung menjadi referral.
     *
     * @param  array<string, mixed>  $data
     */
    public function review(User $actor, BkSupportRequest $request, array $data): BkSupportRequest
    {
        if ($request->status->value === SupportRequestStatus::CLOSED->value) {
            throw new BkValidationException(['status' => ['Permintaan ini sudah selesai.']]);
        }

        $decision = $data['decision'] ?? 'ASSIGNED';

        $request->forceFill([
            'status' => SupportRequestStatus::from($decision)->value,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'decline_reason' => $data['decline_reason'] ?? null,
        ])->save();

        if ($decision !== SupportRequestStatus::DECLINED->value) {
            $referral = $this->referrals->create($actor, [
                'student_id' => (int) $request->student_id,
                'source_type' => $request->request_type->value === SupportRequestType::PARENT->value
                    ? ReferralSource::PARENT->value
                    : ReferralSource::SELF_REFERRAL->value,
                'source_id' => $request->id,
                'source_label' => $request->request_number,
                'category' => $data['category'] ?? ReferralCategory::OTHER->value,
                'priority' => $request->urgent ? 'HIGH' : 'NORMAL',
                'description' => 'Permintaan dukungan portal: '.$request->topic,
                'requested_support' => $request->preferred_time_slot,
            ]);

            $request->forceFill(['referral_id' => $referral->id])->save();
        }

        $this->audit->record(AuditAction::UPDATE, $request->refresh(), $actor, ['reason' => 'Menelaah permintaan portal']);

        return $request->refresh();
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = BkSupportRequest::query()->with(['student:id,full_name,nis', 'requestedBy:id,name']);

        if (! $this->access->isBkStaff($actor)) {
            $studentId = $this->access->studentIdForUser($actor);
            $guardianIds = $this->access->guardianStudentIds($actor);

            $query->where(function ($inner) use ($actor, $studentId, $guardianIds): void {
                $inner->where('requested_by', $actor->id);

                if ($studentId !== null) {
                    $inner->orWhere('student_id', $studentId);
                }

                if ($guardianIds !== []) {
                    $inner->orWhereIn('student_id', $guardianIds);
                }
            });
        }

        return $query
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['request_type'] ?? null, fn ($q, $type) => $q->where('request_type', $type))
            ->when(filter_var($filters['queued'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->queued())
            ->orderByRaw('urgent DESC')
            ->orderBy('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }
}
