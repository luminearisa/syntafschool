<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Contracts\AcademicProviderInterface;
use App\Modules\Bk\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\Bk\Domain\Contracts\BoardingProviderInterface;
use App\Modules\Bk\Domain\Contracts\DisciplineProviderInterface;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\CaseEventType;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Domain\Enums\ScreeningOutcome;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkReferral;
use App\Modules\Bk\Models\BkScreening;
use Illuminate\Support\Facades\DB;

/**
 * Screening awal sebelum kasus ditangani.
 *
 * Screening **bukan diagnosis**: ia mengumpulkan sudut pandang (siswa, guru,
 * orang tua) dan indikator yang sudah ada di modul lain — akademik, kehadiran,
 * perilaku, asrama — lalu merekomendasikan langkah. Indikator diambil otomatis
 * dari provider modul terkait pada rentang 90 hari terakhir, sehingga konselor
 * tidak perlu menyalin data.
 */
class BkScreeningService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkTimelineService $timeline,
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
        private readonly BkAccessService $access,
        private readonly AcademicProviderInterface $academic,
        private readonly AttendanceProviderInterface $attendance,
        private readonly DisciplineProviderInterface $discipline,
        private readonly BoardingProviderInterface $boarding,
    ) {
    }

    /**
     * Jalankan screening untuk satu referral atau kasus.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(User $actor, BkReferral|BkCase $subject, array $data): BkScreening
    {
        $case = $subject instanceof BkCase ? $subject : null;
        $referral = $subject instanceof BkReferral ? $subject : $case?->sourceReferral;

        if ($case !== null) {
            $this->access->assertCanViewCase($actor, $case, 'Mencatat screening');
        } else {
            $this->access->assertCanViewReferral($actor, $referral);
        }

        $studentId = (int) ($case?->student_id ?? $referral?->student_id);

        return DB::transaction(function () use ($actor, $case, $referral, $studentId, $data): BkScreening {
            $screening = BkScreening::query()->create([
                'screening_number' => $this->numbers->next('BK_SCREENING', 'BK-S'),
                'referral_id' => $referral?->id,
                'case_id' => $case?->id,
                'student_id' => $studentId,
                'screened_by' => $actor->id,
                'screened_at' => now(),
                'presenting_concern' => $data['presenting_concern'],
                'observation' => $data['observation'] ?? null,
                'student_perspective' => $data['student_perspective'] ?? null,
                'teacher_observation' => $data['teacher_observation'] ?? null,
                'parent_observation' => $data['parent_observation'] ?? null,
                'academic_indicators' => $data['academic_indicators'] ?? $this->academicIndicators($studentId),
                'attendance_indicators' => $data['attendance_indicators'] ?? $this->attendanceIndicators($studentId),
                'behavioral_indicators' => $data['behavioral_indicators'] ?? $this->behavioralIndicators($studentId),
                'boarding_indicators' => $data['boarding_indicators'] ?? $this->boardingIndicators($studentId),
                'previous_support' => $data['previous_support'] ?? $this->previousSupport($studentId),
                'immediate_support_needed' => (bool) ($data['immediate_support_needed'] ?? false),
                'recommended_outcome' => $data['recommended_outcome'],
                'recommendation_notes' => $data['recommendation_notes'] ?? null,
                'follow_up_date' => $data['follow_up_date'] ?? null,
                'confidentiality_level' => $data['confidentiality_level']
                    ?? ($case?->confidentiality_level->value ?? ConfidentialityLevel::RESTRICTED->value),
            ]);

            $outcome = $screening->recommended_outcome;

            if ($case !== null) {
                $this->timeline->record(CaseEventType::SCREENING_COMPLETED, $actor, $case,
                    summary: 'Screening selesai: '.$outcome->label().'.',
                    related: $screening,
                    payload: ['outcome' => $outcome->value, 'immediate' => $screening->immediate_support_needed]);
            }

            if ($referral !== null) {
                $this->timeline->record(CaseEventType::SCREENING_COMPLETED, $actor, $case, $referral,
                    summary: 'Screening referral selesai: '.$outcome->label().'.', related: $screening,
                    payload: ['outcome' => $outcome->value]);

                app(BkReferralService::class)->recordScreeningOutcome($actor, $referral, $outcome);
            }

            $this->audit->record(AuditAction::SCREEN, $screening, $actor, ['student_id' => $studentId]);

            if ($outcome->isUrgent()) {
                $this->notifications->notifyTeam(
                    NotificationEvent::ESCALATION,
                    sprintf('Screening %s merekomendasikan %s — perlu tindak lanjut segera.', $screening->screening_number, $outcome->label()),
                    ['screening_id' => $screening->id, 'case_id' => $case?->id],
                );
            }

            return $screening->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function academicIndicators(int $studentId): array
    {
        return $this->academic->summary($studentId);
    }

    /** @return array<string, mixed> */
    private function attendanceIndicators(int $studentId): array
    {
        return $this->attendance->summary($studentId, now()->subDays(90)->toDateString(), now()->toDateString());
    }

    /** @return array<string, mixed> */
    private function behavioralIndicators(int $studentId): array
    {
        return $this->discipline->summary($studentId, now()->subDays(180)->toDateString(), now()->toDateString());
    }

    /** @return array<string, mixed> */
    private function boardingIndicators(int $studentId): array
    {
        return $this->boarding->summary($studentId) ?? ['is_boarder' => false];
    }

    private function previousSupport(int $studentId): ?string
    {
        $previous = BkCase::query()->where('student_id', $studentId)->whereNotIn('status', ['CLOSED'])->count();
        $closed = BkCase::query()->where('student_id', $studentId)->where('status', 'CLOSED')->count();

        if ($previous === 0 && $closed === 0) {
            return null;
        }

        return sprintf('Riwayat: %d kasus berjalan, %d kasus selesai.', $previous, $closed);
    }

    /** Screening terakhir seorang siswa (untuk profil dukungan). */
    public function latestForStudent(int $studentId): ?BkScreening
    {
        return BkScreening::query()
            ->with(['screenedBy:id,name'])
            ->where('student_id', $studentId)
            ->orderByDesc('screened_at')
            ->first();
    }

    public function assertCanView(User $actor, BkScreening $screening): void
    {
        if ($screening->case !== null) {
            $this->access->assertCanViewCase($actor, $screening->case, 'Membuka screening');
        } elseif ($screening->referral !== null && ! $this->access->canViewReferral($actor, $screening->referral)) {
            throw new BkValidationException(['screening' => ['Anda tidak memiliki akses ke screening ini.']]);
        }
    }
}
