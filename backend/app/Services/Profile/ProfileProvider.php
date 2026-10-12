<?php

namespace App\Services\Profile;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Gate;

/**
 * Penyedia satu jenis profil (siswa / wali / pegawai).
 *
 * Bagian ("section") dimuat malas per tab. Setiap bagian punya daftar izin
 * (cukup salah satu): data modul lain hanya tampil bagi yang berhak membaca
 * modul itu — izin melihat profil TIDAK otomatis membuka data keuangan,
 * kesehatan, konseling, atau penggajian.
 */
abstract class ProfileProvider
{
    abstract public function type(): string;

    /** @throws \Illuminate\Database\Eloquent\ModelNotFoundException */
    abstract public function find(int $id): object;

    /** @return array<string, mixed> */
    abstract public function header(object $entity): array;

    /**
     * Ringkasan angka di bagian atas halaman (sadar izin).
     *
     * @return list<array<string, mixed>>
     */
    abstract public function summary(object $entity, User $user): array;

    /** @return array<string, array{label: string, icon: string, permissions: list<string>, build: Closure}> */
    abstract protected function definitions(): array;

    /** @return list<array{key: string, label: string, icon: string}> */
    public function availableSections(User $user): array
    {
        $out = [];
        foreach ($this->definitions() as $key => $def) {
            if ($this->allowed($user, $def['permissions'])) {
                $out[] = ['key' => $key, 'label' => $def['label'], 'icon' => $def['icon']];
            }
        }

        return $out;
    }

    /** @return array{key: string, label: string, blocks: list<array<string, mixed>>}|null null = tidak dikenal; false-y izin ditangani pemanggil */
    public function section(object $entity, User $user, string $key): ?array
    {
        $defs = $this->definitions();
        if (! isset($defs[$key])) {
            return null;
        }
        abort_unless($this->allowed($user, $defs[$key]['permissions']), 403, 'Anda tidak berwenang melihat bagian ini.');

        return [
            'key' => $key,
            'label' => $defs[$key]['label'],
            'blocks' => Block::compact(($defs[$key]['build'])($entity, $user)),
        ];
    }

    /** @param list<string> $permissions kosong = cukup izin dasar profil */
    protected function allowed(User $user, array $permissions): bool
    {
        return $permissions === [] || Gate::forUser($user)->any($permissions);
    }

    /** @return array{0: string, 1: string} */
    protected static function initials(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return [mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1)), $name];
    }
}
