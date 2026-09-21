<?php

namespace App\Http\Resources;

use App\Models\PpdbRegistrationAppeal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Sanggahan orang tua atas hasil seleksi (masa sanggah).
 *
 * Dua pembaca dengan hak berbeda:
 * - panitia (`ppdb.registration.view`) — butuh identitas pendaftar, calon, dan
 *   siapa yang memeriksa;
 * - orang tua di portal — hanya sanggahan miliknya sendiri, tanpa identitas
 *   panitia (yang keluar hanya status & catatan keputusan).
 *
 * Bentuk datanya sengaja sama agar UI tidak perlu bercabang; yang dibedakan
 * hanya isi kolom sensitif lewat `$staff`.
 */
class PpdbAppealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->hasAnyPermission(['ppdb.registration.view', 'ppdb.registration.verify']) ?? false;

        return [
            'id' => $this->id,
            'registration_id' => $this->registration_id,
            'period_id' => $this->period_id,
            'parent_account_id' => $this->parent_account_id,
            'reason' => $this->reason,
            'attachment_path' => $this->when($staff, $this->attachment_path),
            'attachment_url' => $this->attachmentUrl(),
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'is_decided' => $this->isDecided(),
            'review_note' => $this->review_note,
            'reviewed_by' => $this->when($staff, $this->reviewed_by),
            'reviewed_by_name' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->name),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'registration' => $this->whenLoaded('registration', fn () => $this->registration ? [
                'id' => $this->registration->id,
                'registration_number' => $this->registration->registration_number,
                'status' => $this->registration->status,
                'selection_result' => $this->registration->selection_result,
                'program_id' => $this->registration->program_id,
                'wave_id' => $this->registration->wave_id,
                'applicant_name' => $this->registration->applicant?->full_name,
                'parent_name' => $this->when($staff, $this->registration->parentAccount?->user?->name),
            ] : null),
            'period' => $this->whenLoaded('period', fn () => $this->period ? [
                'id' => $this->period->id,
                'code' => $this->period->code,
                'name' => $this->period->name,
                'appeal_start' => $this->period->appeal_start?->toDateString(),
                'appeal_end' => $this->period->appeal_end?->toDateString(),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function statusLabel(): string
    {
        return match ($this->status) {
            PpdbRegistrationAppeal::STATUS_ACCEPTED => 'Diterima — keputusan dibuka kembali untuk panitia',
            PpdbRegistrationAppeal::STATUS_REJECTED => 'Ditolak',
            default => 'Menunggu pemeriksaan',
        };
    }

    /** URL lampiran pada disk public; null bila orang tua tidak melampirkan apa pun. */
    private function attachmentUrl(): ?string
    {
        $path = $this->attachment_path;

        return filled($path) ? Storage::disk('public')->url((string) $path) : null;
    }
}
