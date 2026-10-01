<?php

namespace App\Modules\Bk\Application\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Penentu unit sekolah (tenant) yang sedang aktif.
 *
 * ERP ini biasanya dipasang untuk satu sekolah, dan pada keadaan itu resolver
 * mengembalikan `null` — artinya tidak ada penyaringan tambahan dan perilaku
 * modul sama seperti sebelumnya. Bila instalasi melayani beberapa unit (yayasan),
 * resolver mengembalikan id unit yang sah dan global scope `BelongsToSchool`
 * menutup data antar-unit.
 *
 * Nilai disimpan per-request (bukan global statis) agar pekerja antrean tidak
 * mewarisi unit milik request lain — kesalahan yang berbahaya justru karena
 * tampak benar.
 */
class BkTenantResolver
{
    private ?int $resolved = null;

    private bool $resolvedOnce = false;

    /** Unit sekolah yang aktif, atau null bila instalasi satu unit. */
    public function id(): ?int
    {
        if ($this->resolvedOnce) {
            return $this->resolved;
        }

        $this->resolvedOnce = true;
        $this->resolved = $this->detect();

        return $this->resolved;
    }

    /** Paksa unit tertentu (dipakai perintah/pekerja yang berjalan lintas unit). */
    public function force(?int $schoolId): void
    {
        $this->resolved = $schoolId;
        $this->resolvedOnce = true;
    }

    /** Jalankan callback tanpa penyaringan unit, lalu kembalikan keadaan semula. */
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
        // Bila sekolah mengaktifkan penyaringan unit secara eksplisit...
        if (! config('bk.tenant.enabled', false)) {
            return null;
        }

        $user = Auth::user();

        if ($user instanceof User && $user->school_id !== null) {
            return (int) $user->school_id;
        }

        $configured = config('bk.tenant.default_school_id');

        return is_numeric($configured) ? (int) $configured : null;
    }
}
