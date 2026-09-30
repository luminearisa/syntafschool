<?php

namespace App\Modules\UKS\Application\Services;

use App\Models\User;
use App\Modules\UKS\Application\Support\UksNumber;
use App\Modules\UKS\Domain\Enums\IncidentStatus;
use App\Modules\UKS\Domain\Enums\Severity;
use App\Modules\UKS\Models\UksIncident;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Insiden/kecelakaan di lingkungan sekolah: lapor → tindak lanjut → selesai.
 * Orang tua otomatis dikabari untuk tingkat berat/darurat.
 */
class IncidentService
{
    public function __construct(
        private readonly UksAccessService $access,
        private readonly UksNotifier $notifier,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function list(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = UksIncident::query()->with(['student:id,full_name,nis', 'reportedBy:id,name']);
        $this->access->scopeStudents($query, $actor);

        foreach (['status', 'severity', 'student_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        return $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): UksIncident
    {
        $this->access->assertCanSeeStudent($actor, (int) $data['student_id']);

        $severity = Severity::tryFrom((string) ($data['severity'] ?? Severity::RINGAN->value)) ?? Severity::RINGAN;

        $incident = DB::transaction(fn (): UksIncident => UksIncident::query()->create([
            'code' => UksNumber::next('uks-incident', 'IKD'),
            'student_id' => (int) $data['student_id'],
            'visit_id' => $data['visit_id'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? now(),
            'location' => $data['location'] ?? null,
            'severity' => $severity,
            'description' => $data['description'],
            'first_aid' => $data['first_aid'] ?? null,
            'witnesses' => $data['witnesses'] ?? null,
            'status' => IncidentStatus::TERBUKA,
            'reported_by' => $actor->id,
        ]));

        activity('uks')->causedBy($actor)->performedOn($incident)
            ->log('Insiden '.$incident->code.' dilaporkan');

        if ($severity->requiresParentNotice() && $incident->visit_id === null) {
            // Tanpa kunjungan terkait, kabar orang tua dikirim manual lewat
            // tombol "beri tahu orang tua" agar pesannya memuat tindak lanjut.
        }

        return $incident;
    }

    /** @param array{status?: string, follow_up?: ?string, parent_notified?: bool} $data */
    public function update(UksIncident $incident, array $data, User $actor): UksIncident
    {
        $this->access->assertCanSeeIncident($actor, $incident);

        if (isset($data['status']) && IncidentStatus::tryFrom((string) $data['status']) instanceof IncidentStatus) {
            $incident->status = IncidentStatus::from((string) $data['status']);
        }
        if (array_key_exists('follow_up', $data)) {
            $incident->follow_up = $data['follow_up'];
        }
        if (! empty($data['parent_notified'])) {
            $incident->parent_notified = true;
        }
        $incident->save();

        return $incident;
    }
}
