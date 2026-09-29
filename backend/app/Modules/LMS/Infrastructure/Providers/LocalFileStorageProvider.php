<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Modules\LMS\Domain\Contracts\FileStorageProviderInterface;
use App\Modules\LMS\Domain\Enums\FileVisibility;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Penyimpanan berkas LMS (§28).
 *
 * Visibilitas menentukan disk dan awalan direktori sehingga berkas pribadi
 * tidak pernah berada di disk publik — dan setiap nama berkas disanitasi
 * supaya path traversal (`../`) tidak mungkin terjadi.
 */
class LocalFileStorageProvider implements FileStorageProviderInterface
{
    public function key(): string
    {
        return 'local';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function disk(): string
    {
        return (string) config('lms.files.disk', 'local');
    }

    public function store(UploadedFile $file, string $directory, string $visibility = 'PRIVATE', ?string $name = null): array
    {
        $visibility = $this->normalizeVisibility($visibility);
        $target = $this->disk();
        $path = $this->pathFor($directory, $name ?? $file->getClientOriginalName(), $visibility);

        Storage::disk($target)->put($path, $file->get(), ['visibility' => $this->laravelVisibility($visibility)]);

        return [
            'path' => $path,
            'disk' => $target,
            'visibility' => $visibility,
            'size' => (int) $file->getSize(),
            'mime' => $file->getClientMimeType(),
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: '',
        ];
    }

    public function put(string $directory, string $name, string $contents, string $visibility = 'PRIVATE'): array
    {
        $visibility = $this->normalizeVisibility($visibility);
        $target = $this->disk();
        $path = $this->pathFor($directory, $name, $visibility);

        Storage::disk($target)->put($path, $contents, ['visibility' => $this->laravelVisibility($visibility)]);

        return [
            'path' => $path,
            'disk' => $target,
            'visibility' => $visibility,
            'size' => strlen($contents),
            'mime' => null,
            'checksum' => hash('sha256', $contents),
        ];
    }

    public function exists(string $path): bool
    {
        return Storage::disk($this->disk())->exists($path);
    }

    public function delete(string $path): bool
    {
        try {
            return Storage::disk($this->disk())->delete($path);
        } catch (Throwable) {
            return false;
        }
    }

    public function temporaryLifetimeHours(): int
    {
        return (int) config('lms.files.temporary_lifetime_hours', 24);
    }

    /**
     * Nama berkas: hanya huruf/angka/dash/titik. Tanpa sanitasi ini, unggahan
     * bernama `../../.env` bisa menimpa berkas di luar direktori LMS.
     */
    private function pathFor(string $directory, string $name, string $visibility): string
    {
        $base = trim($directory, '/');
        $safe = Str::of(pathinfo($name, PATHINFO_FILENAME))
            ->replaceMatches('/[^A-Za-z0-9\-_]+/', '-')
            ->trim('-')
            ->limit(80, '')
            ->value();

        $safe = $safe === '' ? 'berkas' : $safe;
        $extension = Str::of((string) pathinfo($name, PATHINFO_EXTENSION))
            ->replaceMatches('/[^A-Za-z0-9]+/', '')
            ->limit(12, '')
            ->lower()
            ->value();

        $filename = $extension === '' ? $safe : $safe.'.'.$extension;

        return $base.'/'.$this->visibilityPrefix($visibility).'/'.Str::ulid()->toBase32().'-'.$filename;
    }

    private function visibilityPrefix(string $visibility): string
    {
        return FileVisibility::tryFrom($visibility)?->storagePrefix() ?? 'private';
    }

    private function laravelVisibility(string $visibility): string
    {
        return FileVisibility::tryFrom($visibility)?->isPublic() === true ? 'public' : 'private';
    }

    private function normalizeVisibility(string $visibility): string
    {
        $value = strtoupper(trim($visibility));

        return FileVisibility::tryFrom($value) !== null ? $value : FileVisibility::PRIVATE->value;
    }
}
