<?php

namespace App\Http\Middleware;

use App\Services\Mobile\ContextService;
use App\Support\ContextPermissions;
use App\Support\ContextState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menegakkan peran aktif token mobile di sisi server.
 *
 *  - Token web/admin (ability `*`) tidak berubah sama sekali.
 *  - `ctx:pending` (login mobile sebelum memilih peran) hanya boleh `auth/*`.
 *  - `ctx:guardian` / `ctx:student`: hanya path pada `config/mobile.php`.
 *  - Semua token `ctx:*`: peran aktif dicatat di `ContextState`, sehingga
 *    permission/role dihitung per peran (lihat `User`) dan modul yang membaca
 *    `users.userable` melihat identitas peran aktif.
 */
class EnforceContext
{
    public function __construct(private readonly ContextService $contexts) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Selalu mulai bersih: state statis tidak boleh bocor antar request.
        ContextState::clear();
        ContextPermissions::flush();

        $user = $request->user('sanctum');
        $token = $user?->currentAccessToken();

        if (! $token || ! method_exists($token, 'can') || $token->can('*')) {
            return $next($request);
        }

        $path = preg_replace('#^api/v1/#', '', $request->path());

        if ($token->can('ctx:pending')) {
            return Str::is('auth/*', $path) ? $next($request) : $this->deny('Pilih peran terlebih dahulu.');
        }

        foreach (ContextService::TYPES as $type) {
            if (! $token->can(ContextService::ability($type))) {
                continue;
            }

            // Token menyatakan peran, tetapi tautan identitasnya bisa saja sudah
            // dicabut admin sejak token terbit — periksa ulang setiap request.
            $entityId = $this->contexts->identityIds($user, $type)[0] ?? null;
            if ($entityId === null) {
                return $this->deny('Peran ini sudah tidak tertaut pada akun Anda. Silakan login ulang.', 401);
            }

            ContextState::set((int) $user->getKey(), $type, $entityId);
            ContextState::apply($user);

            if ($type !== ContextService::EMPLOYEE
                && ! Str::is(config('mobile.allow.'.$type, ['auth/*', 'mobile/*']), $path)) {
                return $this->deny('Akses ini tidak tersedia pada peran Anda saat ini.');
            }

            return $next($request);
        }

        return $this->deny('Token tidak memiliki peran yang valid.', 401);
    }

    private function deny(string $message, int $status = 403): Response
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
