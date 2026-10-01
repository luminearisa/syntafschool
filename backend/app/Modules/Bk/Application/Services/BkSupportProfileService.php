<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Contracts\AcademicProviderInterface;
use App\Modules\Bk\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\Bk\Domain\Contracts\BoardingProviderInterface;
use App\Modules\Bk\Domain\Contracts\ClinicSupportProviderInterface;
use App\Modules\Bk\Domain\Contracts\DisciplineProviderInterface;
use App\Modules\Bk\Domain\Contracts\StudentActivityProviderInterface;
use App\Modules\Bk\Domain\Contracts\StudentProviderInterface;
use App\Modules\Bk\Models\BkAppointment;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkFollowUp;
use App\Modules\Bk\Models\BkIntervention;
use App\Modules\Bk\Models\BkSupportRequest;
use Illuminate\Support\Collection;

/**
 * Profil Dukungan Siswa — satu layar untuk memahami seorang anak.
 *
 * Bagian akademik, kehadiran, kedisiplinan, asrama, kesehatan, dan aktivitas
 * **tidak disalin** ke tabel BK: semuanya dibaca saat dibutuhkan dari modul
 * pemiliknya lewat kontrak integrasi. Bagian BK sendiri disaring sesuai izin
 * pemanggil — wali kelas melihat ringkasan, konselor melihat isi penanganan,
 * sementara siswa/orang tua hanya melihat apa yang memang dibagikan.
 */
class BkSupportProfileService
{
    public function __construct(
        private readonly StudentProviderInterface $students,
        private readonly AcademicProviderInterface $academic,
        private readonly AttendanceProviderInterface $attendance,
        private readonly DisciplineProviderInterface $discipline,
        private readonly BoardingProviderInterface $boarding,
        private readonly ClinicSupportProviderInterface $clinic,
        private readonly StudentActivityProviderInterface $activity,
        private readonly BkAccessService $access,
    ) {
    }

    /**
     * Susun profil dukungan.
     *
     * @return array<string, mixed>
     */
    public function build(User $actor, int $studentId): array
    {
        if (! $this->access->canViewSupportProfile($actor, $studentId)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Anda tidak memiliki akses ke profil dukungan siswa ini.');
        }

        $snapshot = $this->students->snapshot($studentId);
        $canSeeBk = $this->access->isBkStaff($actor);
        $canSeeCounseling = $canSeeBk && ($this->access->isCoordinator($actor) || $this->access->isBkAdmin($actor));

        $cases = BkCase::query()->forStudent($studentId)->orderByDesc('opened_at')->get();

        if (! $canSeeBk) {
            $cases = $cases->filter(fn (BkCase $case): bool => $this->access->canViewCase($actor, $case))->values();
        }

        $visibleCaseIds = $cases->pluck('id');

        return [
            'student' => $snapshot,
            'academic' => $this->academic->summary($studentId),
            'attendance' => $this->attendance->summary($studentId, now()->subDays(90)->toDateString(), now()->toDateString()),
            'discipline' => $this->discipline->summary($studentId, now()->subDays(180)->toDateString(), now()->toDateString()),
            'boarding' => $this->boarding->summary($studentId),
            'health' => $this->clinic->summary($studentId),
            'development' => $this->activity->developmentSignals($studentId, $actor->id),
            'cases' => $canSeeBk ? $cases->map(fn (BkCase $case): array => $this->caseSummary($case, $actor))->all() : [],
            'active_case_count' => $cases->filter(fn (BkCase $case): bool => $case->status->isOpen())->count(),
            'interventions' => $canSeeCounseling ? $this->interventions($visibleCaseIds) : [],
            'follow_ups' => $canSeeBk ? $this->followUps($actor, $visibleCaseIds) : [],
            'appointments' => $this->appointments($actor, $studentId),
            'support_requests' => $this->supportRequests($studentId),
            'timeline' => $canSeeBk ? $this->timeline($cases) : [],
            'visibility' => [
                'cases' => $canSeeBk,
                'counseling' => $canSeeCounseling,
                'note' => $canSeeCounseling
                    ? 'Anda dapat membuka catatan konseling untuk siswa ini.'
                    : 'Catatan konseling tidak ditampilkan pada profil ini.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function caseSummary(BkCase $case, User $actor): array
    {
        return [
            'id' => $case->id,
            'case_number' => $case->case_number,
            'case_type' => $case->case_type->value,
            'case_type_label' => $case->case_type->label(),
            'status' => $case->status->value,
            'status_label' => $case->status->label(),
            'priority' => $case->priority->value,
            'opened_at' => $case->opened_at?->toDateString(),
            'target_review_date' => $case->target_review_date?->toDateString(),
            'counselor' => $case->counselor?->name,
            'confidentiality' => $this->access->canViewCounselingContent($actor, $case)
                ? $case->confidentiality_level->value
                : null,
        ];
    }

    /** @param Collection<int, int> $caseIds */
    private function interventions(Collection $caseIds): array
    {
        if ($caseIds->isEmpty()) {
            return [];
        }

        return BkIntervention::query()
            ->whereIn('case_id', $caseIds)
            ->orderByDesc('start_date')
            ->limit(20)
            ->get()
            ->map(fn (BkIntervention $row): array => [
                'id' => $row->id,
                'type' => $row->type->value,
                'type_label' => $row->type->label(),
                'title' => $row->title,
                'status' => $row->status->value,
                'start_date' => $row->start_date?->toDateString(),
                'target_date' => $row->target_date?->toDateString(),
                'result' => $row->result,
            ])
            ->all();
    }

    /** @param Collection<int, int> $caseIds */
    private function followUps(User $actor, Collection $caseIds): array
    {
        if ($caseIds->isEmpty()) {
            return [];
        }

        return BkFollowUp::query()
            ->whereIn('case_id', $caseIds)
            ->orderBy('due_date')
            ->limit(20)
            ->get()
            ->map(fn (BkFollowUp $row): array => [
                'id' => $row->id,
                'title' => $row->title,
                'type' => $row->type->value,
                'status' => $row->status->value,
                'due_date' => $row->due_date?->toDateString(),
                'is_overdue' => (bool) $row->is_overdue,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function appointments(User $actor, int $studentId): array
    {
        return BkAppointment::query()
            ->where('student_id', $studentId)
            ->orderByDesc('scheduled_start')
            ->limit(10)
            ->get()
            ->map(fn (BkAppointment $row): array => [
                'id' => $row->id,
                'number' => $row->appointment_number,
                'scheduled_start' => $row->scheduled_start?->toDateTimeString(),
                'status' => $row->status->value,
                'mode' => $row->mode->value,
                'counselor' => $row->counselor?->name,
                'purpose' => $this->access->canViewCounselingContent($actor, $row->case ?? new BkCase()) ? $row->purpose : null,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function supportRequests(int $studentId): array
    {
        return BkSupportRequest::query()
            ->where('student_id', $studentId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (BkSupportRequest $row): array => [
                'id' => $row->id,
                'number' => $row->request_number,
                'type' => $row->request_type->value,
                'topic' => $row->topic,
                'status' => $row->status->value,
                'created_at' => $row->created_at?->toDateString(),
            ])
            ->all();
    }

    /** @param Collection<int, BkCase> $cases */
    private function timeline(Collection $cases): array
    {
        $events = collect();

        foreach ($cases as $case) {
            foreach (app(BkTimelineService::class)->forCase($case) as $event) {
                $events->push([
                    'case_number' => $case->case_number,
                    'event' => $event->event_type->value,
                    'event_label' => $event->event_type->label(),
                    'summary' => $event->summary,
                    'actor' => $event->actor?->name,
                    'occurred_at' => $event->occurred_at?->toDateTimeString(),
                ]);
            }
        }

        return $events->sortByDesc('occurred_at')->take(50)->values()->all();
    }
}
