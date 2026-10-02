<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak posisi & formasi (§2, §65, §49).
 *
 * Recruitment fase berikutnya membaca ini untuk mengetahui kursi mana yang kosong;
 * Keuangan membacanya untuk anggaran per posisi; Workflow memakainya untuk
 * menentukan pemegang jabatan yang harus menyetujui.
 */
interface PositionProviderInterface
{
    /** @return array<string, mixed>|null */
    public function findById(int $positionId): ?array;

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array;

    /**
     * Siapa yang menduduki posisi ini pada tanggal tertentu (default hari ini).
     *
     * @return list<array<string, mixed>>
     */
    public function holdersOf(int $positionId, ?string $onDate = null): array;

    /**
     * Posisi kosong/kurang terisi pada satu unit.
     *
     * @return list<array<string, mixed>>
     */
    public function vacancies(?int $organizationId = null): array;

    /**
     * Ringkasan formasi: diizinkan, terisi, kosong.
     *
     * @return array{authorized: int, planned: int, filled: int, vacant: int}
     */
    public function headcountSummary(?int $organizationId = null): array;

    /**
     * Posisi yang melapor ke sebuah posisi.
     *
     * @return list<array<string, mixed>>
     */
    public function reportsTo(int $positionId): array;
}
