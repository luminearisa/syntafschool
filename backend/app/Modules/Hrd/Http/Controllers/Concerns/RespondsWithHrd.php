<?php

namespace App\Modules\Hrd\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Bentuk respons modul HRD (§64).
 *
 * Satu amplop untuk seluruh endpoint: `data` selalu berisi muatan, `meta` memuat
 * informasi halaman bila ada. Frontend karena itu tidak perlu menebak bentuk
 * respons per endpoint — dan tidak ada model basis data yang terkirim mentah.
 */
trait RespondsWithHrd
{
    /** @param mixed $data */
    protected function ok(mixed $data, int $status = 200): JsonResponse
    {
        if ($data instanceof LengthAwarePaginator) {
            return response()->json([
                'data' => $data->items(),
                'meta' => [
                    'current_page' => $data->currentPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                    'last_page' => $data->lastPage(),
                ],
            ], $status);
        }

        if ($data instanceof JsonResource) {
            return $data->response()->setStatusCode($status);
        }

        if ($data instanceof Collection) {
            $data = $data->values();
        }

        return response()->json(['data' => $data], $status);
    }

    /** @param array<string, mixed> $meta */
    protected function okWithMeta(mixed $data, array $meta, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => $meta], $status);
    }

    protected function created(mixed $data): JsonResponse
    {
        return $this->ok($data, 201);
    }
}
