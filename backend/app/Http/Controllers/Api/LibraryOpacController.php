<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\LibraryOpacCollection;
use App\Services\Library\Opac\OpacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OPAC — katalog publik.
 *
 * Dapat diakses **tanpa login** (rute publik di `routes/api/library.php`).
 * Hanya record berstatus publik yang tampil, dan bentuk keluarannya sudah
 * disaring: tanpa id basis data, tanpa data peminjam, tanpa harga perolehan,
 * tanpa catatan internal (§48).
 */
class LibraryOpacController extends BaseApiController
{
    public function __construct(protected OpacService $opac)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->opac->search($request);

        $rows = collect($paginator->items())
            ->map(fn ($bibliography) => $this->opac->present($bibliography))
            ->values()
            ->all();

        return $this->successResponse(
            new LibraryOpacCollection($paginator, $rows),
            'Katalog publik berhasil diambil'
        );
    }

    /**
     * Detail publik berdasarkan slug (slug lama juga dapat ditelusuri).
     */
    public function show(string $slug): JsonResponse
    {
        $bibliography = $this->opac->findBySlug($slug);

        if ($bibliography === null) {
            return $this->errorResponse('Koleksi tidak ditemukan pada katalog publik.', 404);
        }

        return $this->successResponse(
            $this->opac->present($bibliography),
            'Detail koleksi berhasil diambil'
        );
    }

    /**
     * Ketersediaan eksemplar sebuah record (dihitung dari item).
     */
    public function availability(string $slug): JsonResponse
    {
        $bibliography = $this->opac->findBySlug($slug);

        if ($bibliography === null) {
            return $this->errorResponse('Koleksi tidak ditemukan pada katalog publik.', 404);
        }

        return $this->successResponse(
            $this->opac->availability($bibliography),
            'Ketersediaan koleksi berhasil diambil'
        );
    }
}