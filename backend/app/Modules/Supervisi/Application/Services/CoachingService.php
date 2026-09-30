<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Models\SupCoachingSession;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Sesi coaching/mentoring pribadi antara atasan (atau mentor) dan pegawai.
 */
class CoachingService
{
    public function __construct(
        private readonly SupervisiSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $payload */
    public function create(array $payload, User $actor): SupCoachingSession
    {
        $supervisorId = isset($payload['supervisor_employee_id'])
            ? (int) $payload['supervisor_employee_id']
            : $this->employeeIdFor($actor);

        if ($supervisorId === null) {
            throw ValidationException::withMessages([
                'supervisor_employee_id' => 'Pembina (supervisor) sesi ini tidak dapat ditentukan dari akun Anda.',
            ]);
        }

        if ($supervisorId === (int) $payload['supervisee_employee_id']) {
            throw ValidationException::withMessages([
                'supervisee_employee_id' => 'Pembina dan peserta coaching tidak boleh orang yang sama.',
            ]);
        }

        $followUpDays = (int) ($this->settings->get('coaching_follow_up_days') ?: 14);

        $session = SupCoachingSession::query()->create([
            'supervisor_employee_id' => $supervisorId,
            'supervisee_employee_id' => (int) $payload['supervisee_employee_id'],
            'visit_id' => $payload['visit_id'] ?? null,
            'session_at' => $payload['session_at'] ?? Carbon::now(),
            'focus' => $payload['focus'] ?? null,
            'commitments' => $payload['commitments'] ?? [],
            'follow_up_at' => $payload['follow_up_at'] ?? Carbon::now()->addDays($followUpDays),
            'status' => 'OPEN',
            'note' => $payload['note'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->audit->log('coaching.created', $session, null, $session->toArray(), null, $actor);

        return $session;
    }

    /** @param array<string,mixed> $payload */
    public function update(SupCoachingSession $session, array $payload, User $actor): SupCoachingSession
    {
        $before = $this->audit->diff($session);

        $session->fill(array_filter([
            'session_at' => $payload['session_at'] ?? null,
            'focus' => $payload['focus'] ?? null,
            'commitments' => $payload['commitments'] ?? null,
            'follow_up_at' => $payload['follow_up_at'] ?? null,
            'note' => $payload['note'] ?? null,
            'status' => $payload['status'] ?? null,
        ], fn ($value) => $value !== null))->save();

        $this->audit->log('coaching.updated', $session, $before, $this->audit->diff($session), null, $actor);

        return $session->refresh();
    }

    /** Menutup sesi dengan catatan hasil tindak lanjut. */
    public function close(SupCoachingSession $session, string $note, User $actor): SupCoachingSession
    {
        if ($session->status === 'CLOSED') {
            throw ValidationException::withMessages(['status' => 'Sesi coaching ini sudah ditutup.']);
        }

        $before = $this->audit->diff($session);
        $session->forceFill(['status' => 'CLOSED', 'note' => $note])->save();
        $this->audit->log('coaching.closed', $session, $before, $this->audit->diff($session), $note, $actor);

        return $session->refresh();
    }

    /**
     * Daftar sesi + sesi yang tindak lanjutnya jatuh tempo.
     *
     * @return array{rows:list<array<string,mixed>>,due_follow_ups:int,open:int}
     */
    public function list(array $filters, User $actor): array
    {
        $query = SupCoachingSession::query()
            ->with(['supervisor:id,full_name', 'supervisee:id,full_name,position'])
            ->orderByDesc('session_at')
            ->orderByDesc('id');

        if (! $this->canSeeAll($actor)) {
            $employeeId = $this->employeeIdFor($actor);

            $query->where(function ($builder) use ($employeeId, $actor): void {
                $builder->where('created_by', $actor->id);

                if ($employeeId !== null) {
                    $builder->orWhere('supervisor_employee_id', $employeeId)
                        ->orWhere('supervisee_employee_id', $employeeId);
                }
            });
        }

        if (! empty($filters['employee_id'])) {
            $query->where(function ($builder) use ($filters): void {
                $builder->where('supervisee_employee_id', (int) $filters['employee_id'])
                    ->orWhere('supervisor_employee_id', (int) $filters['employee_id']);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $rows = $query->limit((int) ($filters['limit'] ?? 100))->get();
        $today = Carbon::today();

        return [
            'rows' => $rows->map(fn (SupCoachingSession $row) => $this->present($row))->all(),
            'open' => $rows->where('status', 'OPEN')->count(),
            'due_follow_ups' => $rows->filter(
                fn (SupCoachingSession $row) => $row->status === 'OPEN'
                    && $row->follow_up_at !== null
                    && $row->follow_up_at->lte($today->copy()->endOfDay())
            )->count(),
        ];
    }

    /** @return array<string,mixed> */
    protected function present(SupCoachingSession $row): array
    {
        return [
            'id' => $row->id,
            'session_at' => $row->session_at?->toIso8601String(),
            'focus' => $row->focus,
            'commitments' => $row->commitments ?? [],
            'follow_up_at' => $row->follow_up_at?->toIso8601String(),
            'status' => $row->status,
            'note' => $row->note,
            'visit_id' => $row->visit_id,
            'supervisor' => $row->supervisor === null ? null : [
                'id' => $row->supervisor->id,
                'full_name' => $row->supervisor->full_name,
            ],
            'supervisee' => $row->supervisee === null ? null : [
                'id' => $row->supervisee->id,
                'full_name' => $row->supervisee->full_name,
                'position' => $row->supervisee->position,
            ],
        ];
    }

    protected function canSeeAll(User $actor): bool
    {
        return $actor->can('supervisi.coaching.manage')
            || $actor->can('supervisi.manage')
            || $actor->can('supervisi.audit.view');
    }

    protected function employeeIdFor(User $actor): ?int
    {
        $id = $actor->userable_id ?? null;

        return $actor->userable_type === \App\Models\Employee::class && $id !== null ? (int) $id : null;
    }
}
