<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * EventProviderInterface
 *
 * Disediakan modul Event untuk modul lain (§4).
 *
 * Modul Event hanya melakukan orkestrasi: seluruh data pokok tetap dimiliki
 * modul asalnya. Bila sumber belum terpasang, implementasi wajib menyatakan
 * `available() === false` beserta alasannya — bukan mengarang data.
 */
interface EventProviderInterface
{
    /** Membuat event secara programatik. */
    public function createEvent(array $payload): array;

    /** Ringkasan satu event. */
    public function getEvent(int $eventId): ?array;

    /** Event yang akan datang. */
    public function getUpcomingEvents(array $filters = []): array;

    /** Daftar peserta event. */
    public function getParticipants(int $eventId): array;

    /** Jadwal & rundown event. */
    public function getEventSchedule(int $eventId): array;

    /** Status operasional event. */
    public function getEventStatus(int $eventId): array;

    /** Sumber daya yang dipakai event. */
    public function getEventResources(int $eventId): array;
}
