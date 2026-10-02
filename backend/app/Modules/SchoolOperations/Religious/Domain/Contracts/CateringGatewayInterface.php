<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Catering (§46, §47, §80). Iftar/Suhoor diteruskan sebagai kebutuhan makan, bukan transaksi makanan baru.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface CateringGatewayInterface
{
    /** Ajukan rencana makan (iftar/suhoor) beserta perkiraan peserta. */
    public function requestMealPlan(array $payload): array;

    /** Perbarui perkiraan jumlah peserta. */
    public function updateHeadcount(string $reference, int $expectedParticipants): array;

    /** Status rencana makan. */
    public function mealPlanStatus(string $reference): ?array;

    /** Ketersediaan modul catering. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
