<?php

namespace App\Modules\Asset\Domain\StateMachines;

use App\Modules\Asset\Domain\Exceptions\AssetDomainException;

/**
 * Transisi status yang terdaftar — bukan status yang dapat diubah sembarangan.
 *
 * Peta transisi ditulis sekali di sini dan dipakai bersama oleh layanan,
 * kebijakan, dan perintah terjadwal. Bila sebuah jalur tidak ada di peta, jalur
 * itu tidak ada — bukan "lupa diperiksa di salah satu tempat".
 */
abstract class StateMachine
{
    /**
     * Peta transisi: status awal => daftar status tujuan yang sah.
     *
     * @return array<string, list<string>>
     */
    abstract public static function transitions(): array;

    /** @return list<string> */
    public static function allowedFrom(string $from): array
    {
        return static::transitions()[$from] ?? [];
    }

    public static function can(string $from, string $to): bool
    {
        return in_array($to, static::allowedFrom($from), true);
    }

    /** Pastikan transisi sah; bila tidak, sebutkan yang sah supaya dapat ditindaklanjuti. */
    public static function assert(string $from, string $to, string $subject = 'data'): void
    {
        if ($from === $to) {
            return;
        }

        if (static::can($from, $to)) {
            return;
        }

        $allowed = static::allowedFrom($from);

        throw new AssetDomainException(
            $allowed === []
                ? "Status $subject \"$from\" bersifat final dan tidak dapat berpindah ke \"$to\"."
                : "Perpindahan status $subject dari \"$from\" ke \"$to\" tidak sah. Yang sah: ".implode(', ', $allowed).'.',
            409,
        );
    }
}
