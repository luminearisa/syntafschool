<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\CareerExplorationStatus;
use App\Modules\Bk\Domain\Enums\GuidanceFocus;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCareerGuidanceSession;
use App\Modules\Bk\Models\BkCareerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Bimbingan karier.
 *
 * Prinsip yang menjaga fitur ini tidak berubah menjadi alat pengunci:
 * **hasil asesmen adalah salah satu masukan, bukan penetapan.** Modul ini tidak
 * pernah menolak siswa yang ingin berpindah arah, tidak pernah menyembunyikan
 * pilihan alternatif, dan selalu menyimpan alasan di balik sebuah arah pilihan.
 */
class BkCareerGuidanceService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkAuditService $audit,
        private readonly BkAccessService $access,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginateProfiles(User $actor, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = BkCareerProfile::query()->with('student:id,full_name,nis');

        $visible = $this->access->visibleStudentIds($actor);

        // Koleksi kosong berarti pemakainya adalah staf BK yang tidak dibatasi
        // cakupan siswa; koleksi berisi berarti batasnya memang daftar itu.
        if ($visible->isNotEmpty()) {
            $query->whereIn('student_id', $visible);
        }

        return $query
            ->when($filters['exploration_status'] ?? null, fn ($q, $status) => $q->where('exploration_status', $status))
            ->when($filters['student_id'] ?? null, fn ($q, $id) => $q->where('student_id', (int) $id))
            ->orderByDesc('updated_at')
            ->paginate($perPage);
    }

    /**
     * Membuat atau memperbarui profil karier.
     *
     * Data dibagi dua kelompok dengan sengaja:
     *   - `interests`/`aspiration`/`skills`: hal yang dipegang siswa sendiri;
     *   - `assessment_inputs`: hasil asesmen/inventori, disimpan sebagai masukan.
     * Pemisahan itu membuat satu skor tidak pernah menggantikan suara siswa.
     *
     * @param array<string, mixed> $data
     */
    public function upsertProfile(User $actor, int $studentId, array $data): BkCareerProfile
    {
        $this->access->assertCanViewSupportProfile($actor, $studentId);

        return DB::transaction(function () use ($actor, $studentId, $data): BkCareerProfile {
            $profile = BkCareerProfile::query()->firstOrNew(['student_id' => $studentId]);

            $profile->fill([
                'aspiration' => $data['aspiration'] ?? $profile->aspiration,
                'interests' => $data['interests'] ?? $profile->interests,
                'strengths' => $data['strengths'] ?? $profile->strengths,
                'skills' => $data['skills'] ?? $profile->skills,
                'preferred_subjects' => $data['preferred_subjects'] ?? $profile->preferred_subjects,
                'assessment_inputs' => $data['assessment_inputs'] ?? $profile->assessment_inputs,
                'university_plan' => $data['university_plan'] ?? $profile->university_plan,
                'education_plan' => $data['education_plan'] ?? $profile->education_plan,
                'action_plan' => $data['action_plan'] ?? $profile->action_plan,
                'target_institution' => $data['target_institution'] ?? $profile->target_institution,
                'target_major' => $data['target_major'] ?? $profile->target_major,
                'alternative_options' => $data['alternative_options'] ?? $profile->alternative_options,
                'notes' => $data['notes'] ?? $profile->notes,
                'guidance_status' => $data['guidance_status'] ?? $profile->guidance_status ?? 'NOT_STARTED',
                'last_reviewed_at' => now(),
                'created_by' => $profile->created_by ?? $actor->id,
                'updated_by' => $actor->id,
            ]);

            if (isset($data['exploration_status'])) {
                $profile->exploration_status = $this->assertTransition(
                    $profile->exploration_status ?? CareerExplorationStatus::NOT_STARTED,
                    CareerExplorationStatus::from((string) $data['exploration_status']),
                );
            }

            $profile->save();

            $this->audit->record(AuditAction::CAREER_UPDATE, $profile, $actor, [
                'student_id' => $studentId,
                'exploration_status' => $profile->exploration_status->value,
                'has_assessment_inputs' => ! empty($profile->assessment_inputs),
            ]);

            return $profile->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function recordSession(User $actor, int $studentId, array $data): BkCareerGuidanceSession
    {
        $this->access->assertCanViewSupportProfile($actor, $studentId);

        if (empty($data['focus'])) {
            throw new BkValidationException(['focus' => ['Setiap sesi bimbingan karier wajib memiliki fokus.']]);
        }

        $session = BkCareerGuidanceSession::query()->create([
            'session_number' => $this->numbers->next('BK_CAREER_SESSION', 'BK-CG'),
            'student_id' => $studentId,
            'case_id' => $data['case_id'] ?? null,
            'counselor_user_id' => $data['counselor_user_id'] ?? $actor->id,
            'focus' => GuidanceFocus::from((string) $data['focus']),
            'session_at' => $data['session_at'] ?? now(),
            'summary' => $data['summary'] ?? null,
            'options_explored' => $data['options_explored'] ?? null,
            'action_items' => $data['action_items'] ?? null,
            'next_steps' => $data['next_steps'] ?? null,
            'next_session_date' => $data['next_session_date'] ?? null,
            'confidentiality_level' => $data['confidentiality_level'] ?? \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::STAFF_ONLY,
            'created_by' => $actor->id,
        ]);

        // Profil ikut diperbarui agar riwayat bimbingan tidak terpisah dari arah
        // yang sedang dijelajahi siswa.
        $profile = BkCareerProfile::query()->firstOrNew(['student_id' => $studentId]);
        $profile->guidance_status = 'ONGOING';
        $profile->last_reviewed_at = now();
        $profile->created_by = $profile->created_by ?? $actor->id;
        $profile->save();

        $this->audit->record(AuditAction::CAREER_UPDATE, $session, $actor, [
            'student_id' => $studentId,
            'focus' => $session->focus->value,
        ]);

        return $session->refresh();
    }

    /** Riwayat sesi bimbingan satu siswa. */
    public function sessions(User $actor, int $studentId): \Illuminate\Database\Eloquent\Collection
    {
        $this->access->assertCanViewSupportProfile($actor, $studentId);

        return BkCareerGuidanceSession::query()
            ->where('student_id', $studentId)
            ->with('counselor:id,name')
            ->orderByDesc('session_at')
            ->get();
    }

    /**
     * Ringkasan untuk profil siswa: termasuk penegasan bahwa arah masih terbuka,
     * supaya tampilan tidak pernah menyiratkan pilihan sudah final.
     */
    public function summaryForStudent(int $studentId): array
    {
        $profile = BkCareerProfile::query()->where('student_id', $studentId)->first();

        return [
            'has_profile' => $profile !== null,
            'aspiration' => $profile?->aspiration,
            'exploration_status' => $profile?->exploration_status?->value ?? CareerExplorationStatus::NOT_STARTED->value,
            'guidance_status' => $profile?->guidance_status ?? 'NOT_STARTED',
            'target' => [
                'institution' => $profile?->target_institution,
                'major' => $profile?->target_major,
            ],
            'alternatives' => $profile?->alternative_options ?? [],
            'assessment_inputs_present' => ! empty($profile?->assessment_inputs),
            'sessions' => BkCareerGuidanceSession::query()->where('student_id', $studentId)->count(),
            'last_session_at' => BkCareerGuidanceSession::query()->where('student_id', $studentId)->max('session_at'),
            'note' => 'Arah karier masih dapat berubah: hasil asesmen hanya salah satu masukan, bukan penetapan.',
        ];
    }

    /** Perpindahan tahap penjelajahan; semua arah masih terbuka selama belum final. */
    protected function assertTransition(CareerExplorationStatus $from, CareerExplorationStatus $to): CareerExplorationStatus
    {
        if ($from === $to || in_array($to, $from->allowedTransitions(), true)) {
            return $to;
        }

        throw new BkValidationException([
            'exploration_status' => ["Tahap penjelajahan tidak dapat berpindah dari {$from->label()} ke {$to->label()}."],
        ]);
    }
}
