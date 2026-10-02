<?php

namespace App\Modules\Hrd\Infrastructure\Lifecycle;

use App\Modules\Hrd\Domain\Contracts\TransactionEffectInterface;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrTransaction;
use RuntimeException;

/**
 * Registry handler efek transaksi (§33, §82).
 *
 * Mesin transaksi tidak mengenal nama jenis transaksi sama sekali: ia hanya
 * bertanya kepada registry "siapa yang menangani `TRANSFER`?". Karena itu menambah
 * jenis transaksi baru tidak menyentuh mesinnya — cukup mendaftarkan handler di
 * service provider modul.
 */
class TransactionEffectRegistry
{
    /** @var array<string, TransactionEffectInterface> */
    private array $effects = [];

    public function register(TransactionEffectInterface $effect): void
    {
        $this->effects[$effect->alias()] = $effect;
    }

    /** @return array<string, TransactionEffectInterface> */
    public function all(): array
    {
        return $this->effects;
    }

    public function has(string $alias): bool
    {
        return isset($this->effects[$alias]);
    }

    /**
     * Resolusi handler: pertama dari kolom jenis transaksi, lalu dari kecocokan
     * handler yang menyatakan dirinya mendukung jenis tersebut.
     */
    public function resolve(HrTransaction $transaction): TransactionEffectInterface
    {
        $alias = $transaction->type?->effect_handler;

        if (is_string($alias) && $alias !== '' && isset($this->effects[$alias])) {
            return $this->effects[$alias];
        }

        foreach ($this->effects as $effect) {
            if ($effect->supports($transaction)) {
                return $effect;
            }
        }

        throw new RuntimeException(
            "Tidak ada handler efek untuk jenis transaksi \"{$transaction->type_code}\". "
            .'Daftarkan handler-nya pada TransactionEffectRegistry, atau perbaiki kolom effect_handler pada jenis transaksi.'
        );
    }

    /** Dipakai pengujian & pemeriksa kesehatan modul. */
    public function assertHealthy(): void
    {
        if ($this->effects === []) {
            throw HrdValidationException::forField(
                'effect_handler',
                'Belum ada handler efek transaksi yang terdaftar — seluruh pengajuan transfer/promosi akan gagal diterapkan.',
                'hr.transaction.no_effects',
            );
        }
    }
}
