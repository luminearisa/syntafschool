<?php

namespace App\Modules\LMS\Domain\Contracts;

/**
 * Kelayakan belajar dari sisi keuangan (§8).
 *
 * LMS **tidak** menanamkan logika Finance. Kontrak ini hanya menanyakan
 * status, dan kebijakan `lms.finance.allow_learning_when_restricted`
 * yang menentukan apa yang dibatasi.
 */
interface FinancialEligibilityProviderInterface
{
    public function key(): string;

    public function isAvailable(): bool;

    /**
     * Status kelayakan seorang siswa.
     *
     * @return array{status:string,outstanding_amount:float,overdue_count:int,reason:string|null,checked_at:string,source:string}
     *                                                                                                                            status: ELIGIBLE|RESTRICTED|SUSPENDED|UNKNOWN
     */
    public function eligibilityFor(int $studentId): array;

    /**
     * Id siswa yang sedang dibatasi (untuk daftar pantau admin).
     *
     * @return list<int>
     */
    public function restrictedStudentIds(int $limit = 100): array;
}
