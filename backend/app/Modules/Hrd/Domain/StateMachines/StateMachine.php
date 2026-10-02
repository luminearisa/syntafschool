<?php

namespace App\Modules\Hrd\Domain\StateMachines;

use App\Modules\Hrd\Domain\Exceptions\LifecycleTransitionException;

/**
 * Mesin keadaan sederhana yang dapat dipakai ulang.
 *
 * Dipakai dua tempat dengan aturan berbeda: siklus hidup pegawai (§68) dan
 * keadaan transaksi (§33). Keduanya membutuhkan hal yang sama — daftar transisi
 * yang sah, penolakan yang jelas, dan satu tempat untuk mengubah aturan itu.
 */
abstract class StateMachine
{
    /** @return array<string, list<string>> */
    abstract public function transitions(): array;

    abstract public function label(string $state): string;

    /** @return list<string> */
    public function allowedFrom(string $state): array
    {
        return $this->transitions()[$state] ?? [];
    }

    public function can(string $from, string $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return in_array($to, $this->allowedFrom($from), true);
    }

    /**
     * Pastikan perpindahan sah, atau hentikan dengan pesan yang menyebutkan
     * keadaan asal, tujuan, dan langkah yang tersedia.
     */
    public function assert(string $from, string $to, ?string $hint = null): void
    {
        if ($this->can($from, $to)) {
            return;
        }

        $allowed = $this->allowedFrom($from);
        $detail = $allowed === []
            ? 'Keadaan ini bersifat akhir.'
            : 'Langkah yang tersedia: '.implode(', ', array_map(fn (string $s) => $this->label($s), $allowed)).'.';

        throw LifecycleTransitionException::between($this->label($from), $this->label($to), trim(($hint ?? '').' '.$detail));
    }

    public function isTerminal(string $state): bool
    {
        return $this->allowedFrom($state) === [];
    }
}
