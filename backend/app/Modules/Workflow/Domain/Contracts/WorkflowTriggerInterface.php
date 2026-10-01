<?php

namespace App\Modules\Workflow\Domain\Contracts;

use App\Models\User;
use App\Modules\Workflow\Models\WorkflowInstance;

/**
 * Titik masuk bagi modul ERP untuk memulai workflow (§44, §45).
 *
 * Modul memanggil trigger (langsung, lewat event, atau API internal) alih-alih
 * membuat tabel approval sendiri. Trigger bertanggung jawab menyiapkan
 * konteks, engine bertanggung jawab atas sisa alurnya.
 */
interface WorkflowTriggerInterface
{
    /** Kunci trigger, mis. `finance.purchase_request.submitted`. */
    public function key(): string;

    /** Kode definisi workflow yang dipakai trigger ini. */
    public function definitionCode(): string;

    /**
     * Mulai workflow untuk sebuah subjek bisnis.
     *
     * @param  array<string, mixed>  $payload  data dari modul pemanggil
     */
    public function start(string|int $subjectId, array $payload = [], ?User $actor = null, bool $autoSubmit = true): WorkflowInstance;
}
