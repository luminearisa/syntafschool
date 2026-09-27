<?php

namespace App\Http\Resources;

use App\Models\FinanceOperation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris register operasi Keuangan (§28, §29).
 *
 * Bentuknya sengaja sama untuk pekerjaan dan peristiwa gagal: yang dibaca
 * bendahara adalah "apa, statusnya sampai mana, menyangkut dokumen mana, dan
 * boleh diulang?". Yang tidak ada di sini pun sengaja: tidak ada angka piutang
 * atau kas, karena operasi tidak pernah menggerakkan uang.
 */
class FinanceOperationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'kind' => $this->kind,
            'kind_label' => FinanceOperation::KIND_LABELS[$this->kind] ?? (string) $this->kind,
            'name' => $this->name,
            'name_label' => $this->resource->nameLabel(),
            'status' => $this->status,
            'status_label' => $this->resource->statusLabel(),
            'attempts' => (int) $this->attempts,
            // Kemajuan (§28): tiga angka, bukan persentase saja, supaya "1.980 dari
            // 2.000" tetap terbaca walau bagiannya dibulatkan.
            'total_rows' => $this->total_rows !== null ? (int) $this->total_rows : null,
            'processed_rows' => (int) $this->processed_rows,
            'progress_percent' => $this->resource->progressPercent(),
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id !== null ? (int) $this->reference_id : null,
            'reference_number' => $this->reference_number,
            // Pesan kegagalan ditampilkan apa adanya: ia jawaban dari "kenapa",
            // dan menyaringnya menjadi "terjadi kesalahan" membuat register ini
            // tidak bisa dipakai memperbaiki apa pun.
            'error' => $this->error,
            'error_kind' => $this->error_kind,
            'retryable' => (bool) $this->retryable,
            'result' => $this->result,
            'payload' => $this->payload,
            'context' => $this->context,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms !== null ? (int) $this->duration_ms : null,
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'created_by_name' => $this->whenLoaded('createdBy', fn (): ?string => $this->createdBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
