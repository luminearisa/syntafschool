<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizAttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'halaqah_id' => $this->halaqah_id,
            'participant_id' => $this->participant_id,
            'date' => $this->date?->toDateString(),
            'status' => $this->status,
            'code' => \App\Models\TahfizAttendance::CODES[$this->status] ?? null,
            'is_present' => in_array($this->status, \App\Models\TahfizAttendance::PRESENT_STATUSES, true),
            'arrival_time' => $this->arrival_time?->format('H:i'),
            'permission_reason' => $this->permission_reason,
            'notes' => $this->notes,
            'recorded_by' => $this->recorded_by,

            'session' => $this->whenLoaded('session', fn () => $this->session ? [
                'id' => $this->session->id,
                'activity_type' => $this->session->activity_type,
                'date' => $this->session->date?->toDateString(),
                'topic' => $this->session->topic,
            ] : null),

            'halaqah' => $this->whenLoaded('halaqah', fn () => $this->halaqah ? [
                'id' => $this->halaqah->id,
                'name' => $this->halaqah->name,
                'code' => $this->halaqah->code,
            ] : null),

            'participant' => $this->whenLoaded('participant', fn () => $this->participant ? [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
                'participant_type' => $this->participant->participant_type,
            ] : null),

            'recorder' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy ? [
                'id' => $this->recordedBy->id,
                'name' => $this->recordedBy->name,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
