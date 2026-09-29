<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CertificateService;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsCertificate;
use App\Modules\LMS\Models\LmsCertificateTemplate;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sertifikat (§21–§25).
 *
 * Verifikasi publik sengaja **tidak** membuka data pribadi: hanya status, nama
 * pada sertifikat, judul course, dan waktu terbit.
 */
class LmsCertificateController extends LmsController
{
    public function __construct(
        private readonly CertificateService $certificates,
        private readonly CourseAccessService $access,
    ) {}

    /** GET /lms/my/certificates */
    public function mine(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        return $this->successResponse(['certificates' => $this->certificates->forUser($actor->id)]);
    }

    /** GET /lms/my/certificates/{certificate} */
    public function show(Request $request, int $certificate): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->certificate($certificate);

        $this->authorizeLms(
            (int) $model->user_id === $actor->id || $actor->can('lms.certificate.manage') || $this->access->parentWatchesCourse($model->course, $actor),
            'Anda tidak berhak melihat sertifikat ini.',
        );

        return $this->successResponse($this->certificates->payload($model) + ['rendered' => $this->certificates->render($model)]);
    }

    /** GET /lms/my/certificates/{certificate}/download */
    public function download(Request $request, int $certificate): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->certificate($certificate);

        $this->authorizeLms((int) $model->user_id === $actor->id, 'Anda tidak berhak mengunduh sertifikat ini.');

        return $this->successResponse($this->certificates->registerDownload($model), 'Unduhan tercatat.');
    }

    /** GET /lms/certificates/verify */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'max:120'],
        ]);

        return $this->successResponse(
            $this->certificates->verify((string) $data['query'], $request->ip(), (string) $request->userAgent()),
            'Hasil verifikasi.',
        );
    }

    /** GET /lms/courses/{course}/certificates/eligibility */
    public function eligibility(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeCertificateManage($model);

        $enrollment = $model->enrollmentFor($actor->id);
        $students = $this->certificates->eligibleStudents($model);
        $sample = $students[0]['enrollment_id'] ?? null;

        return $this->successResponse([
            'course' => ['id' => (int) $model->id, 'name' => $model->name, 'certificate_enabled' => (bool) $model->certificate_enabled],
            'requirements' => $sample !== null
                ? $this->certificates->eligibility(LmsEnrollment::query()->findOrFail((int) $sample))['requirements']
                : $this->certificates->requirementTypes(),
            'students' => $students,
            'viewer' => $enrollment !== null ? $this->certificates->eligibility($enrollment) : null,
        ]);
    }

    /** POST /lms/courses/{course}/certificates/issue */
    public function issue(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeCertificateManage($model);

        $data = $request->validate([
            'enrollment_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'force' => ['nullable', 'boolean'],
        ]);

        if (isset($data['enrollment_id'])) {
            $enrollment = LmsEnrollment::query()->findOrFail((int) $data['enrollment_id']);

            return $this->createdResponse(
                $this->certificates->issue($enrollment, $actor, ['force' => (bool) ($data['force'] ?? false)]),
                'Sertifikat diterbitkan.',
            );
        }

        return $this->successResponse(
            $this->certificates->issueForCourse($model, $actor, (bool) ($data['force'] ?? false)),
            'Penerbitan massal selesai.',
        );
    }

    /** POST /lms/certificates/{certificate}/revoke */
    public function revoke(Request $request, int $certificate): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->certificate($certificate);
        $this->authorizeCertificateManage($model->course);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->successResponse($this->certificates->revoke($model, $actor, (string) $data['reason']), 'Sertifikat dicabut.');
    }

    /** GET /lms/certificates/{certificate}/verifications */
    public function verifications(Request $request, int $certificate): JsonResponse
    {
        $model = $this->certificate($certificate);
        $this->authorizeCertificateManage($model->course);

        return $this->successResponse(['verifications' => $this->certificates->verifications($model)]);
    }

    /** GET /lms/certificate-templates */
    public function templates(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.certificate.template.manage') || $actor->can('lms.manage'), 'Anda tidak berhak melihat template sertifikat.');

        return $this->successResponse(['templates' => $this->certificates->templates()]);
    }

    /** POST /lms/certificate-templates */
    public function storeTemplate(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.certificate.template.manage') || $actor->can('lms.manage'), 'Anda tidak berhak mengubah template sertifikat.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'body_html' => ['nullable', 'string'],
            'signer_name' => ['nullable', 'string', 'max:150'],
            'signer_title' => ['nullable', 'string', 'max:150'],
            'issuer_name' => ['nullable', 'string', 'max:180'],
            'orientation' => ['nullable', 'string'],
            'paper_size' => ['nullable', 'string'],
            'is_default' => ['nullable', 'boolean'],
            'template_data' => ['nullable', 'array'],
        ]);

        return $this->createdResponse($this->certificates->createTemplate($data, $actor), 'Template sertifikat dibuat.');
    }

    /** PUT /lms/certificate-templates/{template} */
    public function updateTemplate(Request $request, int $template): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.certificate.template.manage') || $actor->can('lms.manage'), 'Anda tidak berhak mengubah template sertifikat.');

        $model = LmsCertificateTemplate::query()->find($template);
        abort_if($model === null, 404, 'Template tidak ditemukan.');

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'body_html' => ['nullable', 'string'],
            'signer_name' => ['nullable', 'string', 'max:150'],
            'signer_title' => ['nullable', 'string', 'max:150'],
            'issuer_name' => ['nullable', 'string', 'max:180'],
            'is_default' => ['nullable', 'boolean'],
            'template_data' => ['nullable', 'array'],
        ]);

        return $this->successResponse($this->certificates->updateTemplate($model, $data, $actor), 'Template sertifikat diperbarui.');
    }

    /** GET /lms/certificate-templates/placeholders */
    public function placeholders(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.certificate.template.manage') || $actor->can('lms.manage'), 'Anda tidak berhak melihat template sertifikat.');

        return $this->successResponse(['placeholders' => $this->certificates->placeholders()]);
    }

    private function course(int $id): LmsCourse
    {
        $course = LmsCourse::query()->find($id);
        abort_if($course === null, 404, 'Course tidak ditemukan.');

        return $course;
    }

    private function certificate(int $id): LmsCertificate
    {
        $certificate = LmsCertificate::query()->with('course')->find($id);
        abort_if($certificate === null, 404, 'Sertifikat tidak ditemukan.');

        return $certificate;
    }

    private function authorizeCertificateManage(LmsCourse $course): void
    {
        $actor = $this->actor(request());

        $this->authorizeLms(
            ($actor->can('lms.certificate.issue') || $actor->can('lms.manage')) && $this->access->canManage($course, $actor),
            'Anda tidak berhak mengelola sertifikat course ini.',
        );
    }
}
