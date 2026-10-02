<?php

namespace App\Modules\Hrd\Core\Services;

use App\Models\User;
use App\Modules\Hrd\Core\Domain\Contracts\HelpdeskProviderInterface;
use App\Modules\Hrd\Core\Models\HrServiceTicket;
use App\Modules\Hrd\Core\Models\HrServiceTicketComment;
use App\Modules\Hrd\Core\Support\HrdPhase2Events;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use Carbon\Carbon;
use Throwable;

/**
 * HR Service Center (§77).
 *
 * Bila ERP sudah memiliki Helpdesk, tiket HRD dikirim ke sana lewat
 * `HelpdeskProviderInterface` dan nomor tiketnya disimpan di sini. Bila belum,
 * HRD tetap bekerja dengan tiket lokal — tidak ada modul ticketing kedua yang
 * dipaksakan.
 */
class HRServiceCenterService
{
    /** SLA per prioritas (jam kerja). */
    private const SLA_HOURS = [
        'URGENT' => 4,
        'HIGH' => 8,
        'NORMAL' => 24,
        'LOW' => 72,
    ];

    public function __construct(
        private readonly HelpdeskProviderInterface $helpdesk,
        private readonly HrdPhase2Events $events,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function open(HrEmployee $employee, array $attributes, User $actor): HrServiceTicket
    {
        $category = strtoupper((string) ($attributes['category'] ?? 'OTHER'));
        $priority = strtoupper((string) ($attributes['priority'] ?? 'NORMAL'));

        $allowedCategories = ['PAYROLL', 'ATTENDANCE', 'LEAVE', 'BENEFITS', 'DOCUMENTS', 'EMPLOYMENT', 'TRAINING', 'PERFORMANCE', 'OTHER'];

        if (! in_array($category, $allowedCategories, true)) {
            throw HrdValidationException::forField('category', "Kategori \"{$category}\" tidak dikenal.", 'service_center.category_invalid');
        }

        $slaHours = self::SLA_HOURS[$priority] ?? 24;

        $ticket = HrServiceTicket::query()->create([
            'code' => sprintf('HRT-%s-%05d', now()->format('Y'), HrServiceTicket::query()->whereYear('created_at', now()->year)->count() + 1),
            'employee_id' => $employee->getKey(),
            'category' => $category,
            'subject' => $attributes['subject'] ?? 'Permintaan HR',
            'description' => $attributes['description'] ?? null,
            'priority' => $priority,
            'status' => 'OPEN',
            'sla_due_at' => now()->addHours($slaHours),
        ]);

        $this->forwardToHelpdesk($ticket, $actor);

        $this->events->emit('GOVERNANCE', 'service_ticket.opened', [
            'actor' => $actor,
            'employee_id' => $employee->getKey(),
            'subject_type' => HrServiceTicket::class,
            'subject_id' => $ticket->getKey(),
            'title' => 'Tiket HR: '.$ticket->subject,
            'payload' => ['category' => $category, 'priority' => $priority, 'sla_due_at' => $ticket->sla_due_at?->toDateTimeString()],
        ]);

        return $ticket->refresh();
    }

    public function assign(HrServiceTicket $ticket, int $userId, User $actor): HrServiceTicket
    {
        $ticket->forceFill([
            'assigned_to_user_id' => $userId,
            'status' => in_array($ticket->status, ['OPEN', 'ASSIGNED'], true) ? 'IN_PROGRESS' : $ticket->status,
        ])->save();

        return $ticket->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function comment(HrServiceTicket $ticket, array $attributes, User $actor): HrServiceTicketComment
    {
        $comment = HrServiceTicketComment::query()->create([
            'ticket_id' => $ticket->getKey(),
            'author_user_id' => $actor->getKey(),
            'author_employee_id' => $attributes['author_employee_id'] ?? null,
            'body' => $attributes['body'] ?? '',
            'is_internal' => (bool) ($attributes['is_internal'] ?? false),
            'attachment_path' => $attributes['attachment_path'] ?? null,
        ]);

        if ($ticket->first_response_at === null && ! $comment->is_internal) {
            $ticket->forceFill(['first_response_at' => now(), 'status' => $ticket->status === 'OPEN' ? 'IN_PROGRESS' : $ticket->status])->save();
        }

        return $comment;
    }

    public function resolve(HrServiceTicket $ticket, User $actor, string $resolution): HrServiceTicket
    {
        $ticket->forceFill([
            'status' => 'RESOLVED',
            'resolved_at' => now(),
            'resolution' => $resolution,
        ])->save();

        try {
            if ($ticket->helpdesk_reference !== null) {
                $this->helpdesk->close($ticket->helpdesk_reference, (int) $actor->getKey(), $resolution);
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->events->emit('GOVERNANCE', 'service_ticket.resolved', [
            'actor' => $actor,
            'employee_id' => $ticket->employee_id,
            'subject_type' => HrServiceTicket::class,
            'subject_id' => $ticket->getKey(),
            'title' => 'Tiket HR diselesaikan: '.$ticket->code,
        ]);

        return $ticket->refresh();
    }

    public function close(HrServiceTicket $ticket, User $actor, ?int $satisfactionScore = null): HrServiceTicket
    {
        $ticket->forceFill([
            'status' => 'CLOSED',
            'satisfaction_score' => $satisfactionScore ?? $ticket->satisfaction_score,
        ])->save();

        return $ticket->refresh();
    }

    /** @return list<array<string, mixed>> */
    public function myTickets(int $employeeId): array
    {
        return HrServiceTicket::query()
            ->where('employee_id', $employeeId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (HrServiceTicket $ticket) => [
                'code' => $ticket->code,
                'subject' => $ticket->subject,
                'category' => $ticket->category,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'sla_due_at' => $ticket->sla_due_at?->toDateTimeString(),
                'resolution' => $ticket->resolution,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    public function queue(?string $status = null, ?int $category = null): array
    {
        $tickets = HrServiceTicket::query()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByRaw("case priority when 'URGENT' then 1 when 'HIGH' then 2 when 'NORMAL' then 3 else 4 end")
            ->limit(200)
            ->get();

        $breached = $tickets->filter(fn (HrServiceTicket $ticket) => $ticket->sla_due_at !== null && $ticket->sla_due_at->isPast() && ! in_array($ticket->status, ['RESOLVED', 'CLOSED'], true));

        return [
            'open' => $tickets->whereIn('status', ['OPEN', 'ASSIGNED', 'IN_PROGRESS'])->count(),
            'resolved' => $tickets->where('status', 'RESOLVED')->count(),
            'closed' => $tickets->where('status', 'CLOSED')->count(),
            'sla_breached' => $breached->count(),
            'by_category' => $tickets->groupBy('category')->map->count()->all(),
            'average_satisfaction' => $tickets->whereNotNull('satisfaction_score')->avg('satisfaction_score'),
            'breached_tickets' => $breached->map->only(['code', 'subject', 'priority', 'sla_due_at'])->values()->all(),
        ];
    }

    private function forwardToHelpdesk(HrServiceTicket $ticket, User $actor): void
    {
        try {
            $result = $this->helpdesk->open([
                'reference' => $ticket->code,
                'subject' => $ticket->subject,
                'body' => $ticket->description,
                'priority' => $ticket->priority,
                'category' => $ticket->category,
            ], (int) $actor->getKey());

            if (($result['reference'] ?? null) !== null) {
                $ticket->forceFill(['helpdesk_reference' => $result['reference']])->save();
            }
        } catch (Throwable $exception) {
            // Helpdesk belum terpasang: tiket lokal tetap sah.
            report($exception);
        }
    }
}
