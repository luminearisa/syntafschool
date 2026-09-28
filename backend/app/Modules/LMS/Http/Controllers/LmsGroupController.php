<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\CourseGroupService;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Http\Resources\LmsGroupResource;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Kelompok peserta (§19). */
class LmsGroupController extends LmsController
{
    public function __construct(
        private readonly CourseGroupService $groups,
        private readonly CourseAccessService $access,
    ) {}

    public function index(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($this->access->canManage($course, $actor) || $actor->can('lms.group.view'));

        return $this->successResponse(
            LmsGroupResource::collection($course->groups()->withCount('members')->with('members')->get()),
            'Daftar kelompok berhasil diambil',
        );
    }

    public function store(Request $request, LmsCourse $course): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($course, $this->actor($request)));

        $group = $this->groups->create($course, $this->validated($request));

        return $this->createdResponse(new LmsGroupResource($group), 'Kelompok berhasil dibuat');
    }

    public function update(Request $request, LmsGroup $group): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($group->course, $this->actor($request)));

        return $this->successResponse(
            new LmsGroupResource($this->groups->update($group, $this->validated($request, updating: true))),
            'Kelompok berhasil diperbarui',
        );
    }

    public function destroy(Request $request, LmsGroup $group): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($group->course, $this->actor($request)));

        $this->groups->delete($group);

        return $this->successResponse(null, 'Kelompok berhasil dihapus');
    }

    public function addMembers(Request $request, LmsGroup $group): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($group->course, $this->actor($request)));

        $data = $request->validate([
            'enrollment_ids' => ['required', 'array', 'min:1'],
            'enrollment_ids.*' => ['integer', 'exists:lms_enrollments,id'],
        ]);

        return $this->successResponse(
            new LmsGroupResource($this->groups->addMembers($group, $data['enrollment_ids'])),
            'Anggota kelompok berhasil ditambahkan',
        );
    }

    public function removeMembers(Request $request, LmsGroup $group): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($group->course, $this->actor($request)));

        $data = $request->validate([
            'enrollment_ids' => ['required', 'array', 'min:1'],
            'enrollment_ids.*' => ['integer'],
        ]);

        return $this->successResponse(
            new LmsGroupResource($this->groups->removeMembers($group, $data['enrollment_ids'])),
            'Anggota kelompok berhasil dikeluarkan',
        );
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], [], ['name' => 'nama kelompok']);
    }
}
