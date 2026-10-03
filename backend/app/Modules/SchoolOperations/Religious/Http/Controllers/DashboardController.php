<?php

namespace App\Modules\SchoolOperations\Religious\Http\Controllers;

use App\Modules\SchoolOperations\Religious\Application\Services\ReligiousDashboardService;
use App\Services\Mobile\ContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Dashboard modul, layar masjid, mobile, siswa, wali, dan asrama (§65-§70, §89). */
class DashboardController extends Controller
{
    public function __construct(private readonly ReligiousDashboardService $dashboard) {}

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('religious.view') || abort(403);

        return response()->json(['data' => $this->dashboard->overview(
            (string) $request->input('date', now()->toDateString())
        )]);
    }

    /** Layar digital masjid (§65, §89). */
    public function display(Request $request, int $mosqueId): JsonResponse
    {
        $this->authorizeDisplayAccess($request);

        return response()->json(['data' => $this->dashboard->display($mosqueId, $request->input('date'))]);
    }

    /** Mode mobile (§66). */
    public function mobile(Request $request): JsonResponse
    {
        $this->authorizeDisplayAccess($request);

        return response()->json(['data' => $this->dashboard->mobile(
            $request->integer('mosque_id') ?: null, $request->input('date')
        )]);
    }

    /** Dashboard siswa (§67). */
    public function student(Request $request, int $studentId): JsonResponse
    {
        $user = $request->user();
        $own = (int) (app(ContextService::class)->studentId($user) ?? $user->student_id ?? 0);

        if (! $user->can('religious.attendance.view')
            && (! $user->can('religious.portal.student') || $own !== $studentId)) {
            abort(403, 'Portal siswa hanya dapat mengakses datanya sendiri (§94).');
        }

        return response()->json(['data' => $this->dashboard->student($studentId, $request->input('date'))]);
    }

    /** Dashboard wali dengan isolasi data (§68, §14). */
    public function parent(Request $request): JsonResponse
    {
        $request->user()->can('religious.portal.parent') || abort(403);

        return response()->json(['data' => $this->dashboard->parent(
            (int) $request->user()->id, $request->input('date')
        )]);
    }

    /** Dashboard musyrif/asrama (§69). */
    public function boarding(Request $request): JsonResponse
    {
        $request->user()->can('religious.portal.boarding') || $request->user()->can('religious.attendance.view') || abort(403);

        return response()->json(['data' => $this->dashboard->boarding(
            $request->integer('dormitory_id') ?: null, $request->input('date')
        )]);
    }

    /** Dashboard presensi keagamaan (§70). */
    public function attendance(Request $request): JsonResponse
    {
        $request->user()->can('religious.attendance.view') || abort(403);

        return response()->json(['data' => $this->dashboard->attendance($request->input('date'), $request->only([
            'campus_id', 'mosque_id', 'dormitory_id', 'grade_level', 'class_id', 'prayer_type',
        ]))]);
    }

    private function authorizeDisplayAccess(Request $request): void
    {
        $user = $request->user();
        ($user->can('religious.display.view') || $user->can('religious.view')) || abort(403);
    }
}
