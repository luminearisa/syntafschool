<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\DocumentGatewayInterface;
use Illuminate\Support\Facades\Storage;

class LocalDocumentGateway implements DocumentGatewayInterface
{
    use GatewaySupport;

    public function store(string $path, array $meta = []): array
    {
        // Penyimpanan nyata ditangani unggahan HTTP; di sini metadata dicatat rapi.
        return [
            'path' => $path,
            'url' => $this->url($path),
            'meta' => $meta,
            'stored' => true,
        ];
    }

    public function url(string $path): ?string
    {
        try {
            return Storage::disk(config('filesystems.default'))->exists($path)
                ? Storage::disk(config('filesystems.default'))->url($path)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function available(): bool
    {
        return true;
    }
}
