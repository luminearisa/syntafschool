<?php

namespace App\Http\Resources;

use App\Models\PpdbApplicantDocument;
use App\Services\Ppdb\PpdbRegistrationQueryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Dokumen calon yang sudah diunggah — BER-VERSI.
 *
 * Berkas disimpan di disk `public` dengan path RELATIF pada `file_path`
 * (`ppdb/documents/{registration}/{requirement}/v{version}/{nama-berkas}`);
 * URL lengkap selalu dibangun lewat `Storage::disk('public')->url()` supaya
 * perubahan domain/CDN tidak ikut tersimpan di basis data.
 *
 * @property PpdbApplicantDocument $resource
 */
class PpdbApplicantDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = self::row($this->resource);

        if ($this->resource->relationLoaded('registration')) {
            $registration = $this->resource->registration;
            $data['registration_number'] = $registration?->registration_number;
        }

        if ($this->resource->relationLoaded('applicant')) {
            $data['applicant_name'] = $this->resource->applicant?->full_name;
        }

        return $data;
    }

    /**
     * Bentuk seragam satu dokumen (dipakai juga oleh `PpdbDocumentService`).
     *
     * @return array<string, mixed>
     */
    public static function row(PpdbApplicantDocument $document): array
    {
        $path = (string) $document->file_path;

        return [
            'id' => $document->id,
            'registration_id' => $document->registration_id,
            'applicant_id' => $document->applicant_id,
            'requirement_id' => $document->requirement_id,
            'requirement_name' => $document->requirement_name,
            'version' => (int) $document->version,
            'is_active' => (bool) $document->is_active,
            'status' => $document->status,
            'status_label' => PpdbRegistrationQueryService::documentItemLabel((string) $document->status),
            'original_filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'file_size' => (int) $document->file_size,
            'file_size_human' => self::humanSize((int) $document->file_size),
            'file_path' => $path === '' ? null : $path,
            'url' => self::fileUrl($path),
            'review_note' => $document->review_note,
            'reviewed_at' => $document->reviewed_at?->toIso8601String(),
            'reviewed_by' => $document->reviewed_by,
            'uploaded_at' => $document->uploaded_at?->toIso8601String(),
            'uploaded_by' => $document->uploaded_by,
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }

    public static function fileUrl(string $path): ?string
    {
        return $path === '' ? null : Storage::disk('public')->url($path);
    }

    public static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }

        return round($size, $index === 0 ? 0 : 1).' '.$units[$index];
    }
}
