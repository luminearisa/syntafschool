<?php

namespace App\Http\Resources;

use App\Models\TahfizNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property TahfizNotification $resource
 */
class TahfizNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'participant_id' => $this->participant_id,
            'audience' => $this->audience,
            'audience_label' => $this->audienceLabel(),
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'read_at' => $this->read_at?->toIso8601String(),
            'is_read' => $this->read_at !== null,
            'participant' => $this->whenLoaded('participant', fn () => [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    protected function audienceLabel(): string
    {
        return match ($this->audience) {
            TahfizNotification::AUDIENCE_PARTICIPANT => 'Peserta',
            TahfizNotification::AUDIENCE_INSTRUCTOR => 'Guru/Instruktur',
            TahfizNotification::AUDIENCE_MUSYRIF => 'Musyrif',
            TahfizNotification::AUDIENCE_PARENT => 'Wali',
            TahfizNotification::AUDIENCE_ADMIN => 'Admin',
            default => ucfirst((string) $this->audience),
        };
    }
}
