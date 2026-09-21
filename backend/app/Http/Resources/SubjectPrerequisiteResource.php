<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubjectPrerequisiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject_id' => $this->subject_id,
            'prerequisite_subject_id' => $this->prerequisite_subject_id,
            'notes' => $this->notes,
            'subject' => $this->whenLoaded('subject', fn () => [
                'id' => $this->subject->id,
                'code' => $this->subject->code,
                'name' => $this->subject->name,
            ]),
            'prerequisite' => $this->whenLoaded('prerequisite', fn () => [
                'id' => $this->prerequisite->id,
                'code' => $this->prerequisite->code,
                'name' => $this->prerequisite->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
