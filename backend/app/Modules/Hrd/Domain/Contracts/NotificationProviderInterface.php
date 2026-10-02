<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak notifikasi — modul HRD **memakai**, bukan membuat sendiri (§55, §87).
 *
 * Kanal (dalam aplikasi, surel, WhatsApp, push) tidak pernah dipaku di kode HRD:
 * HRD menyatakan peristiwa dan penerimanya, penyedia kanal yang menentukan
 * bagaimana pesannya dikirim. Bila penyedia WhatsApp belum dipasang, notifikasi
 * tetap tercatat dan tetap muncul di aplikasi.
 */
interface NotificationProviderInterface
{
    /**
     * Kirim satu peristiwa ke sekumpulan penerima.
     *
     * @param  list<int>  $userIds
     * @param  list<string>  $roles  peran yang juga menerima (mis. HR_MANAGER)
     * @param  array<string, mixed>  $payload
     */
    public function notify(array $userIds, array $roles, string $event, array $payload): void;

    /**
     * Kirim dalam jumlah banyak dan kembalikan cacah penerima yang benar-benar
     * memperoleh pesan (bukan cacah permintaan).
     *
     * @param  array{users?: list<int>, roles?: list<string>}  $recipients
     * @param  array<string, mixed>  $payload
     */
    public function notifyMany(array $recipients, string $event, array $payload): int;

    /** Kanal yang tersedia pada instalasi ini. */
    public function availableChannels(): array;
}
