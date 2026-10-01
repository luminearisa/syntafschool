<?php

namespace App\Modules\Activity\Console;

use App\Modules\Activity\Application\Services\ActivityAssessmentService;
use App\Modules\Activity\Domain\Enums\MembershipStatus;
use App\Modules\Activity\Domain\Enums\PeriodStatus;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivityPeriod;
use Illuminate\Console\Command;

/**
 * Evaluasi penyelesaian aktivitas untuk periode yang sudah berakhir (§41, §75).
 *
 * Hanya **menandai kelayakan**; keputusan penyelesaian tetap dilakukan manusia
 * (atau alur persetujuan), karena menyelesaikan aktivitas berarti menutup
 * catatan perkembangan siswa — bukan sekadar menghitung angka.
 */
class ActivityEvaluateCompletionCommand extends Command
{
    protected $signature = 'activity:evaluate-completion {--period= : Kode periode} {--apply : Tetapkan penyelesaian yang memenuhi syarat}';

    protected $description = 'Evaluasi kelayakan penyelesaian aktivitas pada periode yang berakhir';

    public function handle(ActivityAssessmentService $assessments): int
    {
        $periods = ActivityPeriod::query()
            ->when($this->option('period') !== null, fn ($query) => $query->where('code', $this->option('period')))
            ->whereIn('status', [PeriodStatus::ACTIVE->value, PeriodStatus::CLOSED->value])
            ->where('end_date', '<=', now()->addDays(14)->toDateString())
            ->get();

        $evaluated = 0;
        $eligible = 0;

        foreach ($periods as $period) {
            $memberships = ActivityMembership::query()
                ->with('program')
                ->where('period_id', $period->id)
                ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
                ->get();

            foreach ($memberships as $membership) {
                $evaluation = $assessments->evaluateCompletion($membership);
                $evaluated++;

                if ($evaluation['eligible']) {
                    $eligible++;
                    $this->line(sprintf('  siap: %s (program %s)', $membership->student_id, $membership->program_id));
                }
            }
        }

        $this->info(sprintf('%d keanggotaan dievaluasi, %d memenuhi syarat penyelesaian.', $evaluated, $eligible));

        return self::SUCCESS;
    }
}
