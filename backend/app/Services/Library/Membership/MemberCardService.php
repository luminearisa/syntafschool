<?php

namespace App\Services\Library\Membership;

use App\Models\Library;
use App\Models\LibraryMember;
use App\Models\LibraryMemberCard;
use App\Services\Library\NumberingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Kartu anggota.
 *
 * Nomor kartu dibangkitkan dari konfigurasi penomoran perpustakaan, dan
 * `qr_token` dibuat server-side untuk dipakai sirkulasi/verifikasi pada
 * Fase 4 (§39). Cetak ulang tidak mengubah nomor — hanya menambah penghitung
 * cetak untuk keperluan batch print (§41).
 */
class MemberCardService
{
    public function __construct(protected NumberingService $numbering)
    {
    }

    /**
     * Terbitkan kartu aktif untuk seorang anggota.
     *
     * Kartu aktif lama tidak dihapus, melainkan di-revoke agar jejak tetap ada.
     *
     * @param  array<string, mixed>  $options
     */
    public function generate(LibraryMember $member, array $options = []): LibraryMemberCard
    {
        if (! $member->library_id) {
            throw ValidationException::withMessages([
                'member' => 'Anggota belum terhubung ke perpustakaan.',
            ]);
        }

        /** @var Library $library */
        $library = Library::query()->findOrFail($member->library_id);

        return DB::transaction(function () use ($member, $library, $options): LibraryMemberCard {
            $this->revokeActiveCards($member);

            $card = LibraryMemberCard::create([
                'library_id' => $library->id,
                'member_id' => $member->id,
                'card_number' => $options['card_number'] ?? $this->numbering->next($library, 'member_card'),
                'qr_token' => Str::random(64),
                'barcode' => $options['barcode'] ?? null,
                'issued_at' => $options['issued_at'] ?? Carbon::now()->toDateString(),
                'valid_until' => $options['valid_until'] ?? $member->valid_until?->toDateString(),
                'status' => LibraryMemberCard::STATUS_ACTIVE,
            ]);

            activity('library')
                ->event('CARD_GENERATED')
                ->performedOn($card)
                ->withProperties([
                    'member_id' => $member->id,
                    'card_number' => $card->card_number,
                ] + $this->context())
                ->log('Kartu anggota diterbitkan');

            return $card->refresh();
        });
    }

    /**
     * Terbitkan kartu untuk banyak anggota sekaligus (§41 & §56).
     *
     * @param  list<int>  $memberIds
     * @return array{generated:int,skipped:list<int>}
     */
    public function batchGenerate(array $memberIds, array $options = []): array
    {
        $members = LibraryMember::query()->whereIn('id', $memberIds)->get();

        $generated = 0;
        $skipped = [];

        foreach ($members as $member) {
            // Tanpa tanggal berlaku, kartu tetap boleh dicetak dengan masa
            // berlaku mengikuti keanggotaan (bisa kosong).
            $this->generate($member, $options);
            $generated++;
        }

        foreach (array_diff($memberIds, $members->pluck('id')->all()) as $missing) {
            $skipped[] = (int) $missing;
        }

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /**
     * Catat pencetakan kartu (print/batch print).
     */
    public function markPrinted(LibraryMemberCard $card): LibraryMemberCard
    {
        $card->update([
            'printed_count' => $card->printed_count + 1,
            'last_printed_at' => Carbon::now(),
        ]);

        return $card->refresh();
    }

    public function revoke(LibraryMemberCard $card, string $reason): LibraryMemberCard
    {
        if ($card->status === LibraryMemberCard::STATUS_REVOKED) {
            throw ValidationException::withMessages(['card' => 'Kartu sudah dicabut.']);
        }

        $card->update(['status' => LibraryMemberCard::STATUS_REVOKED]);

        activity('library')
            ->event('CARD_REVOKED')
            ->performedOn($card)
            ->withProperties(['reason' => $reason] + $this->context())
            ->log('Kartu anggota dicabut');

        return $card->refresh();
    }

    public function activeCard(LibraryMember $member): ?LibraryMemberCard
    {
        return LibraryMemberCard::query()
            ->where('member_id', $member->id)
            ->where('status', LibraryMemberCard::STATUS_ACTIVE)
            ->latest('id')
            ->first();
    }

    /**
     * Kartu milik pengguna yang sedang login — untuk halaman "Kartu Saya" (§40).
     */
    public function cardForUser(int $userId): ?LibraryMemberCard
    {
        $member = LibraryMember::query()
            ->where('user_id', $userId)
            ->whereIn('status', LibraryMember::ACTIVE_STATUSES)
            ->latest('id')
            ->first();

        return $member !== null ? $this->activeCard($member) : null;
    }

    protected function revokeActiveCards(LibraryMember $member): void
    {
        LibraryMemberCard::query()
            ->where('member_id', $member->id)
            ->where('status', LibraryMemberCard::STATUS_ACTIVE)
            ->update(['status' => LibraryMemberCard::STATUS_REVOKED]);
    }

    /**
     * @return array<string, string>
     */
    protected function context(): array
    {
        if (app()->runningInConsole()) {
            return [];
        }

        return [
            'ip' => request()->ip(),
            'user_agent' => (string) request()->userAgent(),
        ];
    }
}