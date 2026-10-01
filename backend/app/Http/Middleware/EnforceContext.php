<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menegakkan konteks token mobile di sisi server.
 *
 *  - Token web/admin (ability `*`) tidak dibatasi.
 *  - `ctx:pending` (hasil login mobile, sebelum memilih peran) hanya boleh
 *    memanggil `auth/*`.
 *  - `ctx:guardian` / `ctx:student` hanya boleh memanggil `auth/*` dan
 *    `mobile/*`. Permission berbasis role (mis. `student:view` milik role
 *    wali-murid) TIDAK berlaku di sini, sehingga wali tak bisa membaca data
 *    siswa lain lewat endpoint admin.
 *  - `ctx:employee` memakai permission role seperti biasa.
 */
class EnforceContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');
        $token = $user?->currentAccessToken();

        if (! $token || ! method_exists($token, 'can') || $token->can('*')) {
            return $next($request);
        }

        $path = $request->path();
        $isAuth = (bool) preg_match('#^api/v1/auth(/|$)#', $path);

        if ($token->can('ctx:pending') && ! $isAuth) {
            return $this->deny('Pilih peran terlebih dahulu.');
        }

        if (($token->can('ctx:guardian') || $token->can('ctx:student'))
            && ! $isAuth
            && ! preg_match('#^api/v1/mobile(/|$)#', $path)) {
            return $this->deny('Akses ini tidak tersedia pada peran Anda saat ini.');
        }

        return $next($request);
    }

    private function deny(string $message): Response
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }
}
