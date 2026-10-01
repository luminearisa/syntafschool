<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetSettingsService;
use App\Modules\Asset\Domain\Contracts\ProcurementProviderInterface;
use App\Modules\Asset\Domain\Enums\AssetCondition;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Enums\ReplacementRecommendation;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetReplacement;
use App\Modules\Asset\Models\MaintenanceWorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Usulan penggantian aset.
 *
 * Ini **rekomendasi operasional**, bukan pesanan pembelian. Faktor penilainya
 * (umur, kondisi, biaya pemeliharaan, waktu henti, garansi, pemanfaatan) semuanya
 * dapat dikonfigurasi; keputusan tetap milik manusia, dan pengadaannya tetap milik
 * modul Pengadaan.
 */
class AssetReplacementService
{
    public function __construct(
        private readonly AssetSettingsService $settings,
        private readonly AssetAuditService $audit,
        private readonly ProcurementProviderInterface $procurement,
    ) {}

    /**
     * Hitung usulan untuk satu aset (tanpa menyimpan).
     *
     * @return array{recommendation:ReplacementRecommendation, reasons:list<string>, age_ratio:float, maintenance_cost_ratio:float, downtime_days:int}
     */
    public function evaluate(Asset $asset): array
    {
        $rules = (array) config('asset.replacement', []);
        $reasons = [];

        // 1. Umur terhadap umur manfaat.
        $ageRatio = 0.0;

        if ($asset->useful_life_months !== null && $asset->useful_life_months > 0) {
            $start = $asset->commission_date ?? $asset->received_date ?? $asset->purchase_date;

            if ($start !== null) {
                $ageRatio = round($start->diffInMonths(now()) / (int) $asset->useful_life_months, 3);
            }
        }

        // 2. Biaya pemeliharaan terhadap harga perolehan.
        $maintenanceCost = (string) MaintenanceWorkOrder::query()->where('asset_id', $asset->id)->sum('total_cost');
        $costRatio = 0.0;

        if (bccomp((string) $asset->acquisition_cost, '0', 2) === 1) {
            $costRatio = round((float) bcdiv($maintenanceCost, (string) $asset->acquisition_cost, 4), 3);
        }

        // 3. Waktu henti.
        $downtimeDays = (int) floor(((int) $asset->downtime_minutes) / 1440);

        $recommendation = ReplacementRecommendation::MONITOR;

        if ($ageRatio >= (float) ($rules['age_ratio_replace'] ?? 0.9)) {
            $reasons[] = sprintf('Umur sudah %.0f%% dari umur manfaat.', $ageRatio * 100);
            $recommendation = ReplacementRecommendation::REPLACE_REVIEW;
        } elseif ($ageRatio >= (float) ($rules['age_ratio_monitor'] ?? 0.6)) {
            $reasons[] = sprintf('Umur mencapai %.0f%% dari umur manfaat.', $ageRatio * 100);
            $recommendation = ReplacementRecommendation::MONITOR;
        }

        if ($costRatio >= (float) ($rules['maintenance_cost_ratio'] ?? 0.5)) {
            $reasons[] = sprintf('Biaya pemeliharaan sudah %.0f%% dari harga perolehan.', $costRatio * 100);
            $recommendation = ReplacementRecommendation::worst($recommendation, ReplacementRecommendation::REPLACE_REVIEW);
        }

        if ($downtimeDays >= (int) ($rules['downtime_days_replace'] ?? 30)) {
            $reasons[] = sprintf('Waktu henti %d hari dalam setahun terakhir.', $downtimeDays);
            $recommendation = ReplacementRecommendation::worst($recommendation, ReplacementRecommendation::REPLACE_REVIEW);
        }

        $condition = $asset->condition instanceof AssetCondition
            ? $asset->condition
            : AssetCondition::tryFrom((string) $asset->condition);

        if ($condition !== null && $condition->needsRepair()) {
            $reasons[] = 'Kondisi tercatat '.$condition->label().'.';
            $recommendation = ReplacementRecommendation::worst(
                $recommendation,
                $condition->rank() >= AssetCondition::CRITICAL->rank()
                    ? ReplacementRecommendation::REPLACE_REVIEW
                    : ReplacementRecommendation::REPAIR,
            );
        }

        $status = $asset->status instanceof AssetStatus ? $asset->status : AssetStatus::tryFrom((string) $asset->status);

        if ($status === AssetStatus::LOST) {
            $reasons[] = 'Aset berstatus hilang.';
            $recommendation = ReplacementRecommendation::REPLACE_REVIEW;
        }

        if ($asset->warranty_end !== null && $asset->warranty_end->isFuture() && $condition?->isUsable()) {
            $reasons[] = 'Masih dalam masa garansi.';
            $recommendation = ReplacementRecommendation::worst($recommendation, ReplacementRecommendation::MAINTAIN);
        }

        if ($reasons === []) {
            $reasons[] = 'Tidak ada indikator yang menuntut tindakan.';
        }

        return [
            'recommendation' => $recommendation,
            'reasons' => $reasons,
            'age_ratio' => $ageRatio,
            'maintenance_cost_ratio' => $costRatio,
            'downtime_days' => $downtimeDays,
            'maintenance_cost' => $maintenanceCost,
        ];
    }

    /** Simpan usulan (satu per aset pada satu waktu — dijaga kunci unik). */
    public function recommendFor(Asset $asset, bool $persist = true): array
    {
        $evaluation = $this->evaluate($asset);

        if (! $persist) {
            return $evaluation;
        }

        $record = AssetReplacement::query()->updateOrCreate(
            ['asset_id' => $asset->id, 'status' => 'OPEN'],
            [
                'recommendation' => $evaluation['recommendation']->value,
                'trigger_reasons' => $evaluation['reasons'],
                'age_ratio' => $evaluation['age_ratio'],
                'maintenance_cost_ratio' => $evaluation['maintenance_cost_ratio'],
                'downtime_days' => $evaluation['downtime_days'],
                'priority' => $evaluation['recommendation'] === ReplacementRecommendation::REPLACE_REVIEW ? 'HIGH' : 'NORMAL',
                'generated_at' => now(),
            ],
        );

        return $evaluation + ['record' => $record];
    }

    /** Tinjauan manusia atas usulan; keputusan dicatat bersama alasannya. */
    public function review(User $actor, AssetReplacement $replacement, string $decision, ?string $notes = null): AssetReplacement
    {
        $replacement->forceFill([
            'status' => $decision,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'decision_notes' => $notes,
        ])->save();

        $this->audit->record(AuditAction::ASSET_UPDATED, $replacement, $actor, [
            'reason' => $notes ?? 'Tinjauan usulan penggantian: '.$decision,
            'context' => ['recommendation' => $replacement->recommendation?->value ?? (string) $replacement->recommendation],
        ]);

        return $replacement->refresh();
    }

    /**
     * Teruskan usulan yang disetujui ke Pengadaan.
     *
     * Modul ini tidak membuat pesanan pembelian: ia menitipkan permintaan, dan
     * modul Pengadaan yang memutuskan bentuknya.
     */
    public function forwardToProcurement(User $actor, AssetReplacement $replacement, array $data = []): AssetReplacement
    {
        $asset = $replacement->asset;

        $result = $this->procurement->request([
            'title' => $data['title'] ?? 'Penggantian aset: '.($asset?->name ?? '-'),
            'asset_id' => $asset?->id,
            'asset_code' => $asset?->asset_code,
            'quantity' => $data['quantity'] ?? 1,
            'estimated_cost' => $data['estimated_cost'] ?? (string) ($asset?->acquisition_cost ?? '0.00'),
            'reason' => implode('; ', (array) $replacement->trigger_reasons),
            'requested_by' => $actor->id,
        ]);

        $replacement->forceFill([
            'status' => 'CONVERTED',
            'procurement_reference' => $result['reference'] ?? null,
        ])->save();

        $this->audit->record(AuditAction::ASSET_UPDATED, $replacement, $actor, [
            'reason' => 'Usulan penggantian diteruskan ke pengadaan.',
            'context' => ['procurement_reference' => $result['reference'] ?? null, 'status' => $result['status'] ?? 'PENDING'],
        ]);

        return $replacement->refresh();
    }

    /** Jalankan penilaian untuk seluruh aset (perintah terjadwal). */
    public function evaluateAll(User $actor, int $limit = 500): array
    {
        $created = [];

        $assets = Asset::query()
            ->whereNotIn('status', [AssetStatus::DISPOSED->value, AssetStatus::RETIRED->value])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($assets as $asset) {
            $result = $this->recommendFor($asset);

            if ($result['recommendation'] === ReplacementRecommendation::REPLACE_REVIEW) {
                $created[] = ['asset_code' => $asset->asset_code, 'reasons' => $result['reasons']];
            }
        }

        return $created;
    }

    public function rules(): array
    {
        return array_merge(
            (array) config('asset.replacement', []),
            (array) $this->settings->get('replacement.rules', []),
        );
    }
}
