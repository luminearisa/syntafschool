<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Keikutsertaan satu calon pada satu tahap seleksi beserta nilainya.
 *
 * Endpoint peserta hanya dijaga permission seleksi (`ppdb.selection.manage`),
 * jadi identitas calon boleh ditampilkan penuh; portal orang tua memakai bentuk
 * ringkas milik sendiri (`forRegistration`) yang memuat baris calon itu saja.
 *
 * `composite` diisi `PpdbSelectionService` (skor gabungan berbobot dari seluruh
 * tahap pendaftaran ini) — bukan kolom database, jadi dibaca lewat atribut
 * sementara model.
 */
class PpdbSelectionParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stage_id' => $this->stage_id,
            'registration_id' => $this->registration_id,
            'status' => $this->status,
            'score' => $this->score === null ? null : (float) $this->score,
            'result' => $this->result,
            'attended_at' => $this->attended_at?->toIso8601String(),
            'notes' => $this->notes,
            'scored_by' => $this->scored_by,
            'scored_by_name' => $this->whenLoaded('scoredBy', fn () => $this->scoredBy?->name),
            // "Kapan" dinilai: tidak ada kolom `scored_at`, jadi nilai terakhir
            // kali baris ini diubah yang menjadi catatan waktunya.
            'scored_at' => $this->score === null ? null : $this->updated_at?->toIso8601String(),
            'composite' => $this->resource->getAttribute('composite'),
            'registration' => $this->whenLoaded('registration', fn () => $this->registration ? [
                'id' => $this->registration->id,
                'registration_number' => $this->registration->registration_number,
                'status' => $this->registration->status,
                'payment_status' => $this->registration->payment_status,
                'selection_result' => $this->registration->selection_result,
                'program_id' => $this->registration->program_id,
                'wave_id' => $this->registration->wave_id,
                'applicant_name' => $this->registration->applicant?->full_name,
                'applicant_nickname' => $this->registration->applicant?->nickname,
            ] : null),
            'stage' => $this->whenLoaded('stage', fn () => $this->stage ? [
                'id' => $this->stage->id,
                'name' => $this->stage->name,
                'type' => $this->stage->type,
                'passing_score' => $this->stage->passing_score === null ? null : (float) $this->stage->passing_score,
                'weight' => round((float) $this->stage->weight, 2),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
