<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\CourseAnnouncementService;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Http\Resources\LmsAnnouncementResource;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseAnnouncement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pengumuman course (§24). */
class LmsAnnouncementController extends LmsController
{
    public function __construct(
        private readonly CourseAnnouncementService $announcements,
        private readonly CourseAccessService $access,
    ) {}

    public function index(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $manages = $this->access->canManage($course, $actor);

        if (! $manages) {
            $decision = $this->access->decide($course, $actor);
            $this->authorizeLms($decision->allowed, $decision->message ?? 'Anda tidak memiliki akses ke course ini.');
        }

        return $this->successResponse(
            LmsAnnouncementResource::collection(
                $this->announcements->forCourse($course, ! $manages)->paginate($this->perPage($request)),
            ),
            'Daftar pengumuman berhasil diambil',
        );
    }

    public function store(Request $request, LmsCourse $course): JsonResponse
    {
        $this->authorizeLms(
            $this->access->canManage($course, $this->actor($request)),
            'Anda tidak berhak membuat pengumuman pada course ini.',
        );

        $announcement = $this->announcements->create($course, $this->validated($request), $this->actor($request));

        return $this->createdResponse(new LmsAnnouncementResource($announcement), 'Pengumuman berhasil dibuat dan dikirim ke peserta');
    }

    public function update(Request $request, LmsCourseAnnouncement $announcement): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($announcement->course, $this->actor($request)));

        return $this->successResponse(
            new LmsAnnouncementResource($this->announcements->update($announcement, $this->validated($request, updating: true))),
            'Pengumuman berhasil diperbarui',
        );
    }

    public function togglePin(Request $request, LmsCourseAnnouncement $announcement): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($announcement->course, $this->actor($request)));

        return $this->successResponse(
            new LmsAnnouncementResource($this->announcements->togglePin($announcement)),
            'Status pin pengumuman berhasil diubah',
        );
    }

    public function destroy(Request $request, LmsCourseAnnouncement $announcement): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($announcement->course, $this->actor($request)));

        $this->announcements->delete($announcement);

        return $this->successResponse(null, 'Pengumuman berhasil dihapus');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'title' => [$updating ? 'sometimes' : 'required', 'string', 'max:200'],
            'content' => [$updating ? 'sometimes' : 'required', 'string', 'max:200000'],
            'pinned' => ['nullable', 'boolean'],
            'publish' => ['nullable', 'boolean'],
            'published_at' => ['nullable', 'date'],
        ], [], ['title' => 'judul pengumuman', 'content' => 'isi pengumuman']);
    }
}
