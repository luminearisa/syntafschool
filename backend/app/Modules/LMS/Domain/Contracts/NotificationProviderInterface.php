<?php

namespace App\Modules\LMS\Domain\Contracts;

/**
 * Kanal pemberitahuan (§10, §4).
 *
 * LMS menumpang notification engine yang sudah ada; kontrak ini memastikan
 * business logic hanya berbicara pada satu gerbang dan kanal yang tidak
 * tersedia (mis. WhatsApp tanpa gateway) dilaporkan apa adanya, bukan gagal
 * diam-diam.
 */
interface NotificationProviderInterface
{
    public function key(): string;

    public function isAvailable(): bool;

    /** @return list<string> kanal yang benar-benar siap dipakai (in_app/email/push/whatsapp). */
    public function supportedChannels(): array;

    /**
     * Kirim satu peristiwa ke sekumpulan pengguna.
     *
     * Wajib idempotent: `idempotencyKey` yang sama tidak boleh mengirim ulang.
     *
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $payload
     * @param  list<string>|null  $channels  null = pakai kanal peristiwa
     * @return array{sent:int,skipped:int,channels:list<string>,unavailable:list<string>,event:string}
     */
    public function send(array $userIds, string $event, array $payload, ?array $channels = null, ?string $idempotencyKey = null): array;

    /** Ringkasan kesehatan provider untuk halaman pengaturan & health check. */
    public function status(): array;
}
