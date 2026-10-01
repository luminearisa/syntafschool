<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\AssetProviderInterface;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\User as AssetUser;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak yang TAWARKAN modul Asset kepada modul lain (mis. Kegiatan).
 *
 * Modul lain tidak menyentuh tabel aset: mereka bertanya di sini. Pinjaman yang
 * dibuat lewat jalur ini pun melewati aturan yang sama — status aset, pinjaman
 * berjalan, dan kondisi — sehingga tidak ada jalan pintas yang melewati
 * pengamanan modul.
 */
class AssetModuleProvider implements AssetProviderInterface
{
    public function info(int $assetId): ?array
    {
        $asset = Asset::query()->with('location:id,name')->find($assetId);

        if ($asset === null) {
            return null;
        }

        return [
            'id' => $asset->id,
            'asset_code' => $asset->asset_code,
            'name' => $asset->name,
            'status' => $asset->status?->value ?? (string) $asset->status,
            'condition' => $asset->condition?->value ?? (string) $asset->condition,
            'location' => $asset->location?->name,
            'loanable' => $asset->isLoanable(),
            'on_loan' => $asset->active_loan_id !== null,
        ];
    }

    public function lend(array $payload): array
    {
        $assetIds = array_map('intval', (array) ($payload['asset_ids'] ?? [$payload['asset_id'] ?? 0]));
        $actor = \App\Models\User::query()->findOrFail((int) ($payload['actor_id'] ?? 0));

        $loan = app(\App\Modules\Asset\Application\Services\AssetLoanService::class)->request($actor, [
            'borrower_id' => $payload['borrower_id'] ?? $actor->id,
            'borrower_type' => $payload['borrower_type'] ?? 'STUDENT',
            'purpose' => $payload['purpose'] ?? 'Peminjaman dari modul lain.',
            'start_at' => $payload['start_at'] ?? now()->toDateTimeString(),
            'due_at' => $payload['due_at'] ?? now()->addDays(3)->toDateTimeString(),
            'activity_reference' => $payload['activity_reference'] ?? null,
        ], array_values(array_filter($assetIds)));

        return ['status' => 'REQUESTED', 'loan_id' => $loan->id, 'loan_number' => $loan->loan_number];
    }

    public function collect(array $payload): array
    {
        $loanId = (int) ($payload['loan_id'] ?? 0);
        $loan = \App\Modules\Asset\Domain\Enums\LoanStatus::cases() !== [] ? \App\Modules\Asset\Models\AssetLoan::query()->find($loanId) : null;

        if ($loan === null) {
            return ['status' => 'NOT_FOUND'];
        }

        $actor = \App\Models\User::query()->findOrFail((int) ($payload['actor_id'] ?? 0));

        if ($loan->status === \App\Modules\Asset\Domain\Enums\LoanStatus::HANDED_OVER || $loan->status === \App\Modules\Asset\Domain\Enums\LoanStatus::RESERVED) {
            app(\App\Modules\Asset\Application\Services\AssetLoanService::class)->handover($actor, $loan, []);
            app(\App\Modules\Asset\Application\Services\AssetLoanService::class)->activate($actor, $loan->refresh());
            $loan->refresh();
        }

        $returned = app(\App\Modules\Asset\Application\Services\AssetLoanService::class)->return($actor, $loan, [
            'condition' => $payload['condition'] ?? 'GOOD',
            'notes' => $payload['notes'] ?? 'Dikembalikan melalui modul pemanggil.',
        ]);

        return ['status' => 'RETURNED', 'loan_number' => $returned->loan_number, 'condition' => $payload['condition'] ?? 'GOOD'];
    }

    public function available(array $filters): array
    {
        $query = Asset::query()
            ->whereNull('active_loan_id')
            ->whereIn('status', [AssetStatus::ACTIVE->value, AssetStatus::ASSIGNED->value, AssetStatus::IN_STORAGE->value]);

        if (isset($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (isset($filters['location_id'])) {
            $query->where('location_id', (int) $filters['location_id']);
        }

        return $query->limit((int) ($filters['limit'] ?? 50))->get()
            ->map(fn (Asset $asset): array => [
                'id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'name' => $asset->name,
                'condition' => $asset->condition?->value ?? (string) $asset->condition,
            ])
            ->all();
    }
}
