<?php

namespace App\Modules\LMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pemeriksaan permission yang bekerja pada kedua mode (§35).
 *
 * Integrated Mode: permission berasal dari Spatie (role ERP).
 * Standalone Mode: permission berasal dari peta peran `lms_users`.
 *
 * Beberapa permission dapat disebut sekaligus (dipisah koma) dan dianggap
 * "salah satu cukup" — pola yang dipakai halaman yang boleh dibuka guru maupun
 * admin, mis. `lms.permission:lms.course.update,lms.manage`.
 */
class EnsureLmsPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $actor = ResolveLmsActor::actor($request);

        if ($actor === null) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum terautentikasi pada LMS.',
            ], 401);
        }

        $wanted = [];
        foreach ($permissions as $group) {
            foreach (explode(',', $group) as $permission) {
                $permission = trim($permission);

                if ($permission !== '') {
                    $wanted[] = $permission;
                }
            }
        }

        if ($wanted === [] || $actor->canAny($wanted)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Anda tidak memiliki izin untuk tindakan ini pada LMS.',
            'errors' => ['permission' => $wanted],
        ], 403);
    }
}
