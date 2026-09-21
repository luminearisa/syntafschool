<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuardianResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'gender_id' => $this->gender_id,
            'religion_id' => $this->religion_id,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'occupation_id' => $this->occupation_id,
            'income_range_id' => $this->income_range_id,
            'education_background_id' => $this->education_background_id,

            'gender' => $this->whenLoaded('gender', fn () => $this->ref($this->gender)),
            'religion' => $this->whenLoaded('religion', fn () => $this->ref($this->religion)),
            'occupation' => $this->whenLoaded('occupation', fn () => $this->ref($this->occupation)),
            'income_range' => $this->whenLoaded('incomeRange', fn () => $this->incomeRange ? [
                'id' => $this->incomeRange->id,
                'name' => $this->incomeRange->label,
            ] : null),
            'education_background' => $this->whenLoaded('educationBackground', fn () => $this->educationBackground ? [
                'id' => $this->educationBackground->id,
                'name' => $this->educationBackground->name,
            ] : null),

            'students' => $this->whenLoaded('students', fn () => $this->students->map(fn ($s) => [
                'id' => $s->id,
                'nis' => $s->nis,
                'full_name' => $s->full_name,
                'guardian_relation_id' => $s->pivot->guardian_relation_id,
                'is_primary' => (bool) $s->pivot->is_primary,
            ])->values()),

            'students_count' => $this->whenCounted('students'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: mixed, name: string}|null
     */
    private function ref(mixed $model): ?array
    {
        if (! $model) {
            return null;
        }

        return [
            'id' => $model->id,
            'name' => $model->name ?? $model->label ?? (string) $model->id,
        ];
    }
}
