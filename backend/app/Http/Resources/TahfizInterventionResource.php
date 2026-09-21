<?php

namespace App\Http\Resources;

use App\Models\TahfizIntervention;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property TahfizIntervention $resource
 */
class TahfizInterventionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'participant_id' => $this->participant_id,
            'enrollment_id' => $this->enrollment_id,
            'halaqah_id' => $this->halaqah_id,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'reason' => $this->reason,
            'action' => $this->action,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'due_date' => $this->due_date?->toDateString(),
            'completed_at' => $this->completed_at?->toDateString(),
            'result' => $this->result,
            'assigned_by' => $this->assigned_by,
            'is_open' => in_array($this->status, TahfizIntervention::STATUSES_OPEN, true),
            'is_overdue' => $this->due_date !== null
                && $this->completed_at === null
                && $this->due_date->isPast()
                && in_array($this->status, TahfizIntervention::STATUSES_OPEN, true),
            'participant' => $this->whenLoaded('participant', fn () => [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
            ]),
            'halaqah' => $this->whenLoaded('halaqah', fn () => [
                'id' => $this->halaqah->id,
                'name' => $this->halaqah->name,
            ]),
            'assigned_by_user' => $this->whenLoaded('assignedBy', fn () => [
                'id' => $this->assignedBy->id,
                'name' => $this->assignedBy->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    protected function typeLabel(): string
    {
        return match ($this->type) {
            TahfizIntervention::TYPE_TAMBAHAN_MUROJAAH => 'Tambahan Murojaah',
            TahfizIntervention::TYPE_PENGURANGAN_TARGET => 'Pengurangan Target',
            TahfizIntervention::TYPE_SESI_KHUSUS => 'Sesi Khusus',
            TahfizIntervention::TYPE_BIMBINGAN_INDIVIDUAL => 'Bimbingan Individual',
            TahfizIntervention::TYPE_REMEDIAL_HAFALAN => 'Remedial Hafalan',
            TahfizIntervention::TYPE_NAIK_LEVEL => 'Naik Level',
            TahfizIntervention::TYPE_TURUN_LEVEL => 'Turun Level',
            default => ucfirst((string) $this->type),
        };
    }

    protected function statusLabel(): string
    {
        return match ($this->status) {
            TahfizIntervention::STATUS_PROPOSED => 'Diajukan',
            TahfizIntervention::STATUS_IN_PROGRESS => 'Berlangsung',
            TahfizIntervention::STATUS_DONE => 'Selesai',
            TahfizIntervention::STATUS_CANCELLED => 'Dibatalkan',
            default => ucfirst((string) $this->status),
        };
    }
}
