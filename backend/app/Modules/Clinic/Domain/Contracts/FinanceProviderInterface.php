<?php

namespace App\Modules\Clinic\Domain\Contracts;

/**
 * Penagihan layanan klinis yang berbayar.
 *
 * Clinic tidak menulis jurnal akuntansi sendiri. Bila sekolah tidak menagih
 * layanan kesehatan (umum di UKS), implementasi null dipakai dan setiap
 * permintaan penagihan mengembalikan `NOT_BILLABLE` tanpa efek samping.
 */
interface FinanceProviderInterface
{
    public const CREATED = 'CREATED';
    public const NOT_BILLABLE = 'NOT_BILLABLE';
    public const DISABLED = 'DISABLED';
    public const FAILED = 'FAILED';

    /** Apakah modul keuangan siap menerima tagihan dari Clinic. */
    public function isEnabled(): bool;

    /**
     * Terbitkan tagihan atas sebuah layanan klinis.
     *
     * @param array{patient_id: int, service: string, description: string, amount: float, reference: string, date: string} $payload
     * @return array{status: string, invoice_id: ?int, invoice_number: ?string, message: string}
     */
    public function billService(array $payload): array;

    /** Daftar tarif yang dapat ditagih (kosong bila penagihan tidak dipakai). */
    public function billableServices(): array;
}
