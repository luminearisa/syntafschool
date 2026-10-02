<?php

namespace App\Modules\Hrd\Domain\Exceptions;

/**
 * Perpindahan keadaan yang tidak sah (§68).
 *
 * Misalnya mencoba menerapkan transaksi yang belum disetujui, atau memindahkan
 * pegawai dari `archived` ke `probation` tanpa proses rehire. Pesannya
 * menyebutkan keadaan asal dan tujuan supaya admin tahu langkah mana yang
 * hilang — bukan sekadar "operasi tidak diizinkan".
 */
class LifecycleTransitionException extends HrdException
{
    protected string $ruleCode = 'hr.lifecycle.invalid_transition';

    public static function between(string $from, string $to, ?string $hint = null): self
    {
        $message = "Perpindahan keadaan dari \"{$from}\" ke \"{$to}\" tidak diizinkan.";

        if ($hint !== null) {
            $message .= ' '.$hint;
        }

        return new self($message);
    }

    public static function workflow(string $reason): self
    {
        return new self($reason, 0, null);
    }

    public static function notApplied(): self
    {
        return new self('Transaksi harus disetujui sebelum efeknya dapat diterapkan.');
    }
}
