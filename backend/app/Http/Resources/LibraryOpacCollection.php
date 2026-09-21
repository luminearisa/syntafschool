<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Hasil OPAC.
 *
 * Baris sudah dibentuk oleh `OpacService::present()` — hanya data publik,
 * tanpa id basis data & tanpa informasi internal (§48). Karena itu koleksi
 * ini tidak memakai `LibraryBibliographyResource`, melainkan menerima baris
 * siap-pakai; meta pagination diambil standar dari paginator.
 */
class LibraryOpacCollection extends ResourceCollection
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(LengthAwarePaginator $paginator, array $rows)
    {
        parent::__construct($paginator);

        $this->collection = collect($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(Request $request): array
    {
        return $this->collection->all();
    }
}