<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/**
 * Fondasi RFID/NFC/QR (§37/§38). Phase 2 tidak mendefinisikan protokol
 * perangkat keras; modul ini menerima event hasil pembacaan perangkat.
 */
interface TransportationAccessProviderInterface
{
    public function scan(array $payload): array;

    public function deviceAvailable(string $deviceId): bool;

    public function issueBoardingToken(int $studentId, int $tripId, array $options = []): array;

    public function verifyBoardingToken(string $token): array;
}
