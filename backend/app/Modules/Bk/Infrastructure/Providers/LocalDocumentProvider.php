<?php

namespace App\Modules\Bk\Infrastructure\Providers;

use App\Modules\Bk\Domain\Contracts\DocumentProviderInterface;
use Illuminate\Support\Facades\Storage;

/**
 * Penyimpanan berkas dokumen kasus di disk lokal (privat).
 *
 * Berkas **tidak** pernah disajikan langsung: yang dibagikan hanya rujukan
 * internal, dan pengunduhan selalu melewati pemeriksaan kerahasiaan BK.
 */
class LocalDocumentProvider implements DocumentProviderInterface
{
    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk((string) config('bk.documents.disk', 'local'));
    }

    public function store(string $contents, string $filename, array $meta = []): array
    {
        $reference = trim((string) config('bk.documents.path', 'bk/documents'), '/').'/'.date('Y/m').'/'.uniqid('doc_', true).'_'.$filename;

        $this->disk()->put($reference, $contents);

        return [
            'provider' => 'local',
            'reference' => $reference,
            'mime' => $meta['mime'] ?? null,
            'size' => strlen($contents),
            'checksum' => hash('sha256', $contents),
        ];
    }

    public function exists(string $reference): bool
    {
        return $this->disk()->exists($reference);
    }

    public function contents(string $reference): ?string
    {
        return $this->disk()->exists($reference) ? $this->disk()->get($reference) : null;
    }

    public function delete(string $reference): bool
    {
        return $this->disk()->delete($reference);
    }
}
