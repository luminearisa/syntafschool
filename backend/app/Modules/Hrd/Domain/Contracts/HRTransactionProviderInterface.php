<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak transaksi SDM (§33, §65).
 *
 * Modul lain (mis. Keuangan yang ingin menahan pembayaran akhir, atau Aset yang
 * ingin menunggu pengembalian barang) dapat memulai transaksi HR lewat kontrak
 * ini — dan menerima hasil yang sama seperti bila HRD yang memulainya: status,
 * persetujuan, jejak audit, dan linimasa.
 */
interface HRTransactionProviderInterface
{
    /**
     * Ajukan transaksi SDM.
     *
     * `$typeCode` mengacu pada `hr_transaction_types.code` yang aktif.
     *
     * @param  array<string, mixed>  $payload  nilai yang diminta (posisi, unit, tanggal berlaku, …)
     * @return array<string, mixed>
     */
    public function submit(int $employeeId, string $typeCode, array $payload, ?int $actorUserId = null): array;

    /**
     * Terapkan transaksi yang sudah disetujui (idempoten).
     *
     * @return array<string, mixed>
     */
    public function apply(int $transactionId, ?int $actorUserId = null): array;

    /** @return array<string, mixed>|null */
    public function findById(int $transactionId): ?array;

    /**
     * Transaksi yang menunggu tindakan pada satu unit.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingInOrganization(int $organizationId): array;

    /** Apakah pegawai memiliki transaksi berjalan (menghalangi transaksi baru)? */
    public function hasOpenTransaction(int $employeeId): bool;
}
