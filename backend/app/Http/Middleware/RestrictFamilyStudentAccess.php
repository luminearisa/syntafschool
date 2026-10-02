<?php

namespace App\Http\Middleware;

use App\Services\Mobile\ContextService;
use App\Support\ContextPermissions;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pagar kepemilikan untuk endpoint "per siswa" yang dipakai portal wali/siswa
 * (`students/{student}/scores`, `.../assignments`, `attendance-recap/student/…`).
 *
 * Endpoint itu hanya dijaga permission `*.view`, yang juga dimiliki role
 * `wali-murid` dan `siswa` — tanpa pagar ini setiap wali dapat membaca nilai
 * siswa mana pun hanya dengan mengganti id di URL.
 *
 * Aturan: akun yang SELURUH rolenya adalah wali-murid/siswa hanya boleh
 * membuka dirinya sendiri atau anak-anaknya (selain itu 404, tidak 403, agar
 * keberadaan id tidak terkonfirmasi). Akun dengan role staf lain tidak
 * dibatasi — perilaku lama untuk guru, wali kelas, kepala sekolah, dll.
 */
class RestrictFamilyStudentAccess
{
    public function __construct(private readonly ContextService $contexts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $roles = $user->getRoleNames();
        $familyOnly = $roles->isNotEmpty()
            && $roles->diff(array_values(ContextPermissions::FAMILY_ROLES))->isEmpty();

        if (! $familyOnly) {
            return $next($request);
        }

        $param = $request->route('student');
        $studentId = $param instanceof Model ? (int) $param->getKey() : (int) $param;

        $allowed = $this->contexts->childStudentIds($user);
        if (($own = $this->contexts->studentId($user)) !== null) {
            $allowed[] = $own;
        }

        if (! in_array($studentId, $allowed, true)) {
            return response()->json(['success' => false, 'message' => 'Data siswa tidak ditemukan pada akun Anda.'], 404);
        }

        return $next($request);
    }
}
