<?php

namespace App\Http\Resources;

use App\Models\PpdbRegistrationHistory;
use App\Services\Ppdb\PpdbRegistrationQueryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Riwayat pendaftaran (perubahan status, unggah dokumen, revisi, verifikasi).
 *
 * @property PpdbRegistrationHistory $resource
 */
class PpdbRegistrationHistoryResource extends JsonResource
{
    /** Aksi non-status yang tercatat pada riwayat. */
    public const ACTION_LABELS = [
        'created' => 'Pendaftaran dibuat',
        'draft_saved' => 'Draft disimpan',
        'document.uploaded' => 'Dokumen diunggah',
        'document.uploaded_by_staff' => 'Dokumen diunggah panitia',
        'document.approved' => 'Dokumen disetujui',
        'document.rejected' => 'Dokumen ditolak',
        'document.under_review' => 'Dokumen sedang ditinjau',
        'revision.requested' => 'Revisi diminta',
        'payment.verified' => 'Pembayaran diverifikasi',
        'selection.scored' => 'Nilai seleksi dimasukkan',
    ];

    public static function actionLabel(string $action): string
    {
        return self::ACTION_LABELS[$action]
            ?? Str::of($action)->replace(['.', '_'], ' ')->title()->toString();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::row($this->resource);
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(PpdbRegistrationHistory $history): array
    {
        return [
            'id' => $history->id,
            'registration_id' => $history->registration_id,
            'action' => $history->action,
            'action_label' => self::actionLabel((string) $history->action),
            'from_status' => $history->from_status,
            'from_status_label' => $history->from_status === null ? null : PpdbRegistrationQueryService::statusLabel((string) $history->from_status),
            'to_status' => $history->to_status,
            'to_status_label' => $history->to_status === null ? null : PpdbRegistrationQueryService::statusLabel((string) $history->to_status),
            'note' => $history->note,
            'changed_by' => $history->changed_by,
            'changed_by_name' => $history->changedBy?->name,
            'created_at' => $history->created_at?->toIso8601String(),
        ];
    }
}
