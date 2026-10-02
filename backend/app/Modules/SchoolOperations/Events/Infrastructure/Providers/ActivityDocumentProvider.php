<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Dokumen & media (modul Document).
 *
 * Meneruskan ke kontrak Document; berkas biner tidak disimpan di basis data modul Event (§72).
 */
class ActivityDocumentProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak Document; berkas biner tidak disimpan di basis data modul Event (§72).';

    protected const TARGET_CONTRACT = 'App\\Modules\\Activity\\Domain\\Contracts\\DocumentProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function store(string $path, array $meta = []): array
    {
        $value = $this->call('store', [$path, $meta]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function url(string $path): ?string
    {
        $value = $this->call('url', [$path]);

        return $value === null ? null : (string) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function delete(string $path): bool
    {
        $value = $this->call('delete', [$path]);

        return $value === null ? false : (bool) $value;
    }
}
