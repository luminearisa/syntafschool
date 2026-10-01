<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Dasar controller modul Asset & Sarpras.
 *
 * Bentuk jawaban diseragamkan (data + pesan + meta halaman) supaya layar tidak
 * perlu menebak-nebak, dan paginasi tidak pernah lupa membawa totalnya.
 */
abstract class AssetControllerBase extends Controller
{
    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if($user === null, 401, 'Permintaan ini memerlukan otentikasi.');

        return $user;
    }

    protected function ok(mixed $data, string $message = 'OK', array $meta = []): JsonResponse
    {
        $payload = ['message' => $message, 'data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload);
    }

    protected function created(mixed $data, string $message = 'Data dibuat.'): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => $data], 201);
    }

    /** Jawaban paginasi dengan meta yang konsisten. */
    protected function paginated(LengthAwarePaginator $paginator, string $message = 'OK'): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    protected function filters(Request $request, array $allowed): array
    {
        $filters = $request->only($allowed);

        $filters['per_page'] = min(100, max(1, (int) $request->input('per_page', 25)));
        $filters['sort'] = $request->input('sort');
        $filters['direction'] = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        return $filters;
    }
}
