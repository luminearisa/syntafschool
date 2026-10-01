<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetNumberGenerator;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\AssetTagStatus;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Domain\StateMachines\AssetStateMachine;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetTag;
use Illuminate\Support\Facades\DB;

/**
 * Pelabelan aset: cetak → tempel → verifikasi pindai.
 *
 * Label yang salah tempel lebih buruk daripada tanpa label: ia membuat orang
 * percaya bahwa barang sudah terlacak. Karena itu melabeli bukan sekadar
 * menyimpan baris — ada langkah verifikasi pindai yang harus cocok.
 */
class AssetTagService
{
    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetAuditService $audit,
    ) {}

    /** Tandai label sudah dicetak (boleh untuk banyak aset sekaligus). */
    public function markPrinted(User $actor, array $assetIds): array
    {
        $tags = AssetTag::query()
            ->whereIn('asset_id', $assetIds)
            ->whereIn('status', [AssetTagStatus::PENDING_TAGGING->value, AssetTagStatus::TAG_REPLACEMENT->value])
            ->get();

        foreach ($tags as $tag) {
            $tag->forceFill(['printed_at' => now(), 'printed_by' => $actor->id])->save();
        }

        return $tags->map(fn (AssetTag $tag): array => [
            'asset_id' => $tag->asset_id,
            'tag_code' => $tag->tag_code,
            'status' => $tag->status?->value ?? (string) $tag->status,
        ])->all();
    }

    /**
     * Verifikasi tempelan label: kode yang dipindai harus milik aset itu.
     *
     * Kekeliruan yang dijaga di sini nyata di lapangan — dua label tertukar saat
     * pemasangan, dan setahun kemudian stok opname menemukan "aset yang salah
     * tempat" padahal yang salah adalah labelnya.
     */
    public function attach(User $actor, Asset $asset, string $tagCode): Asset
    {
        $tag = $asset->tags()->where('tag_code', $tagCode)->first();

        if ($tag === null) {
            $other = AssetTag::query()->where('tag_code', $tagCode)->first();

            throw new AssetValidationException([
                'tag_code' => [$other !== null
                    ? 'Label ini milik aset lain ('.$other->asset_id.'). Periksa kembali penempelannya.'
                    : 'Kode label tidak dikenali.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $asset, $tag): Asset {
            $before = $asset->status instanceof AssetStatus ? $asset->status->value : (string) $asset->status;

            $tag->forceFill(['status' => AssetTagStatus::TAGGED->value, 'attached_at' => now(), 'attached_by' => $actor->id])->save();

            $asset->forceFill([
                'asset_tag' => $tag->tag_code,
                'tag_status' => AssetTagStatus::TAGGED->value,
                'tagged_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            if ($asset->status instanceof AssetStatus && $asset->status->value === AssetStatus::REGISTERED->value) {
                AssetStateMachine::assert(AssetStatus::REGISTERED->value, AssetStatus::ACTIVE->value, 'aset');
                $asset->forceFill(['status' => AssetStatus::ACTIVE->value])->save();
            }

            $this->audit->record(AuditAction::ASSET_UPDATED, $asset, $actor, [
                'status_before' => $before,
                'status_after' => $asset->status instanceof AssetStatus ? $asset->status->value : (string) $asset->status,
                'reason' => 'Label terpasang dan terverifikasi ('.$tag->tag_code.').',
            ]);

            return $asset->refresh();
        });
    }

    public function reportDamaged(User $actor, Asset $asset, ?string $notes = null): AssetTag
    {
        $tag = $asset->tags()->whereIn('status', [AssetTagStatus::TAGGED->value, AssetTagStatus::PENDING_TAGGING->value])->latest('id')->first();

        if ($tag === null) {
            throw new AssetValidationException(['tag' => ['Tidak ada label aktif pada aset ini.']]);
        }

        $tag->forceFill(['status' => AssetTagStatus::TAG_DAMAGED->value, 'damaged_at' => now(), 'notes' => $notes])->save();
        $asset->forceFill(['tag_status' => AssetTagStatus::TAG_DAMAGED->value])->save();

        $this->audit->record(AuditAction::ASSET_UPDATED, $asset, $actor, [
            'reason' => 'Label rusak'.($notes ? ': '.$notes : '.'),
        ]);

        return $tag;
    }

    /** Ganti label: label lama disimpan sebagai riwayat, tidak dihapus. */
    public function replace(User $actor, Asset $asset, ?string $notes = null): AssetTag
    {
        return DB::transaction(function () use ($actor, $asset, $notes): AssetTag {
            $old = $asset->tags()->latest('id')->first();

            $new = $asset->tags()->create([
                'tag_code' => $this->numbers->next('tag'),
                'status' => AssetTagStatus::PENDING_TAGGING->value,
                'replaced_tag_id' => $old?->id,
                'notes' => $notes,
            ]);

            if ($old !== null) {
                $old->forceFill(['status' => AssetTagStatus::TAG_REPLACEMENT->value, 'notes' => 'Diganti label '.$new->tag_code])->save();
            }

            $asset->forceFill(['tag_status' => AssetTagStatus::PENDING_TAGGING->value, 'asset_tag' => null])->save();

            $this->audit->record(AuditAction::ASSET_UPDATED, $asset, $actor, [
                'reason' => 'Penggantian label'.($old ? ' dari '.$old->tag_code : ''),
                'context' => ['new_tag' => $new->tag_code, 'replaced_tag' => $old?->tag_code],
            ]);

            return $new;
        });
    }
}
