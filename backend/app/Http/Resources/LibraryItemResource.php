<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'library_id' => $this->library_id,
            'bibliography_id' => $this->bibliography_id,

            'item_code' => $this->item_code,
            // Nomor inventaris dipisahkan dari item code (§21).
            'accession_number' => $this->accession_number,

            'barcode' => $this->barcode,
            'rfid_tag' => $this->rfid_tag,
            'rfid_status' => $this->rfid_status,

            'location_id' => $this->location_id,
            'condition_id' => $this->condition_id,
            'status_id' => $this->status_id,
            'status' => $this->status,
            'is_available' => $this->isAvailable(),
            'is_circulation_controlled' => in_array($this->status, \App\Models\LibraryItem::CIRCULATION_STATUSES, true),

            'acquisition_date' => $this->acquisition_date?->toDateString(),
            'acquisition_source_id' => $this->acquisition_source_id,
            'acquisition_price' => $this->acquisition_price !== null ? (float) $this->acquisition_price : null,
            'funding_source' => $this->funding_source,
            'notes' => $this->notes,

            'bibliography' => $this->whenLoaded('bibliography', fn () => $this->bibliography ? [
                'id' => $this->bibliography->id,
                'title' => $this->bibliography->title,
                'slug' => $this->bibliography->slug,
                'control_number' => $this->bibliography->control_number,
            ] : null),

            'library' => $this->whenLoaded('library', fn () => $this->library ? [
                'id' => $this->library->id,
                'code' => $this->library->code,
                'name' => $this->library->name,
            ] : null),

            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'code' => $this->location->code,
                'name' => $this->location->name,
                'type' => $this->location->type,
            ] : null),

            'condition' => $this->whenLoaded('condition', fn () => $this->condition ? [
                'id' => $this->condition->id,
                'name' => $this->condition->name,
            ] : null),

            'collection_status' => $this->whenLoaded('collectionStatus', fn () => $this->collectionStatus ? [
                'id' => $this->collectionStatus->id,
                'name' => $this->collectionStatus->name,
            ] : null),

            'acquisition_source' => $this->whenLoaded('acquisitionSource', fn () => $this->acquisitionSource ? [
                'id' => $this->acquisitionSource->id,
                'name' => $this->acquisitionSource->name,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}