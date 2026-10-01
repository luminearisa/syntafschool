<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkTenantResolver;
use App\Modules\Bk\Domain\Contracts\StudentProviderInterface;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\EarlyWarningAction;
use App\Modules\Bk\Domain\Enums\EarlyWarningEventStatus;
use App\Modules\Bk\Domain\Enums\RiskIndicatorCode;
use App\Modules\Bk\Domain\Enums\RiskIndicatorType;
use App\Modules\Bk\Domain\Enums\RiskSeverity;
use App\Modules\Bk\Domain\Enums\RiskSourceModule;
use App\Modules\Bk\Models\BkEarlyWarningEvent;
use App\Modules\Bk\Models\BkEarlyWarningRule;
use App\Modules\Bk\Models\BkRiskIndicator;
use App\Modules\Bk\Notifications\BkEarlyWarningNotification;
use Illuminate\Support\Carbon;

/**
 * Mesin peringatan dini.
 *
 * Aturan, ambang, jendela waktu, dan tindakan seluruhnya **data** pada tabel
 * `bk_early_warning_rules`; mesin ini hanya membaca dan menjalankannya. Sekolah
 * dapat mengubah kebijakan tanpa deploy, dan versi aturan tersimpan sehingga
 * keputusan lama dapat dipertanggungjawabkan dengan kebijakan yang berlaku saat itu.
 *
 * Dua sifat yang dijaga ketat:
 *   1. **Idempoten** — evaluasi berulang (cron ganda, retry antrean, dua pekerja
 *      paralel) tidak boleh melahirkan penanda atau eskalasi ganda. Kunci
 *      `dedup_key` memuat aturan + versi + siswa + periode + ember jeda.
 *   2. **Tidak menghakimi** — tindakan yang tersedia hanya menambah pekerjaan
 *      untuk ditinjau manusia. Tidak ada aturan yang boleh mengubah status siswa,
 *      memblokir layanan, atau menurunkan nilai.
 */
class BkEarlyWarningEngine
{
    public function __construct(
        private readonly BkEarlyWarningMetricResolver $metrics,
        private readonly BkRiskIndicatorService $indicators,
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
        private readonly StudentProviderInterface $students,
        private readonly BkTenantResolver $tenant,
    ) {
    }

    /**
     * Menjalankan seluruh aturan aktif untuk satu siswa.
     *
     * @return list<BkEarlyWarningEvent>
     */
    public function evaluateStudent(int $studentId, ?User $actor = null, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();
        $context = $this->studentContext($studentId);
        $events = [];

        $rules = BkEarlyWarningRule::query()->active()->orderBy('id')->get();

        foreach ($rules as $rule) {
            if (! $rule->appliesTo($context)) {
                continue;
            }

            $events[] = $this->evaluateRule($rule, $studentId, $asOf, $actor, $context);
        }

        return $events;
    }

    /**
     * Menjalankan mesin untuk banyak siswa sekaligus (dipakai perintah terjadwal).
     *
     * @return array{evaluated: int, matched: int, actioned: int, skipped: int, cooldown: int, events: list<BkEarlyWarningEvent>}
     */
    public function evaluateBatch(?User $actor = null, ?Carbon $asOf = null, ?int $limit = null): array
    {
        $asOf ??= Carbon::now();
        $limit ??= (int) config('bk.early_warning.batch_size', 500);

        $summary = ['evaluated' => 0, 'matched' => 0, 'actioned' => 0, 'skipped' => 0, 'cooldown' => 0, 'events' => []];

        $students = $this->students->activeStudentIds($limit);

        foreach ($students as $studentId) {
            foreach ($this->evaluateStudent((int) $studentId, $actor, $asOf) as $event) {
                $summary['evaluated']++;
                $summary['events'][] = $event;

                match ($event->status) {
                    EarlyWarningEventStatus::ACTIONED => $summary['actioned']++,
                    EarlyWarningEventStatus::COOLDOWN => $summary['cooldown']++,
                    EarlyWarningEventStatus::DETECTED => $summary['matched']++,
                    default => $summary['skipped']++,
                };
            }
        }

        return $summary;
    }

    /** Evaluasi satu aturan untuk satu siswa. */
    protected function evaluateRule(
        BkEarlyWarningRule $rule,
        int $studentId,
        Carbon $asOf,
        ?User $actor,
        array $context,
    ): BkEarlyWarningEvent {
        $windowDays = max(1, (int) $rule->window_days);
        $from = $asOf->copy()->subDays($windowDays)->startOfDay();
        $periodEnd = $asOf->copy()->endOfDay();

        $reading = $this->metrics->resolve($rule->metric, $studentId, $from, $periodEnd);
        $observed = $reading['value'];
        $dedupKey = $this->dedupKey($rule, $studentId, $asOf);

        $attributes = [
            'rule_id' => $rule->id,
            'rule_version' => (int) $rule->version,
            'student_id' => $studentId,
            'metric' => $rule->metric,
            'observed_value' => $observed,
            'threshold' => $rule->threshold,
            'window_days' => $windowDays,
            'period_start' => $from->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'evaluation_date' => $asOf->toDateString(),
            'dedup_key' => $dedupKey,
            'evaluated_by' => $actor?->id,
            'context' => [
                'scope' => $rule->scope,
                'student' => $context,
                'rule_code' => $rule->code,
                'rule_version' => (int) $rule->version,
            ],
        ];

        // Metrik tidak tersedia: dicatat apa adanya. Diam-diam menganggap nol
        // akan menghasilkan penilaian yang salah terhadap siswa.
        if ($observed === null) {
            return $this->recordEvent($attributes + [
                'matched' => false,
                'status' => EarlyWarningEventStatus::SKIPPED,
                'reason' => $reading['reason'] ?? 'Metrik tidak tersedia pada rentang ini.',
            ]);
        }

        $matched = $rule->operator->matches($observed, (float) $rule->threshold);

        if (! $matched) {
            return $this->recordEvent($attributes + [
                'matched' => false,
                'status' => EarlyWarningEventStatus::SKIPPED,
                'reason' => sprintf(
                    '%s %s %s tidak terpenuhi (nilai %s).',
                    $rule->metric->label(),
                    $rule->operator->symbol(),
                    $rule->threshold,
                    $observed,
                ),
            ]);
        }

        // Sudah pernah ditindak dalam jendela jeda: catat sebagai COOLDOWN agar
        // pertanyaan "kenapa tidak ada peringatan kedua?" punya jawaban.
        $existing = BkEarlyWarningEvent::query()->where('dedup_key', $dedupKey)->first();

        if ($existing !== null) {
            return $this->recordEvent($attributes + [
                // Kunci tersendiri agar jejak 'ditahan jeda' tidak menabrak kunci
                // tindakan, tetapi tetap satu baris per aturan/siswa/hari.
                'dedup_key' => $dedupKey.'#cooldown-'.$asOf->toDateString(),
                'matched' => true,
                'status' => EarlyWarningEventStatus::COOLDOWN,
                'indicator_id' => $existing->indicator_id,
                'action_taken' => $existing->action_taken,
                'reason' => sprintf('Sudah ditindak pada %s (jeda %d hari).', $existing->evaluation_date?->toDateString(), (int) $rule->cooldown_days),
            ]);
        }

        $event = $this->recordEvent($attributes + [
            'matched' => true,
            'status' => EarlyWarningEventStatus::DETECTED,
            'reason' => sprintf('%s %s %s terpenuhi (nilai %s).', $rule->metric->label(), $rule->operator->symbol(), $rule->threshold, $observed),
        ]);

        return $this->runAction($rule, $event, $studentId, $actor, $observed, $reading['unit'] ?? null);
    }

    /** Menjalankan tindakan yang ditetapkan aturan. */
    protected function runAction(
        BkEarlyWarningRule $rule,
        BkEarlyWarningEvent $event,
        int $studentId,
        ?User $actor,
        float $observed,
        ?string $unit,
    ): BkEarlyWarningEvent {
        $actor ??= $this->systemActor();

        $code = $rule->indicator_code instanceof RiskIndicatorCode
            ? $rule->indicator_code
            : RiskIndicatorCode::tryFrom((string) $rule->indicator_code) ?? RiskIndicatorCode::CUSTOM;

        $indicator = null;

        if (in_array($rule->action, [
            EarlyWarningAction::CREATE_SUPPORT_INDICATOR,
            EarlyWarningAction::CREATE_BK_REVIEW,
            EarlyWarningAction::CREATE_ACADEMIC_SUPPORT_INDICATOR,
            EarlyWarningAction::CREATE_REFERRAL,
        ], true)) {
            $indicator = $this->indicators->create(
                actor: $actor,
                studentId: $studentId,
                code: $code,
                attributes: [
                    'indicator_type' => $rule->action->indicatorType(),
                    'severity' => $rule->severity instanceof RiskSeverity ? $rule->severity : RiskSeverity::MODERATE,
                    'observed_value' => $observed,
                    'observed_unit' => $unit,
                    'threshold_value' => $rule->threshold,
                    'period_start' => $event->period_start,
                    'period_end' => $event->period_end,
                    'evidence' => [
                        'rule_code' => $rule->code,
                        'rule_version' => (int) $rule->version,
                        'metric' => $rule->metric->value,
                        'operator' => $rule->operator->value,
                        'threshold' => (float) $rule->threshold,
                        'window_days' => (int) $rule->window_days,
                    ],
                    'rule_id' => $rule->id,
                ],
                type: $rule->action->indicatorType(),
                source: $rule->metric->sourceModule(),
                event: $event,
            );
        }

        if ($rule->action === EarlyWarningAction::NOTIFY_COORDINATOR || $rule->requires_review) {
            $this->notifications->notifyTeam(
                \App\Modules\Bk\Domain\Enums\NotificationEvent::BK_REVIEW_REQUIRED,
                "Tinjauan BK: {$rule->name}",
                [
                    'rule_code' => $rule->code,
                    'student_id' => $studentId,
                    'metric' => $rule->metric->value,
                    'observed' => $observed,
                    'threshold' => (float) $rule->threshold,
                    'indicator_id' => $indicator?->id,
                ],
            );
        }

        foreach ($this->coordinatorUserIds() as $userId) {
            BkEarlyWarningNotification::send($userId, $rule, $studentId, $observed, $indicator?->id);
        }

        $event->forceFill([
            'status' => EarlyWarningEventStatus::ACTIONED,
            'action_taken' => $rule->action,
            'indicator_id' => $indicator?->id,
            'actioned_at' => now(),
        ])->save();

        $this->audit->record(AuditAction::CREATE, $indicator ?? $event, $actor, [
            'early_warning' => true,
            'rule_code' => $rule->code,
            'rule_version' => (int) $rule->version,
            'action' => $rule->action->value,
            'metric' => $rule->metric->value,
            'observed' => $observed,
            'threshold' => (float) $rule->threshold,
        ]);

        return $event->refresh();
    }

    /**
     * Menuliskan peristiwa evaluasi.
     *
     * Bila kunci sudah ada dan `unique` benar, baris yang ada dipakai kembali —
     * inilah yang menjaga idempotensi saat pekerja/antrean berjalan bersamaan.
     */
    protected function recordEvent(array $attributes, bool $unique = true): BkEarlyWarningEvent
    {
        if ($unique) {
            $existing = BkEarlyWarningEvent::query()->where('dedup_key', $attributes['dedup_key'] ?? '__none__')->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        try {
            return BkEarlyWarningEvent::query()->create($attributes);
        } catch (\Illuminate\Database\QueryException $exception) {
            // Balapan dua pekerja pada baris yang sama: ambil milik pemenang.
            $existing = BkEarlyWarningEvent::query()->where('dedup_key', $attributes['dedup_key'] ?? '__none__')->first();

            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    /** Kunci idempotensi: aturan + versi + siswa + periode + ember jeda. */
    public function dedupKey(BkEarlyWarningRule $rule, int $studentId, Carbon $asOf): string
    {
        $cooldown = max(1, (int) $rule->cooldown_days);
        $bucket = (int) floor($asOf->startOfDay()->diffInDays(Carbon::create(2000, 1, 1)) / $cooldown);

        return sprintf('ew:rule:%d:v%d:student:%d:bucket:%d', $rule->id, (int) $rule->version, $studentId, $bucket);
    }

    /** Konteks siswa untuk menyaring aturan ber-scope. */
    protected function studentContext(int $studentId): array
    {
        $snapshot = $this->students->snapshot($studentId) ?? [];

        return [
            'student_id' => $studentId,
            'grade' => $snapshot['grade'] ?? $snapshot['class_level'] ?? null,
            'class_id' => $snapshot['class_id'] ?? null,
            'gender' => $snapshot['gender'] ?? null,
        ];
    }

    /** @return list<int> */
    protected function coordinatorUserIds(): array
    {
        return \App\Models\User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['BK_COORDINATOR', 'BK_ADMIN']))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** Aktor sistem: dipakai bila mesin berjalan dari penjadwal, bukan dari orang. */
    protected function systemActor(): User
    {
        $configured = config('bk.early_warning.system_user_id');

        if (is_numeric($configured)) {
            $user = User::query()->find((int) $configured);

            if ($user !== null) {
                return $user;
            }
        }

        $fallback = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['BK_ADMIN', 'BK_COORDINATOR']))
            ->orderBy('id')
            ->first();

        return $fallback ?? new User(['name' => 'Sistem BK']);
    }

    /** Kesehatan mesin aturan: dipakai perintah `bk:health` dan layar pengaturan. */
    public function health(): array
    {
        $rules = BkEarlyWarningRule::query()->count();
        $active = BkEarlyWarningRule::query()->active()->count();
        $lastEvent = BkEarlyWarningEvent::query()->orderByDesc('id')->first();
        $indicators = BkRiskIndicator::query()->open()->count();

        return [
            'tenant_scope' => $this->tenant->id(),
            'rules_total' => $rules,
            'rules_active' => $active,
            'indicators_open' => $indicators,
            'events_total' => BkEarlyWarningEvent::query()->count(),
            'last_evaluation_at' => $lastEvent?->created_at?->toIso8601String(),
            'last_evaluation_status' => $lastEvent?->status?->value,
            'metrics_available' => array_map(
                fn (RiskSourceModule $module): array => ['module' => $module->value, 'owned_by_bk' => $module->isOwnedByBk()],
                RiskSourceModule::cases(),
            ),
        ];
    }
}
