<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris master data Library.
 *
 * Bentuknya seragam untuk semua master sehingga antarmuka dapat memakai satu
 * tabel & satu form: seluruh kolom model + `status` yang sudah diseragamkan
 * (kolom lama `book_categories.is_active` tetap didukung), induk, jumlah anak,
 * pemakaian (`usage_count`), dan relasi yang dimuat.
 */
class LibraryMasterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->attributesToArray();

        // usage_count bukan kolom; diambil dari atribut sementara yang
        // dipasang controller, lalu dirapikan agar tidak muncul dua kali.
        $usageCount = (int) ($attributes['usage_count'] ?? 0);
        unset($attributes['usage_count']);

        return array_merge($attributes, [
            'status' => $this->statusValue(),
            'is_active' => $this->isMasterActive(),
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'id' => $this->parent->id,
                'code' => $this->parent->code,
                'name' => $this->parent->name,
            ] : null),
            'children_count' => (int) ($this->children_count ?? 0),
            'library' => $this->whenLoaded('library', fn () => $this->library ? [
                'id' => $this->library->id,
                'code' => $this->library->code,
                'name' => $this->library->name,
            ] : null),
            'relations' => $this->relationsPayload(),
            'usage_count' => $usageCount,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ]);
    }

    /**
     * Relasi yang dimuat (country/system/publisher) dalam bentuk ringkas.
     *
     * @return array<string, array<string, mixed>|null>
     */
    protected function relationsPayload(): array
    {
        $payload = [];

        foreach (['country', 'system', 'publisher'] as $name) {
            if (! $this->resource->relationLoaded($name)) {
                continue;
            }

            $related = $this->resource->getRelation($name);

            $payload[$name] = $related ? [
                'id' => $related->id,
                'code' => $related->code ?? null,
                'name' => $related->name,
            ] : null;
        }

        return $payload;
    }
}