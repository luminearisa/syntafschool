<?php

namespace App\Modules\LMS\Http\Resources;

use App\Modules\LMS\Models\LmsResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Isi resource, sudah dibersihkan (§13, §40).
 *
 * JS `sanitizeHtml` ditambahkan agar JS tidak membocorkan path penyimpanan:
 * unduhan selalu lewat endpoint ber-otorisasi `/lms/resources/{id}/download`.
 *
 * @mixin LmsResource
 */
class LmsResourceContentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return [];
        }

        return [
            'id' => (int) $this->id,
            'learning_item_id' => (int) $this->learning_item_id,
            'content' => $this->content,
            'file_name' => $this->file_name,
            'file_mime' => $this->file_mime,
            'file_size' => $this->file_size !== null ? (int) $this->file_size : null,
            'has_file' => $this->file_path !== null,
            'download_url' => $this->file_path !== null ? '/api/v1/lms/resources/'.$this->id.'/download' : null,
            'external_url' => $this->external_url,
            'open_in' => $this->open_in,
            'media_source' => $this->media_source,
            'video_provider' => $this->video_provider,
            'embed_url' => $this->embedUrl(),
            'duration_seconds' => $this->duration_seconds !== null ? (int) $this->duration_seconds : null,
            'settings' => $this->settings,
        ];
    }

    /** URL embed yang sudah dinormalisasi (YouTube/Vimeo/Drive) untuk `<iframe>`. */
    protected function embedUrl(): ?string
    {
        $url = $this->external_url;

        if ($url === null) {
            return null;
        }

        if ($this->video_provider === 'youtube') {
            $id = null;

            if (preg_match('~(?:youtu\.be/|v=|embed/)([A-Za-z0-9_-]{6,})~', $url, $matches) === 1) {
                $id = $matches[1];
            }

            return $id !== null ? 'https://www.youtube.com/embed/'.$id : null;
        }

        if ($this->video_provider === 'vimeo') {
            $id = null;

            if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $matches) === 1) {
                $id = $matches[1];
            }

            return $id !== null ? 'https://player.vimeo.com/video/'.$id : null;
        }

        if ($this->video_provider === 'google-drive') {
            if (preg_match('~/file/d/([A-Za-z0-9_-]+)~', $url, $matches) === 1) {
                return 'https://drive.google.com/file/d/'.$matches[1].'/preview';
            }

            return null;
        }

        return in_array($this->open_in, ['EMBED'], true) ? $url : null;
    }
}
