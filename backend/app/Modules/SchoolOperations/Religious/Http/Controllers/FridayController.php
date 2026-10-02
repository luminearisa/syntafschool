<?php

namespace App\Modules\SchoolOperations\Religious\Http\Controllers;

use App\Modules\SchoolOperations\Religious\Application\Services\{FridayPrayerService, KhutbahService};
use App\Modules\SchoolOperations\Religious\Models\Khutbah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Shalat Jumat, khatib, khutbah, dan pustakanya (§27-§31, §92). */
class FridayController extends Controller
{
    public function __construct(
        private readonly FridayPrayerService $friday,
        private readonly KhutbahService $khutbahs,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('religious.friday.view') || abort(403);

        return response()->json(['data' => [
            'history' => $this->friday->history($request->only(['mosque_id', 'from', 'to', 'limit'])),
            'upcoming' => $this->friday->upcoming(5, $request->integer('mosque_id') ?: null),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('religious.friday.manage') || abort(403);

        return response()->json(['data' => $this->friday->schedule($request->validate([
            'mosque_id' => ['required', 'integer'], 'campus_id' => ['nullable', 'integer'],
            'prayer_date' => ['required', 'date'], 'khatib_id' => ['nullable', 'integer'],
            'imam_id' => ['nullable', 'integer'], 'muadzin_id' => ['nullable', 'integer'],
            'topic' => ['nullable', 'string'], 'theme' => ['nullable', 'string'],
            'language' => ['nullable', 'string'], 'start_time' => ['nullable'],
            'end_time' => ['nullable'], 'estimated_attendance' => ['nullable', 'integer'],
            'khutbah_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.friday.manage') || abort(403);
        $validated = $request->validate([
            'role' => ['required', 'string', 'in:KHATIB,IMAM,MUADZIN'], 'profile_id' => ['required', 'integer'],
        ]);

        return response()->json(['data' => $this->friday->assign($id, $validated['role'], (int) $validated['profile_id'])]);
    }

    public function attendance(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.friday.manage') || abort(403);
        $validated = $request->validate(['actual_attendance' => ['required', 'integer', 'min:0']]);

        return response()->json(['data' => $this->friday->recordAttendance($id, (int) $validated['actual_attendance'], $request->all())]);
    }

    // ── Khutbah (§29-§31, §92) ─────────────────────────────────

    public function khutbahs(Request $request): JsonResponse
    {
        $request->user()->can('religious.khutbah.view') || abort(403);

        return response()->json(['data' => $this->khutbahs->library($request->only([
            'term', 'category_code', 'khatib_id', 'language', 'from', 'to', 'limit',
        ]))]);
    }

    public function storeKhutbah(Request $request): JsonResponse
    {
        $request->user()->can('religious.khutbah.create') || abort(403);

        return response()->json(['data' => $this->khutbahs->create($request->validate([
            'title' => ['required', 'string'], 'theme' => ['nullable', 'string'],
            'category_code' => ['nullable', 'string'], 'category_id' => ['nullable', 'integer'],
            'khatib_id' => ['nullable', 'integer'], 'khatib_name' => ['nullable', 'string'],
            'mosque_id' => ['nullable', 'integer'], 'prayer_date' => ['nullable', 'date'],
            'language' => ['nullable', 'string'], 'duration_minutes' => ['nullable', 'integer'],
            'reference_text' => ['nullable', 'string'], 'summary' => ['nullable', 'string'],
            'content' => ['nullable', 'string'], 'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    public function showKhutbah(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.khutbah.view') || abort(403);
        $khutbah = Khutbah::query()->findOrFail($id);

        return response()->json(['data' => [
            'khutbah' => $khutbah,
            'versions' => $this->khutbahs->versions($id),
        ]]);
    }

    public function newKhutbahVersion(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.khutbah.create') || abort(403);
        $khutbah = Khutbah::query()->findOrFail($id);

        return response()->json(['data' => $this->khutbahs->newVersion($khutbah, $request->all())]);
    }

    public function attachKhutbahDocument(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.khutbah.create') || abort(403);
        $khutbah = Khutbah::query()->findOrFail($id);

        return response()->json(['data' => $this->khutbahs->attachDocument($khutbah, $request->all())], 201);
    }

    /** Alur persetujuan khutbah (§79, §92). */
    public function transitionKhutbah(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', 'string'], 'reason' => ['nullable', 'string']]);
        $status = strtoupper($validated['status']);

        $ability = match ($status) {
            'REVIEW' => 'religious.khutbah.review',
            'APPROVED' => 'religious.khutbah.approve',
            'DELIVERED' => 'religious.khutbah.deliver',
            default => 'religious.khutbah.create',
        };
        $request->user()->can($ability) || abort(403);

        $khutbah = Khutbah::query()->findOrFail($id);

        return response()->json(['data' => $this->khutbahs->transition($khutbah, $status, $request->all())]);
    }

    public function categories(Request $request): JsonResponse
    {
        $request->user()->can('religious.khutbah.view') || abort(403);

        return response()->json(['data' => \App\Modules\SchoolOperations\Religious\Models\KhutbahCategory::query()->where('active', true)->orderBy('sort_order')->get()]);
    }
}
