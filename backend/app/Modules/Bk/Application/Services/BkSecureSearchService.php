<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCaseConference;
use App\Modules\Bk\Models\BkDevelopmentPlan;
use App\Modules\Bk\Models\BkGroupProgram;
use App\Modules\Bk\Models\BkReferral;
use App\Modules\Bk\Models\BkRiskIndicator;

/**
 * Pencarian aman.
 *
 * Pencarian adalah tempat kebocoran paling sering terjadi, karena hasilnya
 * menggabungkan banyak tabel dan menggoda untuk "menampilkan semua yang cocok".
 * Di sini setiap jenis hasil melewati penyaring yang sama dengan layar
 * operasionalnya: **peran, izin, cakupan siswa, cakupan kasus, dan kerahasiaan**.
 *
 * Akibatnya, guru yang mencari siswa hanya menemukan identitas dasar siswa itu —
 * tanpa catatan konseling, tanpa konferensi kasus internal, tanpa asesmen
 * terbatas. Kata kunci yang cocok dengan catatan rahasia tetap tidak memunculkan
 * apa pun bagi yang tidak berhak, dan itu disengaja.
 */
class BkSecureSearchService
{
    public function __construct(
        private readonly BkAccessService $access,
        private readonly BkAuditService $audit,
    ) {
    }

    /**
     * @return array{query: string, groups: array<string, list<array<string, mixed>>>, totals: array<string, int>, redacted: bool, note: string}
     */
    public function search(User $actor, string $term, ?string $scope = null): array
    {
        $term = trim($term);
        $limit = max(1, (int) config('bk.search.max_results_per_type', 20));

        if (mb_strlen($term) < 2) {
            return [
                'query' => $term,
                'groups' => [],
                'totals' => [],
                'redacted' => false,
                'note' => 'Kata kunci minimal dua huruf.',
            ];
        }

        $groups = [];
        $totals = [];
        $redacted = false;

        if ($scope === null || $scope === 'students') {
            $students = $this->students($actor, $term, $limit);
            $groups['students'] = $students;
            $totals['students'] = count($students);
        }

        if (($scope === null || $scope === 'cases') && $this->access->isBkStaff($actor)) {
            $cases = $this->cases($actor, $term, $limit);
            $groups['cases'] = $cases;
            $totals['cases'] = count($cases);
        }

        if (($scope === null || $scope === 'referrals') && $this->access->isBkStaff($actor)) {
            $referrals = $this->referrals($actor, $term, $limit);
            $groups['referrals'] = $referrals;
            $totals['referrals'] = count($referrals);
        }

        if ($scope === null || $scope === 'conferences') {
            $conferences = $this->conferences($actor, $term, $limit);
            $groups['conferences'] = $conferences;
            $totals['conferences'] = count($conferences);
        }

        if (($scope === null || $scope === 'indicators') && $this->access->isBkStaff($actor)) {
            $indicators = $this->indicators($actor, $term, $limit);
            $groups['indicators'] = $indicators;
            $totals['indicators'] = count($indicators);
        }

        if (($scope === null || $scope === 'development') && $this->access->isBkStaff($actor)) {
            $plans = $this->developmentPlans($actor, $term, $limit);
            $groups['development_plans'] = $plans;
            $totals['development_plans'] = count($plans);
        }

        if ($scope === null || $scope === 'programs') {
            $programs = $this->programs($actor, $term, $limit);
            $groups['programs'] = $programs;
            $totals['programs'] = count($programs);
        }

        // Pencarian yang menyentuh data siswa selalu tercatat: pola pencarian
        // berulang untuk siswa tertentu adalah sinyal yang perlu dapat ditelusuri.
        if ((bool) config('bk.search.log_queries', true) && $this->access->isBkStaff($actor)) {
            $this->audit->record(AuditAction::SEARCH, $actor, $actor, [
                'term_length' => mb_strlen($term),
                'scope' => $scope,
                'totals' => $totals,
            ]);
        }

        $redacted = collect($totals)->contains(fn (int $count): bool => $count > 0) === false;

        return [
            'query' => $term,
            'groups' => $groups,
            'totals' => $totals,
            'redacted' => $redacted,
            'note' => 'Hasil sudah disaring per peran, cakupan, dan tingkat kerahasiaan. Catatan konseling tidak pernah ikut dalam pencarian.',
        ];
    }

    /** Identitas siswa sebatas yang dibutuhkan untuk menemukan orang yang tepat. */
    protected function students(User $actor, string $term, int $limit): array
    {
        $visible = $this->access->visibleStudentIds($actor);

        return Student::query()
            ->when($visible->isNotEmpty(), fn ($q) => $q->whereIn('id', $visible))
            ->where(fn ($q) => $q
                ->where('full_name', 'like', '%'.$term.'%')
                ->orWhere('nis', 'like', '%'.$term.'%')
                ->orWhere('nisn', 'like', '%'.$term.'%'))
            ->limit($limit)
            ->get(['id', 'full_name', 'nis', 'is_active'])
            ->map(fn (Student $student): array => [
                'id' => $student->id,
                'title' => $student->full_name,
                'subtitle' => $student->nis,
                'is_active' => (bool) $student->is_active,
                'link' => '/bk/students/'.$student->id,
            ])
            ->all();
    }

    protected function cases(User $actor, string $term, int $limit): array
    {
        $query = BkCase::query()->with('student:id,full_name');
        $this->access->scopeCases($query, $actor);

        return $query
            ->where(fn ($q) => $q
                ->where('case_number', 'like', '%'.$term.'%')
                ->orWhere('title', 'like', '%'.$term.'%')
                ->orWhereHas('student', fn ($student) => $student->where('full_name', 'like', '%'.$term.'%')))
            ->limit($limit)
            ->get()
            ->map(fn (BkCase $case): array => [
                'id' => $case->id,
                'title' => $case->case_number,
                'subtitle' => $case->student?->full_name,
                'status' => $case->status->value,
                'priority' => $case->priority->value,
                'confidentiality' => $case->confidentiality_level->value,
                'link' => '/bk/cases/'.$case->id,
            ])
            ->all();
    }

    protected function referrals(User $actor, string $term, int $limit): array
    {
        $query = BkReferral::query()->with('student:id,full_name');
        $this->access->scopeReferrals($query, $actor);

        return $query
            ->where(fn ($q) => $q
                ->where('referral_number', 'like', '%'.$term.'%')
                ->orWhereHas('student', fn ($student) => $student->where('full_name', 'like', '%'.$term.'%')))
            ->limit($limit)
            ->get()
            ->map(fn (BkReferral $referral): array => [
                'id' => $referral->id,
                'title' => $referral->referral_number,
                'subtitle' => $referral->student?->full_name,
                'status' => $referral->status->value,
                'confidentiality' => $referral->confidentiality_level->value,
                'link' => '/bk/referrals',
            ])
            ->all();
    }

    /**
     * Konferensi kasus.
     *
     * Konferensi internal tidak pernah muncul bagi guru/siswa/wali yang bukan
     * peserta, meskipun kata kuncinya cocok.
     */
    protected function conferences(User $actor, string $term, int $limit): array
    {
        $query = BkCaseConference::query()->with('student:id,full_name');
        $this->access->scopeConferences($query, $actor);

        return $query
            ->where(fn ($q) => $q
                ->where('conference_number', 'like', '%'.$term.'%')
                ->orWhere('title', 'like', '%'.$term.'%'))
            ->limit($limit)
            ->get()
            ->map(fn (BkCaseConference $conference): array => [
                'id' => $conference->id,
                'title' => $conference->conference_number,
                'subtitle' => $conference->title,
                'status' => $conference->status->value,
                'student' => $conference->student?->full_name,
                'link' => '/bk/conferences',
            ])
            ->all();
    }

    protected function indicators(User $actor, string $term, int $limit): array
    {
        return BkRiskIndicator::query()
            ->with('student:id,full_name')
            ->where(fn ($q) => $q
                ->where('label', 'like', '%'.$term.'%')
                ->orWhere('indicator_code', 'like', '%'.$term.'%')
                ->orWhereHas('student', fn ($student) => $student->where('full_name', 'like', '%'.$term.'%')))
            ->limit($limit)
            ->get()
            ->map(fn (BkRiskIndicator $indicator): array => [
                'id' => $indicator->id,
                'title' => $indicator->label,
                'subtitle' => $indicator->student?->full_name,
                'severity' => $indicator->severity->value,
                'status' => $indicator->status->value,
                'link' => '/bk/risk-indicators',
            ])
            ->all();
    }

    protected function developmentPlans(User $actor, string $term, int $limit): array
    {
        return BkDevelopmentPlan::query()
            ->with('student:id,full_name')
            ->where(fn ($q) => $q
                ->where('plan_number', 'like', '%'.$term.'%')
                ->orWhere('title', 'like', '%'.$term.'%'))
            ->limit($limit)
            ->get()
            ->map(fn (BkDevelopmentPlan $plan): array => [
                'id' => $plan->id,
                'title' => $plan->plan_number,
                'subtitle' => $plan->title,
                'student' => $plan->student?->full_name,
                'status' => $plan->status->value,
                'link' => '/bk/development-plans',
            ])
            ->all();
    }

    protected function programs(User $actor, string $term, int $limit): array
    {
        $query = BkGroupProgram::query();

        if ($this->access->isReferrerOnly($actor)) {
            $studentIds = array_merge(
                array_filter([$this->access->studentIdForUser($actor)]),
                $this->access->guardianStudentIds($actor),
            );

            $query->whereHas('members', fn ($member) => $member->whereIn('student_id', $studentIds === [] ? [-1] : $studentIds));
        }

        return $query
            ->where(fn ($q) => $q
                ->where('title', 'like', '%'.$term.'%')
                ->orWhere('program_number', 'like', '%'.$term.'%'))
            ->limit($limit)
            ->get()
            ->map(fn (BkGroupProgram $program): array => [
                'id' => $program->id,
                'title' => $program->title,
                'subtitle' => $program->program_number,
                'status' => $program->status->value,
                'link' => '/bk/programs',
            ])
            ->all();
    }

    /**
     * Apakah sebuah catatan dengan kerahasiaan tertentu layak muncul bagi pengguna?
     * Dipakai pengujian kebocoran dan oleh pengaya hasil pencarian di masa depan.
     */
    public function canSurface(User $actor, ConfidentialityLevel $level): bool
    {
        if ($level->rank() <= ConfidentialityLevel::STAFF_ONLY->rank()) {
            return true;
        }

        return $this->access->isBkStaff($actor);
    }
}
