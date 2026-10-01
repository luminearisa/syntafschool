<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Modules\Activity\Domain\Contracts\DocumentProviderInterface;
use Illuminate\Support\Facades\Storage;

/** Penyimpanan dokumen lokal (bukti prestasi, lampiran, sertifikat). */
class LocalDocumentProvider implements DocumentProviderInterface
{
    public function store(string $path, array $meta = []): array
    {
        return [
            'status' => Storage::disk($this->disk())->exists($path) ? 'EXISTS' : 'REFERENCED',
            'path' => $path,
            'url' => $this->url($path),
        ];
    }

    public function url(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        return Storage::disk($this->disk())->exists($path) ? Storage::disk($this->disk())->url($path) : null;
    }

    public function delete(string $path): bool
    {
        return Storage::disk($this->disk())->exists($path) && Storage::disk($this->disk())->delete($path);
    }

    protected function disk(): string
    {
        return (string) config('activity.documents.disk', 'public');
    }
}
