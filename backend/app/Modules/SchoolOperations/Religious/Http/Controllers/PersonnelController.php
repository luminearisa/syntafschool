<?php

namespace App\Modules\SchoolOperations\Religious\Http\Controllers;

use App\Modules\SchoolOperations\Religious\Application\Services\PersonnelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Imam, muadzin, khatib, ketersediaan, dan pengganti (§16-§21, §28). */
class PersonnelController extends Controller
{
    public function __construct(private readonly PersonnelService $personnel)
    {
    }

    public function imams(Request $request): JsonResponse
    {
        $request->user()->can('religious.personnel.view') || abort(403);

        return response()->json(['data' => $this->personnel->imams($request->only(['mosque_id', 'campus_id', 'limit']))]);
    }

    public function storeImam(Request $request): JsonResponse
    {
        $request->user()->can('religious.personnel.manage') || abort(403);

        return response()->json(['data' => $this->personnel->registerImam($request->validate([
            'person_type' => ['nullable', 'string'], 'employee_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'], 'external_name' => ['nullable', 'string'],
            'full_name' => ['nullable', 'string'], 'qualification' => ['nullable', 'string'],
            'specialization' => ['nullable', 'string'], 'campus_id' => ['nullable', 'integer'],
            'mosque_id' => ['nullable', 'integer'], 'scope_type' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    public function muadzins(Request $request): JsonResponse
    {
        $request->user()->can('religious.personnel.view') || abort(403);

        return response()->json(['data' => $this->personnel->muadzins($request->only(['mosque_id', 'limit']))]);
    }

    public function storeMuadzin(Request $request): JsonResponse
    {
        $request->user()->can('religious.personnel.manage') || abort(403);

        return response()->json(['data' => $this->personnel->registerMuadzin($request->validate([
            'person_type' => ['nullable', 'string'], 'employee_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'], 'external_name' => ['nullable', 'string'],
            'full_name' => ['nullable', 'string'], 'qualification' => ['nullable', 'string'],
            'mosque_id' => ['nullable', 'integer'], 'campus_id' => ['nullable', 'integer'],
        ]))], 201);
    }

    public function khatibs(Request $request): JsonResponse
    {
        $request->user()->can('religious.personnel.view') || abort(403);

        return response()->json(['data' => $this->personnel->khatibs($request->only(['campus_id', 'limit']))]);
    }

    public function storeKhatib(Request $request): JsonResponse
    {
        $request->user()->can('religious.personnel.manage') || abort(403);

        return response()->json(['data' => $this->personnel->registerKhatib($request->validate([
            'person_type' => ['nullable', 'string'], 'employee_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'], 'external_name' => ['nullable', 'string'],
            'full_name' => ['nullable', 'string'], 'qualification' => ['nullable', 'string'],
            'specialization' => ['nullable', 'string'], 'languages' => ['nullable', 'array'],
            'bio' => ['nullable', 'string'], 'campus_id' => ['nullable', 'integer'],
        ]))], 201);
    }

    /** Penugasan imam/muadzin/khatib dengan validasi bentrok (§60). */
    public function assignImam(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.personnel.manage') || abort(403);

        return response()->json(['data' => $this->personnel->assignImam($id, $request->all())], 201);
    }

    public function assignMuadzin(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.personnel.manage') || abort(403);

        return response()->json(['data' => $this->personnel->assignMuadzin($id, $request->all())], 201);
    }

    public function assignKhatib(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.personnel.manage') || abort(403);

        return response()->json(['data' => $this->personnel->assignKhatib($id, $request->all())], 201);
    }

    public function availability(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.personnel.view') || abort(403);

        return response()->json(['data' => $this->personnel->availability(
            $id, (string) $request->input('date', now()->toDateString())
        )]);
    }

    public function setAvailability(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.personnel.manage') || abort(403);

        return response()->json(['data' => $this->personnel->setAvailability($id, $request->all())], 201);
    }

    /** Pengajuan pengganti (§21). */
    public function requestSubstitute(Request $request, int $assignmentId): JsonResponse
    {
        $request->user()->can('religious.personnel.manage') || abort(403);
        $validated = $request->validate(['substitute_personnel_id' => ['required', 'integer']]);

        return response()->json(['data' => $this->personnel->requestSubstitute(
            $assignmentId, (int) $validated['substitute_personnel_id'], $request->only(['reason', 'reason_detail', 'notes'])
        )], 201);
    }

    public function approveSubstitute(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.personnel.manage') || abort(403);

        return response()->json(['data' => $this->personnel->approveSubstitute($id, $request->only(['reason']))]);
    }

    public function registerTeacher(Request $request): JsonResponse
    {
        $request->user()->can('religious.personnel.manage') || abort(403);

        return response()->json(['data' => $this->personnel->registerReligiousTeacher($request->all())], 201);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $request->user()->can('religious.personnel.view') || abort(403);

        return response()->json(['data' => $this->personnel->dashboard($request->only(['from', 'to']))]);
    }
}
