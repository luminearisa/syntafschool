<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\CourseBackupService;
use App\Modules\LMS\Application\Services\CourseExportService;
use App\Modules\LMS\Application\Services\CourseImportService;
use App\Modules\LMS\Domain\Enums\ImportConflictStrategy;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ekspor, impor, backup, dan pemulihan course (§14–§15).
 *
 * Impor mengikuti urutan wajib: UPLOAD → VALIDATE → PREVIEW → RESOLVE CONFLICT
 * → IMPORT → VERIFY. Tidak ada penimpaan tanpa strategi yang dipilih eksplisit.
 */
class LmsImportExportController extends LmsController
{
    public function __construct(
        private readonly CourseExportService $exports,
        private readonly CourseImportService $imports,
        private readonly CourseBackupService $backups,
        private readonly CourseAccessService $access,
    ) {}

    /** GET /lms/courses/{course}/export */
    public function export(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeExport($model);

        $payload = $this->exports->export($model, $actor, [
            'include_content' => $request->boolean('include_content', true),
            'include_files' => $request->boolean('include_files', true),
            'include_activities' => $request->boolean('include_activities', true),
        ]);

        if ($request->boolean('store')) {
            return $this->createdResponse(
                $this->exports->store($payload, $model, $actor),
                'Berkas ekspor disimpan.',
            );
        }

        return $this->successResponse([
            'summary' => $this->exports->summary($payload),
            'payload' => $payload,
        ]);
    }

    /** POST /lms/courses/import/upload */
    public function upload(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeImport();

        $request->validate(['file' => ['required', 'file', 'mimetypes:application/json,text/plain', 'max:20480']]);

        return $this->createdResponse($this->imports->upload($request->file('file'), $actor), 'Berkas impor diunggah.');
    }

    /** POST /lms/courses/import/validate */
    public function validateImport(Request $request): JsonResponse
    {
        $this->authorizeImport();

        $data = $request->validate([
            'payload' => ['nullable', 'array'],
            'json' => ['nullable', 'string'],
            'target_course_id' => ['nullable', 'integer'],
        ]);

        $json = $data['json'] ?? ($data['payload'] !== null ?? false ? json_encode($data['payload']) : null);

        if ($json === null) {
            return $this->errorResponse('Sertakan `payload` (objek) atau `json` (teks) hasil ekspor.', 422);
        }

        $target = isset($data['target_course_id']) ? $this->course((int) $data['target_course_id']) : null;

        return $this->successResponse($this->imports->validate((string) $json, $target), 'Hasil validasi impor.');
    }

    /** POST /lms/courses/import */
    public function import(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeImport();

        $data = $request->validate([
            'payload' => ['required', 'array'],
            'conflict_strategy' => ['nullable', 'string', 'in:SKIP,REPLACE,DUPLICATE,MERGE'],
            'target_course_id' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'max:190'],
        ]);

        $validation = $this->imports->validate((string) json_encode($data['payload']));

        if (! $validation['valid']) {
            return $this->validationErrorResponse(['payload' => $validation['errors']], 'Berkas impor tidak valid.');
        }

        $target = isset($data['target_course_id']) ? $this->course((int) $data['target_course_id']) : null;

        if ($target !== null) {
            $this->authorizeLms($this->access->canManage($target, $actor), 'Anda tidak berhak mengimpor ke course ini.');
        }

        $result = $this->imports->import(
            payload: $data['payload'],
            actor: $actor,
            strategy: ImportConflictStrategy::tryFrom((string) ($data['conflict_strategy'] ?? 'SKIP')) ?? ImportConflictStrategy::SKIP,
            target: $target,
            idempotencyKey: $data['idempotency_key'] ?? null,
        );

        return $this->createdResponse($result, 'Impor selesai.');
    }

    /** GET /lms/courses/imports */
    public function history(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.course.import') || $actor->can('lms.manage'), 'Anda tidak berhak melihat riwayat impor.');

        return $this->successResponse([
            'imports' => $this->imports->history($request->only('status', 'course_id'), (int) $this->perPage($request, 25)),
        ]);
    }

    /** GET /lms/courses/imports/{import} */
    public function showImport(Request $request, int $import): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.course.import') || $actor->can('lms.manage'), 'Anda tidak berhak melihat riwayat impor.');

        $model = LmsCourseImport::query()->find($import);
        abort_if($model === null, 404, 'Riwayat impor tidak ditemukan.');

        return $this->successResponse([
            'import' => $model->toPayload(),
            'verification' => $this->imports->verify($model),
        ]);
    }

    /** GET /lms/courses/{course}/backups */
    public function backups(Request $request, int $course): JsonResponse
    {
        $this->authorizeExport($this->course($course));

        return $this->successResponse(['backups' => $this->backups->backups()]);
    }

    /** POST /lms/courses/{course}/backups */
    public function createBackup(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeExport($model);

        $data = $request->validate(['label' => ['nullable', 'string', 'max:180']]);

        return $this->createdResponse($this->backups->backup($model, $actor, $data['label'] ?? null), 'Backup dibuat.');
    }

    /** POST /lms/courses/backups/verify */
    public function verifyBackup(Request $request): JsonResponse
    {
        $this->authorizeImport();

        $data = $request->validate(['path' => ['required', 'string', 'max:255']]);

        return $this->successResponse($this->backups->verify((string) $data['path']), 'Hasil uji backup.');
    }

    /** POST /lms/courses/backups/restore */
    public function restore(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeImport();

        $data = $request->validate([
            'path' => ['required', 'string', 'max:255'],
            'target_course_id' => ['nullable', 'integer'],
            'replace_target' => ['nullable', 'boolean'],
        ]);

        $result = $this->backups->restore(
            (string) $data['path'],
            $actor,
            isset($data['target_course_id']) ? (int) $data['target_course_id'] : null,
            (bool) ($data['replace_target'] ?? false),
        );

        return $this->successResponse($result, 'Pemulihan selesai.');
    }

    private function course(int $id): LmsCourse
    {
        $course = LmsCourse::query()->find($id);
        abort_if($course === null, 404, 'Course tidak ditemukan.');

        return $course;
    }

    private function authorizeExport(LmsCourse $course): void
    {
        $actor = $this->actor(request());

        $this->authorizeLms(
            ($actor->can('lms.course.export') || $actor->can('lms.manage')) && $this->access->canManage($course, $actor),
            'Anda tidak berhak mengekspor course ini.',
        );
    }

    private function authorizeImport(): void
    {
        $actor = $this->actor(request());

        $this->authorizeLms($actor->can('lms.course.import') || $actor->can('lms.manage'), 'Anda tidak berhak mengimpor course.');
    }
}
