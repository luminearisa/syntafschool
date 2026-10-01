<?php

namespace App\Modules\Bk\Domain\Contracts;

/**
 * Sinyal perkembangan dari aktivitas siswa (kepemimpinan, sosial, minat).

Delegasi ke `CounselingProviderInterface` milik Modul Activity: BK hanya menerima
ringkasan yang memang boleh dibagikan, bukan catatan aktivitas internal.
 */
interface StudentActivityProviderInterface
{

    /**
     * @return list<array{label: string, detail: ?string}> sinyal perkembangan (kosong bila tidak diizinkan)
     */
    public function developmentSignals(int $studentId, int $userId): array;
}
