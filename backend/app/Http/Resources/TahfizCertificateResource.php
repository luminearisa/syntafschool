<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TahfizCertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'certificate_number' => $this->certificate_number,
            'participant_id' => $this->participant_id,
            'enrollment_id' => $this->enrollment_id,
            'program_id' => $this->program_id,
            'achievement_type' => $this->achievement_type,
            'achievement_type_label' => $this->achievementLabel(),
            'juz_count' => $this->juz_count,
            'title' => $this->title,
            'description' => $this->description,
            'issue_date' => $this->issue_date?->toDateString(),
            'exam_id' => $this->exam_id,
            'examiner_id' => $this->examiner_id,
            'issuer_id' => $this->issuer_id,
            'signature_name' => $this->signature_name,
            'qr_token' => $this->qr_token,
            'status' => $this->status,
            'status_label' => $this->status === 'issued' ? 'Berlaku' : 'Dicabut',
            'is_valid' => $this->status === 'issued',
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoke_reason' => $this->revoke_reason,
            'participant' => $this->whenLoaded('participant', fn () => [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
                'participant_type' => $this->participant->participant_type,
            ]),
            'program' => $this->whenLoaded('program', fn () => [
                'id' => $this->program->id,
                'name' => $this->program->name,
                'code' => $this->program->code,
            ]),
            'examiner' => $this->whenLoaded('examiner', fn () => [
                'id' => $this->examiner->id,
                'full_name' => $this->examiner->full_name,
            ]),
            'issuer' => $this->whenLoaded('issuer', fn () => [
                'id' => $this->issuer->id,
                'name' => $this->issuer->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    protected function achievementLabel(): string
    {
        return [
            'selesai_program' => 'Selesai Program',
            'hafal_1_juz' => 'Hafal 1 Juz',
            'hafal_5_juz' => 'Hafal 5 Juz',
            'hafal_10_juz' => 'Hafal 10 Juz',
            'hafal_30_juz' => 'Hafal 30 Juz',
            'lulus_tasmi' => 'Lulus Tasmi\'',
            'lulus_tahsin' => 'Lulus Tahsin',
        ][$this->achievement_type] ?? $this->achievement_type;
    }
}
