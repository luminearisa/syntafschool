<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Clinic\Application\Services\CertificateService;
use App\Modules\Clinic\Application\Services\ClinicAccessService;
use App\Modules\Clinic\Domain\Enums\CertificateType;
use App\Modules\Clinic\Domain\Enums\DocumentType;
use App\Modules\Clinic\Domain\Enums\DocumentVisibility;
use App\Modules\Clinic\Models\ClinicDocument;
use App\Modules\Clinic\Models\ClinicHealthCertificate;
use App\Modules\Clinic\Models\ClinicPatient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;

/**
 * Surat keterangan kesehatan & dokumen klinis.
 *
 * Dokumen disimpan pada disk privat dan **hanya** dapat diunduh lewat endpoint
 * ber-otorisasi di sini; setiap unduhan tercatat sebagai SHARE di jejak audit.
 * Kode verifikasi memungkinkan pihak luar memeriksa keaslian surat tanpa
 * membuka rekam medis — endpoint verifikasi publik hanya mengembalikan status
 * dan masa berlaku, bukan isi klinis.
 */
class ClinicCertificateController extends BaseApiController
{
    public function __construct(
        private readonly CertificateService $certificates,
        private readonly ClinicAccessService $access,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->access->scopePatients(
            ClinicHealthCertificate::query()->with(['patient.student:id,nis,full_name']),
            $request->user(),
        );

        $query
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('id');

        return $this->successResponse($query->limit(200)->get());
    }

    /** Ajukan permintaan surat keterangan. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:clinic_patients,id'],
            'visit_id' => ['nullable', 'integer', 'exists:clinic_visits,id'],
            'type' => ['required', Rule::in(CertificateType::values())],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);

        $patient = ClinicPatient::query()->findOrFail($validated['patient_id']);
        $this->access->assertCanSeePatientModel($request->user(), $patient);

        $certificate = $this->certificates->request($patient, $validated, $request->user());

        return $this->createdResponse($certificate, 'Permintaan surat '.$certificate->certificate_number.' dibuat');
    }

    public function show(Request $request, ClinicHealthCertificate $certificate): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $certificate->patient_id);

        return $this->successResponse($certificate->load(['patient.student', 'visit:id,visit_number', 'issuer:id,name', 'reviewer:id,name']));
    }

    /** Tinjau permintaan: setujui atau tolak. */
    public function review(Request $request, ClinicHealthCertificate $certificate): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $certificate->patient_id);

        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'clinical_summary' => ['nullable', 'string', 'max:3000'],
            'restrictions' => ['nullable', 'string', 'max:2000'],
            'recommendation' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:255'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);

        return $this->successResponse($this->certificates->review($certificate, $validated, $request->user()), 'Surat ditinjau');
    }

    /** Terbitkan surat yang sudah disetujui. */
    public function issue(Request $request, ClinicHealthCertificate $certificate): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $certificate->patient_id);

        if (! $this->access->canRecordClinical($request->user()) && ! $request->user()->hasAnyRole(['super-admin', 'admin-sekolah'])) {
            return $this->errorResponse('Anda tidak berwenang menerbitkan surat keterangan kesehatan.', 403);
        }

        return $this->successResponse($this->certificates->issue($certificate, $request->user()), 'Surat diterbitkan');
    }

    /** Unggah dokumen kesehatan (versi baru menandai versi lama). */
    public function storeDocument(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:clinic_patients,id'],
            'file' => ['required', 'file', 'max:8192'],
            'type' => ['nullable', Rule::in(DocumentType::values())],
            'title' => ['nullable', 'string', 'max:191'],
            'visibility' => ['nullable', Rule::in(DocumentVisibility::values())],
            'is_signed' => ['nullable', 'boolean'],
            'documentable_type' => ['nullable', 'string', 'max:120'],
            'documentable_id' => ['nullable', 'integer'],
        ]);

        $patient = ClinicPatient::query()->findOrFail($validated['patient_id']);
        $this->access->assertCanSeePatientModel($request->user(), $patient);

        $document = $this->certificates->storeDocument($patient, $request->file('file'), $validated, $request->user());

        return $this->createdResponse($document, 'Dokumen kesehatan diunggah');
    }

    public function documents(Request $request, ClinicPatient $patient): JsonResponse
    {
        $this->access->assertCanSeePatientModel($request->user(), $patient);

        return $this->successResponse($patient->documents()->orderByDesc('id')->get()->map(fn (ClinicDocument $document): array => [
            'id' => $document->id,
            'document_number' => $document->document_number,
            'type' => $document->type,
            'title' => $document->title,
            'version' => $document->version,
            'is_current' => (bool) $document->is_current,
            'visibility' => $document->visibility,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'created_at' => $document->created_at?->toIso8601String(),
        ]));
    }

    /** Unduh dokumen (setelah pemeriksaan otorisasi & visibilitas). */
    public function download(Request $request, ClinicDocument $document): \Symfony\Component\HttpFoundation\Response
    {
        $patient = ClinicPatient::query()->findOrFail($document->patient_id);
        $this->access->assertCanAccessDocument($request->user(), $patient, $document->visibility);

        $contents = $this->certificates->read($document, $request->user());

        return response()->make($contents, 200, [
            'Content-Type' => $document->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.($document->original_name ?? 'dokumen-kesehatan').'"',
        ]);
    }

    /**
     * Verifikasi keaslian surat (dipakai panitia kegiatan/penyelenggara lomba).
     * Tidak memerlukan login dan **tidak** membuka isi klinis.
     */
    public function verify(string $code): JsonResponse
    {
        $certificate = ClinicHealthCertificate::query()->where('verification_code', $code)->first();

        if ($certificate === null) {
            return $this->errorResponse('Kode verifikasi tidak ditemukan.', 404);
        }

        return $this->successResponse([
            'valid' => $certificate->isValid(),
            'certificate_number' => $certificate->certificate_number,
            'type' => $certificate->type,
            'type_label' => $certificate->typeEnum()->label(),
            'status' => $certificate->status,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'valid_from' => $certificate->valid_from?->toDateString(),
            'valid_until' => $certificate->valid_until?->toDateString(),
        ], 'Status surat ditemukan');
    }
}
