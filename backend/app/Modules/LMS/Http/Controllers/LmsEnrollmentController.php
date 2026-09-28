<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\CourseEnrollmentService;
use App\Modules\LMS\Domain\Enums\EnrollmentMethod;
use App\Modules\LMS\Domain\Enums\EnrollmentRole;
use App\Modules\LMS\Domain\Enums\EnrollmentStatus;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Http\Resources\LmsEnrollmentResource;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pendaftaran peserta: manual, massal, dan sinkronisasi SIAKAD (§15–§18). */
class LmsEnrollmentController extends LmsController
{
    public function __construct(
        private readonly CourseEnrollmentService $enrollments,
        private readonly CourseAccessService $access,
    ) {}

    public function index(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(
            $this->access->canManage($course, $actor) || $actor->can('lms.enrollment.view'),
            'Anda tidak berhak melihat daftar peserta course ini.',
        );

        $query = $this->enrollments->roster($course, $request->only(['role', 'status', 'method', 'search']));

        return $this->successResponse([
            'summary' => $this->enrollments->summary($course),
            'enrollments' => LmsEnrollmentResource::collection(
                $query->paginate($this->perPage($request, 25))->appends($request->query()),
            ),
        ], 'Daftar peserta course berhasil diambil');
    }

    public function store(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($this->access->canManage($course, $actor), 'Anda tidak berhak menambah peserta pada course ini.');

        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
            'teacher_id' => ['nullable', 'integer'],
            'role' => ['nullable', Rule::in(EnrollmentRole::values())],
            'enrollment_method' => ['nullable', Rule::in(EnrollmentMethod::values())],
            'status' => ['nullable', Rule::in(EnrollmentStatus::values())],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
        ]);

        $enrollment = $this->enrollments->enroll($course, $data, $actor);

        return $this->createdResponse(
            new LmsEnrollmentResource($enrollment->load('user')),
            'Peserta berhasil didaftarkan',
        );
    }

    /** Pendaftaran massal berdasarkan daftar id siswa SIAKAD (§18). */
    public function bulk(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($this->access->canManage($course, $actor), 'Anda tidak berhak menambah peserta pada course ini.');

        $data = $request->validate([
            'student_ids' => ['nullable', 'array'],
            'student_ids.*' => ['integer'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
            'method' => ['nullable', Rule::in(EnrollmentMethod::values())],
        ]);

        $method = $data['method'] ?? EnrollmentMethod::MANUAL->value;

        $result = ($data['student_ids'] ?? []) !== []
            ? $this->enrollments->enrollStudents($course, $data['student_ids'], $actor, $method)
            : $this->enrollments->bulkEnroll($course, $data['user_ids'] ?? [], $actor, $method);

        return $this->successResponse($result, 'Pendaftaran massal selesai diproses');
    }

    /** Calon peserta yang belum terdaftar — bahan dialog "Tambah Siswa" (§18). */
    public function candidates(Request $request, LmsCourse $course): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($course, $this->actor($request)));

        $term = (string) $request->query('search', '');

        return $this->successResponse(
            $this->enrollments->availableStudents($course, $term, $this->perPage($request, 25)),
            'Daftar calon peserta berhasil diambil',
        );
    }

    public function update(Request $request, LmsEnrollment $enrollment): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($enrollment->course, $this->actor($request)));

        $data = $request->validate([
            'role' => ['nullable', Rule::in(EnrollmentRole::values())],
            'status' => ['nullable', Rule::in(EnrollmentStatus::values())],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
        ]);

        return $this->successResponse(
            new LmsEnrollmentResource($this->enrollments->update($enrollment, $data)),
            'Data peserta berhasil diperbarui',
        );
    }

    public function suspend(Request $request, LmsEnrollment $enrollment): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($enrollment->course, $this->actor($request)));

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->successResponse(
            new LmsEnrollmentResource($this->enrollments->suspend($enrollment, $data['reason'] ?? null, $this->actor($request))),
            'Peserta berhasil ditangguhkan',
        );
    }

    public function cancel(Request $request, LmsEnrollment $enrollment): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($enrollment->course, $this->actor($request)));

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->successResponse(
            new LmsEnrollmentResource($this->enrollments->cancel($enrollment, $data['reason'] ?? null, $this->actor($request))),
            'Keikutsertaan peserta dibatalkan',
        );
    }

    public function reactivate(Request $request, LmsEnrollment $enrollment): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($enrollment->course, $this->actor($request)));

        return $this->successResponse(
            new LmsEnrollmentResource($this->enrollments->reactivate($enrollment, $this->actor($request))),
            'Peserta berhasil diaktifkan kembali',
        );
    }

    public function complete(Request $request, LmsEnrollment $enrollment): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($enrollment->course, $this->actor($request)));

        return $this->successResponse(
            new LmsEnrollmentResource($this->enrollments->complete($enrollment, $this->actor($request))),
            'Peserta ditandai selesai',
        );
    }

    public function destroy(Request $request, LmsEnrollment $enrollment): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($enrollment->course, $this->actor($request)));

        $this->enrollments->remove($enrollment, $this->actor($request));

        return $this->successResponse(null, 'Peserta dikeluarkan dari course');
    }

    /**
     * POST /lms/courses/{course}/enroll — siswa mendaftar sendiri (§16 SELF).
     *
     * Hanya untuk course non-akademik yang mengizinkan pendaftaran mandiri;
     * course akademik tetap ditentukan SIAKAD.
     */
    public function selfEnroll(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);

        if (! config('lms.enrollment.self_enrollment', true)) {
            return $this->errorResponse('Pendaftaran mandiri sedang dinonaktifkan oleh sekolah.', 403);
        }

        if ($course->isAcademic()) {
            return $this->errorResponse('Course akademik didaftarkan otomatis dari SIAKAD, bukan mandiri.', 422);
        }

        if (! $course->isPublished() || ! $course->allowsSelfEnrollment()) {
            return $this->errorResponse('Course ini tidak terbuka untuk pendaftaran mandiri.', 422);
        }

        $enrollment = $this->enrollments->enroll($course, [
            'user_id' => $actor->id,
            'role' => EnrollmentRole::STUDENT->value,
            'enrollment_method' => EnrollmentMethod::SELF->value,
        ], $actor);

        return $this->createdResponse(
            new LmsEnrollmentResource($enrollment),
            'Berhasil mendaftar pada course ini',
        );
    }

    /** Sinkronkan peserta dari rombel SIAKAD tanpa mengetik ulang (§6, §17). */
    public function syncAcademic(Request $request, LmsCourse $course): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($course, $this->actor($request)));

        $result = $this->enrollments->syncAcademicEnrollments($course, $this->actor($request));

        return $this->successResponse($result, 'Sinkronisasi peserta dari SIAKAD selesai');
    }
}
