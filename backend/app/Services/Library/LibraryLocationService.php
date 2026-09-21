<?php

namespace App\Services\Library;

use App\Models\Library;
use App\Models\LibraryLocation;
use Illuminate\Validation\ValidationException;

/**
 * Hierarki lokasi perpustakaan: gedung → lantai → ruang.
 *
 * Rak buku bukan bagian Step 1 (ditangani modul katalog/inventaris).
 */
class LibraryLocationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Library $library, array $data): LibraryLocation
    {
        $this->assertParentBelongsToLibrary($library, $data['parent_id'] ?? null);

        return LibraryLocation::create($data + ['library_id' => $library->id]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LibraryLocation $location, array $data): LibraryLocation
    {
        $this->assertParentBelongsToLibrary($location->library, $data['parent_id'] ?? null);

        if (array_key_exists('parent_id', $data)) {
            $this->assertNoCycle($location, $data['parent_id'] !== null ? (int) $data['parent_id'] : null);
        }

        $location->update($data);

        return $location;
    }

    /**
     * Pindahkan lokasi ke induk lain (atau ke akar).
     */
    public function move(LibraryLocation $location, ?int $parentId): LibraryLocation
    {
        $this->assertParentBelongsToLibrary($location->library, $parentId);
        $this->assertNoCycle($location, $parentId);

        $location->update(['parent_id' => $parentId]);

        return $location;
    }

    /**
     * Hapus lokasi — ditolak bila masih memiliki sub-lokasi.
     */
    public function delete(LibraryLocation $location): void
    {
        if ($location->children()->exists()) {
            throw ValidationException::withMessages([
                'location' => 'Lokasi tidak dapat dihapus karena masih memiliki sub-lokasi. Pindahkan atau hapus sub-lokasi terlebih dahulu.',
            ]);
        }

        $location->delete();
    }

    /**
     * Induk harus berada pada perpustakaan yang sama.
     */
    protected function assertParentBelongsToLibrary(Library $library, mixed $parentId): void
    {
        if ($parentId === null || $parentId === '') {
            return;
        }

        $exists = LibraryLocation::query()
            ->whereKey($parentId)
            ->where('library_id', $library->id)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'parent_id' => 'Induk lokasi tidak ditemukan pada perpustakaan ini.',
            ]);
        }
    }

    /**
     * Cegah siklus: lokasi tidak boleh dipindah ke bawah keturunannya sendiri.
     */
    protected function assertNoCycle(LibraryLocation $location, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === $location->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'Lokasi tidak dapat menjadi induk bagi dirinya sendiri.',
            ]);
        }

        if (in_array($parentId, $location->descendantIds(), true)) {
            throw ValidationException::withMessages([
                'parent_id' => 'Lokasi tidak dapat dipindah ke bawah keturunannya sendiri.',
            ]);
        }
    }
}