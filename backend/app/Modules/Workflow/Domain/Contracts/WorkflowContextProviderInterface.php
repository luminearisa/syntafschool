<?php

namespace App\Modules\Workflow\Domain\Contracts;

/**
 * Mengubah entitas bisnis menjadi **konteks workflow** (§31, §65).
 *
 * Ini satu-satunya jembatan resmi antara data modul dan engine. Workflow tidak
 * pernah mengakses tabel bisnis secara langsung: modul mendaftarkan provider
 * untuk `subject_type`-nya, dan engine hanya bekerja dari snapshot konteks yang
 * dihasilkan provider tersebut. Dengan begitu:
 *
 *  - kondisi `amount > 10000000` dievaluasi terhadap snapshot, bukan tabel hidup;
 *  - riwayat approval tetap dapat direkonstruksi walau data bisnis berubah;
 *  - engine tidak memiliki ketergantungan kompilasi ke modul mana pun.
 */
interface WorkflowContextProviderInterface
{
    /** `subject_type` yang ditangani, mis. `finance.purchase_request`. */
    public function subjectType(): string;

    /**
     * Kunci konteks minimal yang harus ada agar workflow bisa berjalan.
     *
     * @return list<string> mis. ["amount", "department"]
     */
    public function requiredKeys(): array;

    /**
     * Ambil konteks dari entitas bisnis.
     *
     * @return array<string, mixed> konteks (harus dapat di-JSON-kan)
     */
    public function provide(string|int $subjectId): array;

    /** Ringkasan untuk label/kartu request, mis. kode & judul dokumen. */
    public function describe(string|int $subjectId): array;
}
