<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\CaseEventType;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Domain\Enums\NoteType;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCaseNote;

/**
 * Catatan kasus — termasuk catatan sensitif konselor.
 *
 * Catatan `SENSITIVE` atau `COUNSELOR_NOTE` hanya dapat ditulis dan dibaca
 * pemegang akses konseling. Catatan tidak pernah disunting diam-diam:
 * perbaikan membuat baris baru yang menunjuk catatan asalnya (`amended_from_id`),
 * sehingga riwayat tetap utuh.
 */
class BkNoteService
{
    public function __construct(
        private readonly BkTimelineService $timeline,
        private readonly BkAuditService $audit,
        private readonly BkAccessService $access,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, BkCase $case, array $data): BkCaseNote
    {
        $this->access->assertCanViewCase($actor, $case, 'Menambah catatan kasus');

        $type = NoteType::from($data['note_type'] ?? NoteType::GENERAL->value);
        $level = isset($data['confidentiality_level'])
            ? ConfidentialityLevel::from($data['confidentiality_level'])
            : ($case->confidentiality_level->rank() >= ConfidentialityLevel::COUNSELOR_ONLY->rank()
                ? $case->confidentiality_level
                : ConfidentialityLevel::COUNSELOR_ONLY);

        $isSensitive = (bool) ($data['is_sensitive'] ?? false);

        if (($type === NoteType::SENSITIVE || $isSensitive || $level->isCounselorOnly())
            && ! $this->access->canViewCounselingContent($actor, $case)) {
            throw new BkValidationException(['note_type' => ['Catatan rahasia hanya dapat ditulis pemegang akses konseling.']]);
        }

        $note = BkCaseNote::query()->create([
            'case_id' => $case->id,
            'session_id' => $data['session_id'] ?? null,
            'note_type' => $type->value,
            'body' => $data['body'],
            'author_id' => $actor->id,
            'author_role' => $actor->roles->pluck('name')->first(),
            'confidentiality_level' => $level->value,
            'is_sensitive' => $isSensitive || $type === NoteType::SENSITIVE,
            'visible_to' => $data['visible_to'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);

        $this->timeline->record(CaseEventType::NOTE_ADDED, $actor, $case,
            summary: $note->is_sensitive ? 'Catatan rahasia ditambahkan.' : 'Catatan ditambahkan.',
            related: $note);

        $this->audit->record(AuditAction::NOTE_ADD, $note, $actor, [
            'confidentiality_level' => $level->value,
            'changes' => ['note_type' => ['from' => null, 'to' => $type->value]],
        ]);

        return $note;
    }

    /** Perbaiki catatan dengan menyisipkan versi baru (non-destruktif). */
    public function amend(User $actor, BkCaseNote $note, string $reason, string $newBody): BkCaseNote
    {
        $this->access->assertCanViewNote($actor, $note);

        if (! $this->access->canViewCounselingContent($actor, $note->case)) {
            throw new BkValidationException(['note' => ['Hanya pemegang akses konseling yang dapat memperbaiki catatan ini.']]);
        }

        $amendment = BkCaseNote::query()->create([
            'case_id' => $note->case_id,
            'session_id' => $note->session_id,
            'note_type' => NoteType::AMENDMENT->value,
            'body' => $newBody,
            'author_id' => $actor->id,
            'author_role' => $actor->roles->pluck('name')->first(),
            'confidentiality_level' => $note->confidentiality_level->value,
            'is_sensitive' => $note->is_sensitive,
            'amended_from_id' => $note->id,
            'occurred_at' => now(),
        ]);

        $this->audit->record(AuditAction::UPDATE, $note, $actor, [
            'reason' => $reason,
            'changes' => ['body' => ['from' => '[disunting]', 'to' => '[disunting]']],
        ]);

        if ($note->case !== null) {
            $this->timeline->record(CaseEventType::NOTE_ADDED, $actor, $note->case,
                summary: 'Catatan diperbaiki: '.$reason, related: $amendment);
        }

        return $amendment;
    }

    /** @return list<BkCaseNote> */
    public function forCase(User $actor, BkCase $case): array
    {
        $this->access->assertCanViewCase($actor, $case, 'Membuka catatan kasus');

        return $case->notes()
            ->with(['author:id,name'])
            ->get()
            ->filter(fn (BkCaseNote $note): bool => $this->access->canViewNote($actor, $note))
            ->sortByDesc('occurred_at')
            ->values()
            ->all();
    }
}
