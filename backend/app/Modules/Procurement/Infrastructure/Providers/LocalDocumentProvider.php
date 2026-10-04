<?php

namespace App\Modules\Procurement\Infrastructure\Providers;

use App\Modules\Procurement\Domain\Contracts\DocumentProviderInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Implementasi bawaan dokumen: menyimpan berkas di disk `local` dan
 * menghasilkan berkas teks/HTML sederhana untuk dokumen pengadaan.
 *
 * Rendering PDF sebenarnya diserahkan ke modul Document; default ini menjaga
 * modul tetap berfungsi tanpa ketergantungan pustaka tambahan.
 */
class LocalDocumentProvider implements DocumentProviderInterface
{
    public function generate(array $payload): array
    {
        $type = (string) ($payload['type'] ?? 'document');
        $format = (string) ($payload['format'] ?? 'html');
        $path = 'procurement/'.now()->format('Y/m').'/'.Str::slug($type).'-'.Str::uuid()->toString().'.'.$format;

        $body = $payload['html'] ?? $this->renderText($type, (array) ($payload['data'] ?? []));
        Storage::disk('local')->put($path, $body);

        return [
            'path' => $path,
            'url' => null,
            'mime' => $format === 'pdf' ? 'application/pdf' : 'text/html',
        ];
    }

    public function store(array $payload): array
    {
        /** @var UploadedFile|null $file */
        $file = $payload['file'] ?? null;

        if ($file === null) {
            return ['path' => (string) ($payload['path'] ?? ''), 'url' => null];
        }

        $directory = 'procurement/'.now()->format('Y/m');
        $path = $file->store($directory, 'local');

        return ['path' => (string) $path, 'url' => null];
    }

    /** @param array<string, mixed> $data */
    protected function renderText(string $type, array $data): string
    {
        $lines = ['Dokumen: '.strtoupper($type), 'Dibuat: '.now()->toDateTimeString(), ''];

        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $lines[] = $key.': '.$value;
            }
        }

        return implode("\n", $lines);
    }
}
