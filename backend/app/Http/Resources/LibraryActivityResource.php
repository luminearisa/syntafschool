<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris jejak audit Library (dari paket spatie/laravel-activitylog).
 *
 * Nilai lama & baru dibaca dari kolom `attribute_changes`
 * (struktur: attributes = nilai baru, old = nilai lama).
 */
class LibraryActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $changes = $this->attribute_changes ?? collect();

        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            'event' => $this->event,
            'description' => $this->description,
            'subject_type' => $this->subject_type !== null ? class_basename($this->subject_type) : null,
            'subject_id' => $this->subject_id,
            'causer' => $this->whenLoaded('causer', fn () => $this->causer ? [
                'id' => $this->causer->id,
                'name' => $this->causer->name,
            ] : null),
            'causer_id' => $this->causer_id,
            'old_values' => (array) ($changes['old'] ?? []),
            'new_values' => (array) ($changes['attributes'] ?? []),
            'properties' => (array) ($this->properties?->toArray() ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}