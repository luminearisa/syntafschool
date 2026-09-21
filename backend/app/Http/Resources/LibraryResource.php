<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'timezone' => $this->timezone,
            'logo_path' => $this->logo_path,
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'allowed_transitions' => $this->allowedTransitions(),

            'locations_count' => (int) ($this->locations_count ?? 0),
            'operating_hours_count' => (int) ($this->operating_hours_count ?? 0),
            'closures_count' => (int) ($this->closures_count ?? 0),
            'numbering_configs_count' => (int) ($this->numbering_configs_count ?? 0),

            'school' => $this->whenLoaded('school', fn () => $this->school ? [
                'id' => $this->school->id,
                'name' => $this->school->name,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}