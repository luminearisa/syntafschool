<?php

namespace App\Modules\LMS\Domain\Contracts;

/**
 * Sumber data wali murid (§4, §7).
 *
 * Integrated → relasi `student_guardians` ERP; Standalone → `lms_guardians`.
 * Kontrak ini yang membuat Portal Wali bekerja pada kedua mode tanpa
 * business logic perlu tahu mode mana yang aktif (§4).
 */
interface ParentProviderInterface
{
    /** Kunci provider untuk audit & pengaturan (mis. `erp`, `standalone`). */
    public function key(): string;

    /** Apakah sumber data benar-benar dapat dipakai saat ini. */
    public function isAvailable(): bool;

    /**
     * Daftar wali dari seorang siswa.
     *
     * @return list<array{user_id:int|null,name:string,email:string|null,phone:string|null,relation:string|null,is_primary:bool,source:string}>
     */
    public function guardiansForStudent(int $studentId): array;

    /**
     * Id pengguna (akun) wali dari seorang siswa — siap dipakai notifikasi.
     *
     * @return list<int>
     */
    public function guardianUserIdsForStudent(int $studentId): array;

    /**
     * Siswa yang berada dalam tanggung jawab seorang wali.
     *
     * @return list<int>
     */
    public function studentIdsForGuardian(int $userId): array;

    /**
     * Apakah hubungan wali–siswa ini sah? Dipakai sebelum membuka data anak
     * (§44: wali hanya boleh mengakses anak dengan hubungan yang valid).
     */
    public function guards(int $userId, int $studentId): bool;
}
