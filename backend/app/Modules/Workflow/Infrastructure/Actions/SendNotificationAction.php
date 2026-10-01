<?php

namespace App\Modules\Workflow\Infrastructure\Actions;

use App\Modules\Workflow\Application\Services\WorkflowNotificationService;
use App\Modules\Workflow\Domain\Contracts\WorkflowActionInterface;
use App\Modules\Workflow\Domain\Enums\NotificationEvent;
use App\Modules\Workflow\Domain\Support\WorkflowActionContext;
use InvalidArgumentException;

/**
 * Aksi bawaan: kirim notifikasi ke pihak yang ditentukan definisi (§67).
 *
 * ```json
 * { "handler": "notification.send",
 *   "params": { "to": "requester|approvers|observers|managers|user:12",
 *               "event": "WORKFLOW_COMPLETED",
 *               "title": "Pengadaan disetujui",
 *               "body": "..." } }
 * ```
 */
final class SendNotificationAction implements WorkflowActionInterface
{
    public function __construct(
        private readonly WorkflowNotificationService $notifications,
    ) {
    }

    public function alias(): string
    {
        return 'notification.send';
    }

    public function description(): string
    {
        return 'Kirim notifikasi workflow ke requester/approver/pengamat/pengguna tertentu.';
    }

    public function execute(WorkflowActionContext $context, array $params = []): array
    {
        $event = NotificationEvent::tryFrom((string) ($params['event'] ?? 'WORKFLOW_COMPLETED'))
            ?? NotificationEvent::WORKFLOW_COMPLETED;

        $recipients = $this->resolveRecipients($context, (string) ($params['to'] ?? 'requester'));

        if ($recipients === []) {
            return ['sent' => 0, 'reason' => 'Penerima tidak ditemukan.'];
        }

        $title = (string) ($params['title'] ?? 'Pembaruan Workflow');
        $body = (string) ($params['body'] ?? ('Request '.$context->instanceCode().' diperbarui.'));

        $sent = $this->notifications->dispatch(
            event: $event,
            instance: $context->instance,
            recipients: $recipients,
            title: $title,
            body: $body,
            payload: ['hook' => $context->hook, 'step_key' => $context->stepInstance?->node_key],
            step: $context->stepInstance,
            channels: isset($params['channels']) ? (array) $params['channels'] : null,
            keySuffix: $context->hook.':'.$event->value,
            actionUrl: $params['action_url'] ?? null,
        );

        return ['sent' => count($sent), 'event' => $event->value];
    }

    /**
     * @return list<int>
     */
    protected function resolveRecipients(WorkflowActionContext $context, string $to): array
    {
        $tokens = array_map('trim', explode(',', $to));

        $ids = [];

        foreach ($tokens as $token) {
            if (str_starts_with($token, 'user:')) {
                $ids[] = (int) substr($token, 5);

                continue;
            }

            $ids = array_merge($ids, match ($token) {
                'requester' => [(int) $context->instance->requester_id],
                'approvers' => $context->instance->approvals()->where('status', 'PENDING')->pluck('approver_id')->all(),
                'deciders' => $context->instance->decisions()->pluck('actor_id')->all(),
                'observers' => array_map('intval', array_filter((array) ($context->version?->config['observers'] ?? []), 'is_numeric')),
                'managers' => $this->managersOfRequester($context),
                default => throw new InvalidArgumentException("Penerima notifikasi `{$token}` tidak dikenal."),
            });
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /** @return list<int> */
    protected function managersOfRequester(WorkflowActionContext $context): array
    {
        $requesterId = (int) $context->instance->requester_id;

        return array_values(array_filter([
            app(\App\Modules\Workflow\Domain\Contracts\OrganizationalDirectoryInterface::class)->managerOf($requesterId),
            app(\App\Modules\Workflow\Domain\Contracts\OrganizationalDirectoryInterface::class)->backupManagerOf($requesterId),
        ]));
    }
}
