<?php

namespace App\Modules\Asset\Application\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Penentu unit sekolah (tenant) yang aktif untuk modul Asset & Sarpras.
 *
 * Mengikuti pola yang sudah dipakai modul BK: instalasi satu sekolah berjalan
 * tanpa penyaringan (`null`), sedangkan instalasi yayasan menyaring setiap
 * kueri aset/fasilitas menurut unitnya. Nilai disimpan per-permintaan agar
 * pekerja antrean tidak mewarisi unit milik permintaan lain — kesalahan yang
 * berbahaya justru karena tampak benar.
 */
class AssetTenantResolver
{
    private ?int $resolved = null;

    private bool $resolvedOnce = false;

    public function id(): ?int
    {
        if ($this->resolvedOnce) {
            return $this->resolved;
        }

        $this->resolvedOnce = true;
        $this->resolved = $this->detect();

        return $this->resolved;
    }

    /** Paksa unit tertentu (perintah/pekerja lintas unit, dan pengujian). */
    public function force(?int $schoolId): void
    {
        $this->resolved = $schoolId;
        $this->resolvedOnce = true;
    }

    /** Jalankan callback TANPA penyaringan unit, lalu kembalikan keadaan semula. */
    public function withoutScope(callable $callback): mixed
    {
        $previousId = $this->resolved;
        $previousOnce = $this->resolvedOnce;

        $this->force(null);

        try {
            return $callback();
        } finally {
            $this->resolved = $previousId;
            $this->resolvedOnce = $previousOnce;
        }
    }

    protected function detect(): ?int
    {
        if (! config('asset.tenant.enabled', false)) {
            return null;
        }

        $user = Auth::user();

        if ($user instanceof User && $user->school_id !== null) {
            return (int) $user->school_id;
        }

        $configured = config('asset.tenant.default_school_id');

        return is_numeric($configured) ? (int) $configured : null;
    }
}
