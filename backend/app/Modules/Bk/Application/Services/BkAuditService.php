<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCaseNote;
use App\Modules\Bk\Models\BkCaseDocument;
use App\Modules\Bk\Models\BkCounselingSession;
use App\Modules\Bk\Models\BkReferral;
use App\Modules\Bk\Models\BkAuditLog;
use App\Modules\Bk\Domain\Contracts\AuditProviderInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Request;

/**
 * Jejak audit BK — append-only.
 *
 * Yang dicatat adalah **metadata**: aksi, pelaku, peran, sasaran, dan perubahan
 * kolom non-sensitif. Isi catatan konseling tidak pernah masuk ke jejak audit
 * (`changes` hanya memuat nama kolom dan nilai yang aman dibaca auditor).
 *
 * Pembacaan data rahasia (`VIEW_CONFIDENTIAL`) juga dicatat, sehingga pertanyaan
 * "siapa membuka catatan konseling anak saya?" dapat dijawab.
 */
class BkAuditService
{
    public function __construct(private readonly AuditProviderInterface $erpAudit)
    {
    }

    /** @param array<string, mixed> $context */
    public function record(AuditAction $action, Model $subject, ?User $actor = null, array $context = []): BkAuditLog
    {
        $case = $subject instanceof BkCase ? $subject : $this->caseFrom($subject);

        $log = BkAuditLog::query()->create([
            'action' => $action->value,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'case_id' => $case?->id,
            'referral_id' => $subject instanceof BkReferral ? $subject->id : ($context['referral_id'] ?? null),
            'student_id' => $context['student_id'] ?? $case?->student_id,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->roles->pluck('name')->first(),
            'confidentiality_level' => $context['confidentiality_level'] ?? $case?->confidentiality_level?->value,
            'access_reason' => $context['reason'] ?? null,
            'changes' => $context['changes'] ?? null,
            'context' => $this->requestContext(),
            'occurred_at' => now(),
        ]);

        $this->erpAudit->record(
            'bk.'.$action->value,
            $subject->getMorphClass(),
            (int) $subject->getKey(),
            ['student_id' => $context['student_id'] ?? null],
        );

        return $log;
    }

    /** Catat akses baca (termasuk yang ditolak — penolakan juga penting). */
    public function logAccess(User $user, string $action, Model $subject, bool $granted, ?string $reason = null): void
    {
        $enum = match ($action) {
            'VIEW' => AuditAction::VIEW,
            'VIEW_CONFIDENTIAL' => AuditAction::VIEW_CONFIDENTIAL,
            'DOCUMENT_VIEW' => AuditAction::DOCUMENT_VIEW,
            'EXPORT' => AuditAction::EXPORT,
            default => AuditAction::VIEW,
        };

        $case = $subject instanceof BkCase ? $subject : $this->caseFrom($subject);

        BkAuditLog::query()->create([
            'action' => $enum->value,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'case_id' => $case?->id,
            'student_id' => $case?->student_id ?? ($subject->student_id ?? null),
            'actor_id' => $user->id,
            'actor_role' => $user->roles->pluck('name')->first(),
            'confidentiality_level' => $this->subjectLevel($subject)?->value,
            'access_reason' => $reason,
            'changes' => null,
            'context' => $this->requestContext() + ['granted' => $granted],
            'occurred_at' => now(),
        ]);
    }

    /**
     * Perubahan kolom yang aman dibaca auditor.
     *
     * Kolom yang memuat isi naratif (mis. `counselor_note`, `discussion`) hanya
     * dicatat **nama kolomnya**, bukan nilainya.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function diff(array $before, array $after): array
    {
        $redacted = ['counselor_note', 'session_summary', 'body', 'discussion', 'observation', 'description',
            'student_perspective', 'teacher_observation', 'parent_observation', 'previous_support',
            'problem_statement', 'agreed_actions', 'outcome', 'result', 'notes'];

        $changes = [];

        foreach ($after as $key => $value) {
            if (! array_key_exists($key, $before) || $before[$key] === $value) {
                continue;
            }

            $changes[$key] = in_array($key, $redacted, true)
                ? ['from' => '[disunting]', 'to' => '[disunting]']
                : ['from' => $before[$key], 'to' => $value];
        }

        return $changes;
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return BkAuditLog::query()
            ->with(['actor:id,name', 'case:id,case_number,student_id'])
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['case_id'] ?? null, fn ($q, $caseId) => $q->where('case_id', $caseId))
            ->when($filters['student_id'] ?? null, fn ($q, $studentId) => $q->where('student_id', $studentId))
            ->when($filters['actor_id'] ?? null, fn ($q, $actorId) => $q->where('actor_id', $actorId))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('occurred_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('occurred_at', '<=', $to))
            ->orderByDesc('occurred_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @return array<string, mixed> */
    private function requestContext(): array
    {
        $request = Request::instance();

        return array_filter([
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => $request?->header('X-Request-Id'),
        ], fn ($value): bool => $value !== null);
    }

    private function caseFrom(Model $subject): ?BkCase
    {
        return match (true) {
            $subject instanceof BkCounselingSession, $subject instanceof BkCaseNote,
            $subject instanceof BkCaseDocument => $subject->case,
            default => method_exists($subject, 'case') ? $subject->case : null,
        };
    }

    private function subjectLevel(Model $subject): ?ConfidentialityLevel
    {
        $level = $subject->confidentiality_level ?? null;

        return $level instanceof ConfidentialityLevel ? $level : null;
    }
}
