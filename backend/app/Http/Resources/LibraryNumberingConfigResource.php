<?php

namespace App\Http\Resources;

use App\Models\LibraryNumberingConfig;
use App\Services\Library\NumberingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryNumberingConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'library_id' => $this->library_id,
            'key' => $this->key,
            'label' => LibraryNumberingConfig::KEYS[$this->key] ?? $this->key,
            'format' => $this->format,
            'prefix' => $this->prefix,
            'padding' => $this->padding,
            'reset_period' => $this->reset_period,
            'start_number' => $this->start_number,
            'last_number' => $this->last_number,
            'last_reset_at' => $this->last_reset_at?->toIso8601String(),
            // Pratinjau nomor berikutnya — hanya bila relasi library tersedia.
            'preview' => $this->whenLoaded('library', fn () => $this->library !== null
                ? app(NumberingService::class)->preview($this->resource, $this->library)
                : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}