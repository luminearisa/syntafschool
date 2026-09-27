<?php

namespace App\Http\Resources;

use App\Services\Finance\FinanceCollectionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sejarah satu aksi penagihan (§6). Baris ini sengaja tidak memuat angka
 * piutang: yang disimpan adalah "siapa menghubungi siapa, kapan, dari tangga
 * workflow mana", sementara sisa tagihan tetap dibaca dari mesin AR.
 */
class FinanceCollectionActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'invoice_id' => (int) $this->invoice_id,
            'invoice_number' => $this->whenLoaded('invoice', fn (): ?string => $this->invoice?->invoice_number),
            'action_type' => $this->action_type,
            'action_label' => $this->resource->typeLabel(),
            'stage' => $this->stage,
            // Stage dibekukan saat aksi dicatat, jadi labelnya dibaca dari
            // tangga workflow standar — bukan kolom teks yang bisa menyimpang.
            'stage_label' => FinanceCollectionService::STAGE_LABELS[$this->stage] ?? (string) $this->stage,
            'notes' => $this->notes,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'notified_count' => (int) $this->notified_count,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id !== null ? (int) $this->reference_id : null,
            'reference' => $this->whenLoaded('reference', fn (): ?array => $this->reference === null ? null : [
                'type' => $this->reference_type,
                'id' => (int) $this->reference_id,
                // documentNumber() dikenal setiap kelas di REFERENCE_TYPES, jadi
                // nomor write-off (§51) dan nomor cicilan (§7) dibaca lewat satu jalur.
                'number' => $this->reference->documentNumber(),
            ]),
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'created_by_name' => $this->whenLoaded('createdBy', fn (): ?string => $this->createdBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
