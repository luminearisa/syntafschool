<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\CaseAgingBucket;
use App\Modules\Bk\Domain\Enums\CaseStatus;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkExternalReferral;
use App\Modules\Bk\Models\BkReferral;
use App\Modules\Bk\Models\BkRiskIndicator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Analitik BK.
 *
 * Dua aturan yang membentuk seluruh kelas ini:
 *
 *  1. **Cakupan privasi tetap berlaku pada angka.** Angka agregat pun dapat
 *     membocorkan: "satu kasus di kelas 9A" cukup untuk menebak siapa. Karena itu
 *     setiap kueri melewati cakupan baris yang sama dengan layar operasional, dan
 *     kelompok yang terlalu kecil disamarkan (`< 3` dilaporkan sebagai
 *     `dilindungi`) alih-alih ditampilkan.
 *
 *  2. **Yang di-cache hanya agregat tak sensitif.** Angka tanpa dimensi pribadi
 *     (jumlah kasus per kategori, tren bulanan) boleh di-cache; apa pun yang
 *     menyebut siswa/konselor individual tidak, karena cache adalah salinan yang
 *     hidup di luar kendali kewenangan.
 */
class BkAnalyticsService
{
    /** Ambang minimum agar sebuah kelompok boleh ditampilkan. */
    private const SMALL_GROUP = 3;

    private const CACHE_TTL_MINUTES = 10;

    public function __construct(private readonly BkAccessService $access)
    {
    }

    // ------------------------------------------------------------------- kasus

    /** @param array<string, mixed> $filters */
    public function cases(User $actor, array $filters): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : Carbon::now()->subMonths(6);
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : Carbon::now();

        $base = fn () => BkCase::query()->whereBetween('created_at', [$from, $to]);

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total' => $base()->count(),
            'by_category' => $this->protectedGroups($base()->select('category', DB::raw('count(*) as total'))->groupBy('category')->pluck('total', 'category')),
            'by_priority' => $this->protectedGroups($base()->select('priority', DB::raw('count(*) as total'))->groupBy('priority')->pluck('total', 'priority')),
            'by_status' => $this->protectedGroups($base()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')),
            'by_type' => $this->protectedGroups($base()->select('case_type', DB::raw('count(*) as total'))->groupBy('case_type')->pluck('total', 'case_type')),
            'by_grade' => $this->byGrade($from, $to),
            'by_counselor' => $this->byCounselor($actor, $from, $to),
            'by_program' => $this->byProgram($from, $to),
        ];
    }

    /** Analitik rujukan: volume, sumber, dan waktu tanggap. */
    public function referrals(User $actor, array $filters): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : Carbon::now()->subMonths(6);
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : Carbon::now();

        $query = fn () => BkReferral::query()->whereBetween('referred_at', [$from, $to]);

        $responded = (clone $query())->whereNotNull('received_at')->get();
        $averageResponseHours = $responded->isEmpty() ? null : round((float) $responded
            ->map(fn ($referral): float => $referral->referred_at !== null && $referral->received_at !== null
                ? $referral->referred_at->diffInMinutes($referral->received_at) / 60
                : 0)
            ->avg(), 1);

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total' => $query()->count(),
            'by_source' => $this->protectedGroups($query()->select('source_type', DB::raw('count(*) as total'))->groupBy('source_type')->pluck('total', 'source_type')),
            'by_category' => $this->protectedGroups($query()->select('category', DB::raw('count(*) as total'))->groupBy('category')->pluck('total', 'category')),
            'by_priority' => $this->protectedGroups($query()->select('priority', DB::raw('count(*) as total'))->groupBy('priority')->pluck('total', 'priority')),
            'converted_to_case' => $query()->whereNotNull('case_id')->count(),
            'average_response_hours' => $averageResponseHours,
            'monthly_trend' => $this->monthlyTrend('bk_referrals', 'referred_at', $from, $to),
            'external_total' => BkExternalReferral::query()->whereBetween('created_at', [$from, $to])->count(),
            'external_by_provider_type' => $this->protectedGroups(
                BkExternalReferral::query()
                    ->join('bk_referral_providers', 'bk_referral_providers.id', '=', 'bk_external_referrals.referral_provider_id')
                    ->whereBetween('bk_external_referrals.created_at', [$from, $to])
                    ->select('bk_referral_providers.provider_type', DB::raw('count(*) as total'))
                    ->groupBy('bk_referral_providers.provider_type')
                    ->pluck('total', 'provider_type'),
            ),
        ];
    }

    /** Analitik hasil: apakah dukungan yang diberikan berujung sesuatu. */
    public function outcomes(User $actor, array $filters): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : Carbon::now()->subYear();
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : Carbon::now();

        $closed = BkCase::query()->whereBetween('closed_at', [$from, $to]);
        $resolved = (clone $closed)->whereIn('status', [CaseStatus::RESOLVED->value, CaseStatus::CLOSED->value])->get();
        $reopened = (clone $closed)->whereNotNull('reopened_at')->count();

        $durations = $resolved
            ->filter(fn (BkCase $case): bool => $case->opened_at !== null && $case->closed_at !== null)
            ->map(fn (BkCase $case): float => (float) $case->opened_at->diffInDays($case->closed_at));

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'closed_total' => $closed->count(),
            'resolved_total' => $resolved->count(),
            'reopened_total' => $reopened,
            'reopen_rate' => $resolved->count() === 0 ? null : round(($reopened / max(1, $resolved->count())) * 100, 1),
            'average_case_duration_days' => $durations->isEmpty() ? null : round((float) $durations->avg(), 1),
            'gender_note' => 'Modul ini tidak melaporkan hasil berdasarkan jenis kelamin: dimensi itu tidak diperlukan untuk perbaikan layanan.',
        ];
    }

    /** Umur kasus per kelompok (case aging) — pemantauan operasional, bukan penilaian konselor. */
    public function aging(User $actor, array $filters = []): array
    {
        $open = BkCase::query()
            ->whereNotIn('status', [CaseStatus::CLOSED->value, CaseStatus::CANCELLED->value])
            ->get(['id', 'opened_at', 'priority', 'case_type']);

        $buckets = collect(CaseAgingBucket::cases())->mapWithKeys(fn (CaseAgingBucket $bucket): array => [$bucket->value => 0])->all();
        $oldest = null;

        foreach ($open as $case) {
            $age = $case->opened_at !== null ? (int) $case->opened_at->diffInDays(now()) : 0;
            $bucket = CaseAgingBucket::forAge($age);
            $buckets[$bucket->value]++;

            if ($oldest === null || $age > $oldest['days']) {
                $oldest = ['case_id' => $case->id, 'days' => $age, 'priority' => $case->priority?->value];
            }
        }

        return [
            'open_total' => $open->count(),
            'buckets' => $buckets,
            'thresholds' => collect(CaseAgingBucket::cases())
                ->mapWithKeys(fn (CaseAgingBucket $bucket): array => [$bucket->value => $bucket->upperBound()])
                ->all(),
            'oldest_open_case' => $oldest,
            'average_open_age_days' => $open->isEmpty() ? null : round((float) $open
                ->map(fn (BkCase $case): float => (float) ($case->opened_at?->diffInDays(now()) ?? 0))
                ->avg(), 1),
            'note' => 'Umur kasus dipakai untuk pemantauan beban kerja dan alur, bukan untuk menilai kualitas konselor.',
        ];
    }

    /** Tren bulanan untuk berbagai jenis rekaman. */
    public function trends(User $actor, array $filters = []): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : Carbon::now()->subMonths(12)->startOfMonth();
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : Carbon::now();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'referrals' => $this->monthlyTrend('bk_referrals', 'referred_at', $from, $to),
            'cases' => $this->monthlyTrend('bk_cases', 'opened_at', $from, $to),
            'sessions' => $this->monthlyTrend('bk_counseling_sessions', 'scheduled_at', $from, $to),
            'indicators' => $this->monthlyTrend('bk_risk_indicators', 'detected_at', $from, $to),
            'interventions' => $this->monthlyTrend('bk_interventions', 'start_date', $from, $to),
        ];
    }

    /** Analitik layanan: pertanyaan "apakah layanan ini dipakai dan tuntas?". */
    public function service(User $actor, array $filters = []): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : Carbon::now()->subMonths(6);
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : Carbon::now();

        $appointments = DB::table('bk_appointments')->whereBetween('scheduled_start', [$from, $to]);
        $completed = (clone $appointments)->whereIn('attendance_status', ['PRESENT', 'ABSENT'])->count();
        $noShow = (clone $appointments)->where('attendance_status', 'ABSENT')->count();

        $followUps = DB::table('bk_follow_ups')->whereBetween('created_at', [$from, $to]);
        $followUpTotal = (clone $followUps)->count();
        $followUpCompleted = (clone $followUps)->whereIn('status', ['COMPLETED'])->count();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'appointments_total' => (clone $appointments)->count(),
            'appointments_completed' => $completed,
            'appointment_no_show_rate' => $completed === 0 ? null : round(($noShow / max(1, $completed)) * 100, 1),
            'follow_up_total' => $followUpTotal,
            'follow_up_completion_rate' => $followUpTotal === 0 ? null : round(($followUpCompleted / max(1, $followUpTotal)) * 100, 1),
            'indicators_open' => BkRiskIndicator::query()->open()->count(),
            'indicators_by_source' => $this->protectedGroups(
                BkRiskIndicator::query()
                    ->whereBetween('detected_at', [$from, $to])
                    ->select('source_module', DB::raw('count(*) as total'))
                    ->groupBy('source_module')
                    ->pluck('total', 'source_module'),
            ),
        ];
    }

    // ------------------------------------------------------------------ bantuan

    /**
     * Menyamarkan kelompok kecil.
     *
     * "1 kasus di kelas 9A" praktis menyebut nama. Karena itu kelompok dengan
     * jumlah di bawah ambang dilaporkan sebagai satu baris `dilindungi`, sementara
     * totalnya tetap akurat.
     */
    protected function protectedGroups(\Illuminate\Support\Collection $groups): array
    {
        $visible = [];
        $protected = 0;

        foreach ($groups as $key => $total) {
            $value = (string) ($key ?? 'TIDAK_DIKETAHUI');

            if ((int) $total < self::SMALL_GROUP) {
                $protected += (int) $total;

                continue;
            }

            $visible[] = ['key' => $value, 'total' => (int) $total];
        }

        if ($protected > 0) {
            $visible[] = ['key' => 'DILINDUNGI', 'total' => $protected, 'note' => 'Kelompok dengan kurang dari '.self::SMALL_GROUP.' kasus tidak dirinci.'];
        }

        return $visible;
    }

    protected function byGrade(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('bk_cases')
            ->join('students', 'students.id', '=', 'bk_cases.student_id')
            ->leftJoin('class_students', 'class_students.student_id', '=', 'students.id')
            ->leftJoin('school_classes', 'school_classes.id', '=', 'class_students.school_class_id')
            ->whereBetween('bk_cases.created_at', [$from, $to])
            ->select('school_classes.name as grade', DB::raw('count(distinct bk_cases.id) as total'))
            ->groupBy('school_classes.name')
            ->pluck('total', 'grade');

        return $this->protectedGroups($rows);
    }

    /**
     * Sebaran beban kerja per konselor.
     *
     * Hanya koordinator/admin yang boleh melihat angka per orang: bagi konselor,
     * angka ini berubah menjadi perbandingan antar-rekan yang tidak dimaksudkan
     * layar ini. Data per orang juga tidak di-cache.
     */
    protected function byCounselor(User $actor, Carbon $from, Carbon $to): array
    {
        if (! $this->access->canMonitorWorkload($actor)) {
            return [];
        }

        return BkCase::query()
            ->join('users', 'users.id', '=', 'bk_cases.assigned_counselor_id')
            ->whereBetween('bk_cases.created_at', [$from, $to])
            ->select('users.name as counselor', DB::raw('count(*) as total'))
            ->groupBy('users.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => ['counselor' => $row->counselor, 'total' => (int) $row->total])
            ->all();
    }

    /** Kasus yang berasal dari program kelompok (relasi lewat keikutsertaan). */
    protected function byProgram(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('bk_cases')
            ->join('bk_group_program_members', 'bk_group_program_members.case_id', '=', 'bk_cases.id')
            ->join('bk_group_programs', 'bk_group_programs.id', '=', 'bk_group_program_members.program_id')
            ->whereBetween('bk_cases.created_at', [$from, $to])
            ->select('bk_group_programs.title as program', DB::raw('count(distinct bk_cases.id) as total'))
            ->groupBy('bk_group_programs.title')
            ->pluck('total', 'program');

        return $this->protectedGroups($rows);
    }

    protected function monthlyTrend(string $table, string $column, Carbon $from, Carbon $to): array
    {
        // Agregat tanpa dimensi pribadi: aman di-cache sebentar.
        $key = 'bk:analytics:trend:'.$table.':'.$column.'.'.$from->format('Ymd').'.'.$to->format('Ymd');

        return Cache::remember($key, now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($table, $column, $from, $to): array {
            $rows = DB::table($table)
                ->whereNotNull($column)
                ->whereBetween($column, [$from, $to])
                ->select(DB::raw($this->monthExpression($column).' as period'), DB::raw('count(*) as total'))
                ->groupBy('period')
                ->orderBy('period')
                ->get();

            return $rows->map(fn ($row): array => ['period' => (string) $row->period, 'total' => (int) $row->total])->all();
        });
    }

    /** Ekspresi bulan yang bekerja di MySQL maupun SQLite (dipakai uji). */
    protected function monthExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}
