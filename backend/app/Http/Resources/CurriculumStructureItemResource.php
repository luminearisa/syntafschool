<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurriculumStructureItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'curriculum_structure_id' => $this->curriculum_structure_id,
            'subject_id' => $this->subject_id,
            'subject_group_id' => $this->subject_group_id,
            'semester' => (int) $this->semester,
            'weekly_hours' => (int) $this->weekly_hours,
            'is_required' => (bool) $this->is_required,
            'sort_order' => (int) $this->sort_order,
            'notes' => $this->notes,
            'subject' => $this->whenLoaded('subject', fn () => [
                'id' => $this->subject->id,
                'code' => $this->subject->code,
                'name' => $this->subject->name,
                'category' => $this->subject->category,
                'credit' => $this->subject->credit,
            ]),
            'subject_group' => $this->whenLoaded('subjectGroup', fn () => [
                'id' => $this->subjectGroup->id,
                'code' => $this->subjectGroup->code,
                'name' => $this->subjectGroup->name,
            ]),
        ];
    }
}
