<?php

namespace App\Modules\Workflow\Domain\Exceptions;

use App\Modules\Workflow\Models\WorkflowDecision;
use App\Modules\Workflow\Models\WorkflowInstance;

/**
 * Konflik keadaan (HTTP 409).
 *
 * Dipakai ketika approver kedua kali menekan tombol yang sama, atau ketika
 * dua permintaan paralel menyentuh step yang sama (§64). Konflik **bukan**
 * kegagalan: keputusan pertama tetap sah, dan respons membawa hasilnya agar
 * klien dapat menampilkannya alih-alih mengulang aksi.
 */
class WorkflowConflict extends WorkflowException
{
    public function statusCode(): int
    {
        return 409;
    }

    public static function alreadyDecided(WorkflowDecision $decision): self
    {
        return new self(
            'Keputusan untuk step ini sudah tercatat dan tidak dapat diubah.',
            [
                'decision_id' => $decision->id,
                'decision' => $decision->decision->value,
                'decided_by' => $decision->actor_id,
                'decided_at' => $decision->decided_at?->toIso8601String(),
            ],
        );
    }

    public static function notOpen(WorkflowInstance $instance): self
    {
        return new self(
            "Request {$instance->code} sudah berstatus {$instance->status->value} dan tidak dapat diproses lagi.",
            ['status' => $instance->status->value],
        );
    }

    public static function locked(string $message = 'Step ini sedang diproses oleh permintaan lain. Coba lagi.'): self
    {
        return new self($message);
    }
}
