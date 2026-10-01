<?php

namespace App\Modules\Bk\Domain\Contracts;

/**
 * Sumber data siswa untuk Modul BK.

Modul BK **tidak** menyimpan nama, kelas, atau identitas siswa: semuanya dibaca
dari master ERP lewat kontrak ini sehingga tidak ada dua sumber kebenaran.
 */
interface StudentProviderInterface
{

    /**
     * Ringkasan identitas siswa untuk header profil dukungan & dokumen BK.
     *
     * @return array{
     *     id: int, nis: ?string, nisn: ?string, name: string, gender: ?string,
     *     birth_date: ?string, age_years: ?int, class_id: ?int, class_name: ?string,
     *     homeroom_teacher_id: ?int, is_active: bool
     * }|null
     */
    public function snapshot(int $studentId): ?array;

    /**
     * Pencarian siswa untuk membuat referral/kasus (nama/NIS/NISN).
     *
     * @return list<array{id: int, nis: ?string, name: string, class_name: ?string}>
     */
    public function search(string $term, int $limit = 20): array;
}
