<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * SIAKAD — konteks akademik dan pengakuan aktivitas (§56–57).
 *
 * Pengakuan akademik (nilai ekstrakurikuler pada rapor) **tidak** ditulis
 * langsung ke tabel akademik. Modul ini mengirim *usulan* pengakuan; SIAKAD
 * yang memutuskan menerimanya lewat workflow dan kebijakan penilaiannya.
 */
interface AcademicProviderInterface
{
    /** Konteks periode akademik aktif (tahun ajaran, semester, tanggal). */
    public function context(): array;

    public function classLevel(int $studentId): ?int;

    public function programName(int $studentId): ?string;

    /** Ringkasan akademik (mis. rata-rata nilai, peringkat) untuk aturan kelayakan. */
    public function standing(int $studentId): array;

    /** Kirim usulan pengakuan; kembalikan status penerimaan usulan. */
    public function submitRecognition(int $studentId, array $payload): array;

    /** Ringkasan aktivitas untuk rapor (format diserahkan ke modul akademik). */
    public function reportCardSummary(int $studentId, ?int $periodId = null): array;
}
