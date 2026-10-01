<?php

namespace App\Modules\Workflow\Domain\Support;

use App\Models\User;

/**
 * Satu pesan notifikasi workflow yang siap dikirim (§67).
 */
final class NotificationMessage
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly string $event,
        public readonly string $channel,
        public readonly ?User $recipient,
        public readonly string $title,
        public readonly string $body,
        public readonly array $payload = [],
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $actionUrl = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'channel' => $this->channel,
            'recipient_id' => $this->recipient?->id,
            'title' => $this->title,
            'body' => $this->body,
            'payload' => $this->payload,
            'action_url' => $this->actionUrl,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
