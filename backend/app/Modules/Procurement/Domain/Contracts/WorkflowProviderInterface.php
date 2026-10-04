<?php

namespace App\Modules\Procurement\Domain\Contracts;

/**
 * Workflow — mesin persetujuan lintas modul.
 *
 * Modul Procurement **tidak** membuat mesin approval sendiri. Ia memulai
 * definisi workflow (bawaan: "Persetujuan Permintaan Pengadaan") dan menerima
 * keputusan kembali lewat callback status.
 */
interface WorkflowProviderInterface
{
    /**
     * Mulai sebuah alur persetujuan atas suatu subjek.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>  {instance_id, status, steps}
     */
    public function start(string $workflow, array $payload): array;

    /**
     * Status terkini alur persetujuan.
     *
     * @return array<string, mixed>|null
     */
    public function status(string $instanceId): ?array;
}
