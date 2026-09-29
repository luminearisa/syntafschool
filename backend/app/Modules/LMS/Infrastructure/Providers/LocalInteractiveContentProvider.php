<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Modules\LMS\Domain\Contracts\InteractiveContentProviderInterface;
use App\Modules\LMS\Domain\Enums\InteractiveContentType;
use ZipArchive;

/**
 * Konten interaktif bawaan LMS (§13).
 *
 * Tipe yang didukung sama dengan `InteractiveContentType` Phase 4. Paket
 * `.h5p` hanya divalidasi strukturnya (arsip + `h5p.json`); pemetaan penuh
 * dapat ditambahkan sebagai provider lain tanpa mengubah
 * `InteractiveContentService`.
 */
class LocalInteractiveContentProvider implements InteractiveContentProviderInterface
{
    public function key(): string
    {
        return 'local';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function supportedTypes(): array
    {
        return InteractiveContentType::values();
    }

    public function supportsPackages(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function validatePackage(string $absolutePath, ?string $originalName = null): array
    {
        $errors = [];
        $warnings = [];
        $type = null;
        $title = null;
        $version = null;
        $payload = [];

        if (! is_file($absolutePath)) {
            return $this->invalid('Berkas paket tidak ditemukan.');
        }

        if (! $this->supportsPackages()) {
            return $this->invalid('Ekstensi zip PHP tidak tersedia pada server ini.');
        }

        $zip = new ZipArchive;

        if ($zip->open($absolutePath) !== true) {
            return $this->invalid('Arsip tidak dapat dibuka atau rusak.');
        }

        try {
            $manifestRaw = $zip->getFromName('h5p.json') ?: $zip->getFromName('imsmanifest.xml');

            if ($manifestRaw === false || $manifestRaw === '') {
                $errors[] = 'Paket tidak memuat h5p.json atau imsmanifest.xml.';
            } elseif (str_contains($manifestRaw, '<manifest')) {
                // SCORM ditangani `ScormPackageService`; di sini hanya dicatat.
                $type = 'SCORM';
                $warnings[] = 'Paket ini berformat SCORM — impor lewat menu SCORM agar pelacakannya benar.';
            } else {
                $manifest = json_decode($manifestRaw, true);

                if (! is_array($manifest)) {
                    $errors[] = 'Manifest h5p.json tidak dapat dibaca.';
                } else {
                    $title = isset($manifest['title']) ? (string) $manifest['title'] : null;
                    $version = isset($manifest['language']) ? (string) ($manifest['language'] ?? '') : null;
                    $type = 'H5P';
                    $payload = [
                        'title' => $title,
                        'main_library' => $manifest['mainLibrary'] ?? null,
                        'libraries' => is_array($manifest['preloadedDependencies'] ?? null) ? $manifest['preloadedDependencies'] : [],
                        'package_name' => $originalName,
                    ];
                }
            }
        } finally {
            $zip->close();
        }

        return [
            'valid' => $errors === [],
            'type' => $type,
            'title' => $title,
            'version' => $version,
            'errors' => $errors,
            'warnings' => $warnings,
            'payload' => $payload,
        ];
    }

    public function mapPackage(array $package): array
    {
        /** @var array<string, mixed> $manifestPayload */
        $manifestPayload = (array) ($package['payload'] ?? []);

        return [
            'type' => (string) ($package['type'] ?? 'H5P'),
            'title' => (string) ($package['title'] ?? 'Konten interaktif'),
            'version' => (string) ($package['version'] ?? '1.0'),
            'payload' => [
                'source' => 'package',
                'package_type' => $package['type'] ?? null,
                'main_library' => $manifestPayload['main_library'] ?? null,
                'libraries' => $manifestPayload['libraries'] ?? [],
                'slides' => [],
                'items' => [],
            ],
            'metadata' => [
                'imported_from' => $manifestPayload['package_name'] ?? null,
                'imported_at' => now()->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function invalid(string $message): array
    {
        return [
            'valid' => false,
            'type' => null,
            'title' => null,
            'version' => null,
            'errors' => [$message],
            'warnings' => [],
            'payload' => [],
        ];
    }
}
