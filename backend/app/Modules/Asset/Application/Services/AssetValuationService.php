<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Domain\Contracts\FinanceProviderInterface;
use App\Modules\Asset\Domain\Enums\AssetDepreciationMethod;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Enums\ValuationType;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetDepreciation;
use App\Modules\Asset\Models\AssetImpairment;
use App\Modules\Asset\Models\AssetValuation;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Valuasi & penyusutan aset.
 *
 * Penyusutan DIHITUNG di sini (karena umur, residu, dan metode adalah data
 * operasional) tetapi DICATAT di Keuangan — modul ini tidak menulis jurnal dan
 * tidak menyimpan buku besar kedua. Setiap periode disusutkan SEKALI; kuncinya
 * ada di basis data (`unique(asset_id, period)`), sehingga menjalankan ulang
 * perintah bulanan tidak menggandakan beban.
 */
class AssetValuationService
{
    public function __construct(
        private readonly AssetAuditService $audit,
        private readonly FinanceProviderInterface $finance,
        private readonly AssetAccessService $access,
    ) {}

    /**
     * Hitung penyusutan satu periode tanpa mengubah apa pun (pratinjau).
     *
     * @return array{rows:list<array<string,string|int|null>>, total:string}
     */
    public function preview(string $period, ?int $assetId = null): array
    {
        [$start, $end] = $this->periodBounds($period);

        $query = Asset::query()
            ->whereNotIn('status', [AssetStatus::DISPOSED->value, AssetStatus::RETIRED->value])
            ->whereNotNull('useful_life_months')
            ->where('capitalization_decision', 'ASSET');

        if ($assetId !== null) {
            $query->whereKey($assetId);
        }

        $rows = [];
        $total = '0.00';

        foreach ($query->get() as $asset) {
            $amount = $this->depreciationFor($asset, $end);

            if ($amount === null) {
                continue;
            }

            if (AssetDepreciation::query()->where('asset_id', $asset->id)->where('period', $period)->exists()) {
                $rows[] = ['asset_id' => $asset->id, 'asset_code' => $asset->asset_code, 'amount' => '0.00', 'note' => 'sudah dibukukan pada periode ini'];

                continue;
            }

            $rows[] = [
                'asset_id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'name' => $asset->name,
                'method' => $asset->depreciation_method ?? 'STRAIGHT_LINE',
                'amount' => $amount,
                'book_value_after' => $this->bookValueAfter($asset, $amount),
            ];

            $total = bcadd($total, $amount, 2);
        }

        return ['rows' => $rows, 'total' => $total, 'period' => $period, 'from' => $start->toDateString(), 'to' => $end->toDateString()];
    }

    /** Jalankan penyusutan satu periode (idempoten per aset-periode). */
    public function run(User $actor, string $period, ?int $assetId = null): array
    {
        $this->access->assertCanViewFinancials($actor);

        $preview = $this->preview($period, $assetId);
        $posted = [];

        foreach ($preview['rows'] as $row) {
            if (($row['amount'] ?? '0.00') === '0.00') {
                continue;
            }

            $asset = Asset::query()->find($row['asset_id']);

            if ($asset === null) {
                continue;
            }

            $result = DB::transaction(function () use ($actor, $asset, $period, $row): AssetDepreciation {
                $depreciation = AssetDepreciation::query()->create([
                    'asset_id' => $asset->id,
                    'period' => $period,
                    'method' => (string) ($asset->depreciation_method ?? AssetDepreciationMethod::STRAIGHT_LINE->value),
                    'amount' => $row['amount'],
                    'accumulated_amount' => bcadd((string) $asset->accumulated_depreciation, (string) $row['amount'], 2),
                    'book_value' => (string) $row['book_value_after'],
                    'status' => 'POSTED',
                    'finance_status' => 'PENDING',
                    'posted_at' => now(),
                    'posted_by' => $actor->id,
                ]);

                $asset->forceFill([
                    'accumulated_depreciation' => $depreciation->accumulated_amount,
                    'current_book_value' => $depreciation->book_value,
                ])->save();

                return $depreciation;
            });

            // Akibat keuangannya dicatat lewat kontrak Keuangan.
            $finance = $this->finance->depreciate((int) $asset->id, $period, [
                'asset_code' => $asset->asset_code,
                'amount' => $row['amount'],
                'book_value_after' => $row['book_value_after'],
                'actor_id' => $actor->id,
            ]);

            $result->forceFill([
                'finance_status' => $finance['status'] ?? 'PENDING',
                'finance_reference' => $finance['reference'] ?? null,
            ])->save();

            $this->audit->record(AuditAction::ASSET_DEPRECIATED, $asset, $actor, [
                'reason' => 'Penyusutan periode '.$period.'.',
                'context' => ['amount' => $row['amount'], 'book_value_after' => $row['book_value_after']],
            ]);

            $posted[] = [
                'asset_id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'amount' => $row['amount'],
                'finance_status' => $result->finance_status,
            ];
        }

        return ['period' => $period, 'posted' => $posted, 'total' => array_reduce($posted, fn (string $carry, array $row): string => bcadd($carry, (string) $row['amount'], 2), '0.00')];
    }

    /**
     * Penilaian ulang / penurunan nilai.
     *
     * Setiap perubahan nilai membawa alasan, dokumen, tanggal berlaku, dan
     * persetujuan — inilah yang membedakannya dari "mengubah angka".
     */
    public function revalue(User $actor, Asset $asset, ValuationType $type, string $amount, string $reason, array $data = []): AssetValuation
    {
        $this->access->assertCanViewFinancials($actor);

        if (trim($reason) === '') {
            throw new AssetValidationException(['reason' => ['Alasan penilaian ulang wajib diisi.']]);
        }

        if ($type === ValuationType::IMPAIRMENT && $this->isPositive($amount)) {
            throw new AssetValidationException(['amount' => ['Penurunan nilai harus berupa angka positif (besarnya penurunan).']]);
        }

        return DB::transaction(function () use ($actor, $asset, $type, $amount, $reason, $data): AssetValuation {
            $previous = (string) ($asset->current_book_value ?? $asset->acquisition_cost);

            $valuation = AssetValuation::query()->create([
                'asset_id' => $asset->id,
                'valuation_type' => $type->value,
                'amount' => $amount,
                'previous_book_value' => $previous,
                'effective_date' => $data['effective_date'] ?? now()->toDateString(),
                'reason' => $reason,
                'document_reference' => $data['document_reference'] ?? null,
                'approved_by' => $data['approved_by'] ?? $actor->id,
                'approved_at' => now(),
                'created_by' => $actor->id,
            ]);

            if ($type === ValuationType::IMPAIRMENT) {
                AssetImpairment::query()->create([
                    'asset_id' => $asset->id,
                    'amount' => $amount,
                    'reason' => $reason,
                    'effective_date' => $valuation->effective_date,
                    'approved_by' => $valuation->approved_by,
                ]);

                $asset->forceFill([
                    'current_book_value' => bcsub($previous, $this->normalize($amount), 2) < 0
                        ? '0.00'
                        : bcsub($previous, $this->normalize($amount), 2),
                ])->save();
            } else {
                $asset->forceFill(['current_book_value' => $this->normalize($amount), 'fair_value' => $this->normalize($amount)])->save();
            }

            $finance = $this->finance->revalue([
                'asset_id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'valuation_type' => $type->value,
                'amount' => $amount,
                'reason' => $reason,
                'effective_date' => $valuation->effective_date?->toDateString(),
                'actor_id' => $actor->id,
            ]);

            $this->audit->record(AuditAction::ASSET_REVALUED, $asset, $actor, [
                'before' => ['book_value' => $previous],
                'after' => ['book_value' => (string) $asset->current_book_value],
                'reason' => $reason,
                'context' => ['type' => $type->value, 'finance_status' => $finance['status'] ?? 'PENDING'],
            ]);

            return $valuation->refresh();
        });
    }

    /** Waktu henti & nilai buku dipakai usulan penggantian. */
    public function bookValue(Asset $asset): string
    {
        return (string) ($asset->current_book_value ?? $asset->acquisition_cost ?? '0.00');
    }

    protected function depreciationFor(Asset $asset, \Carbon\CarbonInterface $until): ?string
    {
        $cost = $this->normalize((string) $asset->acquisition_cost);
        $residual = $this->normalize((string) $asset->residual_value);
        $life = (int) ($asset->useful_life_months ?? 0);

        if ($life <= 0 || $this->compare($cost, $residual) <= 0) {
            return null;
        }

        $method = $asset->depreciation_method instanceof AssetDepreciationMethod
            ? $asset->depreciation_method
            : AssetDepreciationMethod::tryFrom((string) $asset->depreciation_method) ?? AssetDepreciationMethod::STRAIGHT_LINE;

        $started = $asset->depreciation_started_at
            ?? $asset->capitalization_date
            ?? $asset->received_date
            ?? $asset->purchase_date;

        if ($started === null || $started->greaterThan($until)) {
            return null;
        }

        $monthly = $method === AssetDepreciationMethod::DECLINING_BALANCE
            ? $this->decliningMonthly($asset, $cost, $residual, $life)
            : bcdiv(bcsub($cost, $residual, 2), (string) $life, 2);

        $depreciable = bcsub($cost, $residual, 2);
        $accumulated = $this->normalize((string) $asset->accumulated_depreciation);
        $remaining = bcsub($depreciable, $accumulated, 2);

        if ($this->compare($remaining, '0.00') <= 0) {
            return null;
        }

        // Bulan pertama penyusutan TIDAK dipotong: barang yang mulai dipakai
        // tanggal 20 tetap disusutkan penuh mulai bulan itu, sesuai kebiasaan
        // sekolah yang menyusun anggaran per bulan ajaran.
        return $this->compare($monthly, $remaining) > 0 ? $remaining : $monthly;
    }

    protected function decliningMonthly(Asset $asset, string $cost, string $residual, int $life): string
    {
        $rate = bcdiv('2', (string) max(1, intdiv($life, 12)), 6);
        $monthlyRate = bcdiv($rate, '12', 8);
        $base = bcsub($cost, $this->normalize((string) $asset->accumulated_depreciation), 2);

        if ($this->compare($base, $residual) <= 0) {
            return '0.00';
        }

        return bcmul($base, $monthlyRate, 2);
    }

    protected function bookValueAfter(Asset $asset, string $amount): string
    {
        $value = bcsub($this->bookValue($asset), $amount, 2);

        return $this->compare($value, '0.00') < 0 ? '0.00' : $value;
    }

    /** @return array{0:\Carbon\Carbon,1:\Carbon\Carbon} */
    protected function periodBounds(string $period): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new AssetValidationException(['period' => ['Periode harus berformat YYYY-MM.']]);
        }

        $start = \Carbon\Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    protected function normalize(mixed $value): string
    {
        $value = is_string($value) || is_numeric($value) ? (string) $value : '0';

        return number_format((float) $value, 2, '.', '');
    }

    protected function compare(string $a, string $b): int
    {
        return bccomp($this->normalize($a), $this->normalize($b), 2);
    }

    protected function isPositive(string $amount): bool
    {
        return $this->compare($amount, '0.00') > 0;
    }
}
