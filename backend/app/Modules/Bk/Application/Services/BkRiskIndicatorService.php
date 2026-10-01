<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Domain\Enums\RiskIndicatorCode;
use App\Modules\Bk\Domain\Enums\RiskIndicatorStatus;
use App\Modules\Bk\Domain\Enums\RiskIndicatorType;
use App\Modules\Bk\Domain\Enums\RiskSeverity;
use App\Modules\Bk\Domain\Enums\RiskSourceModule;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkEarlyWarningEvent;
use App\Modules\Bk\Models\BkRiskIndicator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Penanda dukungan/risiko siswa.
 *
 * Penanda adalah **permintaan agar manusia meninjau**, bukan kesimpulan tentang
 * anak. Karena itu:
 *   - istilahnya memakai "indikator dukungan / perhatian", bukan bahasa klinis;
 *   - setiap penanda meninggalkan jejak siapa yang meninjau dan apa hasilnya;
 *   - penanda tidak pernah mengubah status siswa, memblokir layanan, atau
 *     memengaruhi nilai — seluruh tindak lanjutnya tetap keputusan manusia.
 *
 * Kunci anti-duplikasi (`dedup_key`) membuat evaluasi berulang (cron ganda, retry
 * antrean, dua pekerja paralel) tetap menghasilkan satu penanda untuk satu periode.
 */
class BkRiskIndicatorService
{
    public function __construct(
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return BkRiskIndicator::query()
            ->with(['student:id,full_name,nis', 'rule:id,code,name', 'reviewer:id,name'])
            ->when($filters['student_id'] ?? null, fn ($q, $id) => $q->where('student_id', (int) $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $status === 'open' ? $q->open() : $q->where('status', $status))
            ->when($filters['code'] ?? null, fn ($q, $code) => $q->where('indicator_code', $code))
            ->when($filters['severity'] ?? null, fn ($q, $severity) => $q->where('severity', $severity))
            ->when($filters['source'] ?? null, fn ($q, $source) => $q->where('source_module', $source))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('detected_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('detected_at', '<=', $to))
            ->orderByDesc('detected_at')
            ->paginate($perPage);
    }

    /** Penanda terbuka untuk satu siswa — dipakai profil dukungan & ruang kerja kasus. */
    public function openForStudent(int $studentId): Collection
    {
        return BkRiskIndicator::query()->open()->forStudent($studentId)->orderByDesc('detected_at')->get();
    }

    /**
     * Membuat penanda. Idempoten: pemanggilan berulang dengan konteks yang sama
     * mengembalikan baris yang sudah ada, bukan menumpuk penanda baru.
     *
     * @param array<string, mixed> $attributes
     */
    public function create(
        User $actor,
        int $studentId,
        RiskIndicatorCode $code,
        array $attributes = [],
        ?RiskIndicatorType $type = null,
        RiskSourceModule|string|null $source = null,
        ?BkEarlyWarningEvent $event = null,
    ): BkRiskIndicator {
        $type ??= $attributes['indicator_type'] ?? RiskIndicatorType::SUPPORT_INDICATOR;
        $type = $type instanceof RiskIndicatorType ? $type : RiskIndicatorType::from((string) $type);

        $sourceModule = $source instanceof RiskSourceModule
            ? $source
            : RiskSourceModule::tryFrom((string) ($source ?? $code->defaultSource()->value)) ?? $code->defaultSource();

        $detectedAt = $attributes['detected_at'] ?? now();
        $dedupKey = $attributes['dedup_key'] ?? $this->dedupKey($studentId, $code, $detectedAt);

        $existing = BkRiskIndicator::query()->where('dedup_key', $dedupKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $indicator = BkRiskIndicator::query()->create([
            'student_id' => $studentId,
            'indicator_type' => $type,
            'indicator_code' => $code,
            'label' => $attributes['label'] ?? $code->label(),
            'severity' => $attributes['severity'] ?? RiskSeverity::tryFrom((string) ($attributes['severity'] ?? '')) ?? $type->defaultSeverity(),
            'source_module' => $sourceModule,
            'status' => RiskIndicatorStatus::OPEN,
            'detected_at' => $detectedAt,
            'period_start' => $attributes['period_start'] ?? null,
            'period_end' => $attributes['period_end'] ?? null,
            'observed_value' => $attributes['observed_value'] ?? null,
            'observed_unit' => $attributes['observed_unit'] ?? null,
            'threshold_value' => $attributes['threshold_value'] ?? null,
            'evidence' => $attributes['evidence'] ?? null,
            'summary' => $attributes['summary'] ?? $code->neutralNote(),
            'rule_id' => $attributes['rule_id'] ?? $event?->rule_id,
            'event_id' => $event?->id,
            'case_id' => $attributes['case_id'] ?? null,
            'referral_id' => $attributes['referral_id'] ?? null,
            'created_by' => $actor->id,
            'dedup_key' => $dedupKey,
        ]);

        $this->audit->record(AuditAction::CREATE, $indicator, $actor, [
            'student_id' => $studentId,
            'indicator_code' => $code->value,
            'source_module' => $sourceModule->value,
        ]);

        $this->notifications->notifyTeam(
            NotificationEvent::RISK_INDICATOR_CREATED,
            "Penanda dukungan baru: {$code->label()}",
            [
                'indicator_id' => $indicator->id,
                'student_id' => $studentId,
                'severity' => $indicator->severity->value,
                'note' => 'Perlu ditinjau manusia; penanda bukan diagnosis.',
            ],
        );

        return $indicator;
    }

    /**
     * Meninjau penanda. Hasil tinjauan wajib berupa keputusan yang jelas:
     * ditangani, dialihkan menjadi rujukan/kasus, atau ternyata tidak relevan.
     *
     * @param array<string, mixed> $data
     */
    public function review(User $actor, BkRiskIndicator $indicator, array $data): BkRiskIndicator
    {
        if ($indicator->status === RiskIndicatorStatus::RESOLVED) {
            throw new BkValidationException(['status' => ['Penanda yang sudah selesai tidak perlu ditinjau ulang.']]);
        }

        $status = RiskIndicatorStatus::from((string) ($data['status'] ?? RiskIndicatorStatus::REVIEWED->value));

        $indicator->forceFill([
            'status' => $status,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? $indicator->review_note,
            'case_id' => $data['case_id'] ?? $indicator->case_id,
            'referral_id' => $data['referral_id'] ?? $indicator->referral_id,
        ])->save();

        $this->audit->record(AuditAction::RISK_UPDATE, $indicator, $actor, [
            'status' => $status->value,
            'has_note' => ! empty($data['review_note']),
        ]);

        return $indicator->refresh();
    }

    /** Menandai penanda sebagai perlu perhatian lebih lanjut (mis. dipakai koordinator). */
    public function escalateAttention(User $actor, BkRiskIndicator $indicator, string $reason): BkRiskIndicator
    {
        $indicator->forceFill([
            'severity' => RiskSeverity::HIGH,
            'status' => RiskIndicatorStatus::UNDER_REVIEW,
            'summary' => trim(($indicator->summary ?? '').' | '.$reason),
        ])->save();

        $this->audit->record(AuditAction::RISK_UPDATE, $indicator, $actor, ['escalated' => true]);

        return $indicator->refresh();
    }

    /**
     * Kunci anti-duplikasi: satu penanda per siswa, per kode, per periode hari.
     * Periode dipakai (bukan waktu persis) supaya evaluasi dua kali dalam satu hari
     * tidak melahirkan dua penanda yang sama.
     */
    public function dedupKey(int $studentId, RiskIndicatorCode $code, mixed $detectedAt): string
    {
        $period = $detectedAt instanceof \DateTimeInterface
            ? $detectedAt->format('Y-m-d')
            : now()->format('Y-m-d');

        return "risk:{$code->value}:student:{$studentId}:{$period}";
    }
}
