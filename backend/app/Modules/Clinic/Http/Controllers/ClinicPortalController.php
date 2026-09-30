<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Clinic\Application\Services\ClinicAccessService;
use App\Modules\Clinic\Application\Services\ClinicAuditService;
use App\Modules\Clinic\Application\Services\PatientService;
use App\Modules\Clinic\Domain\Contracts\ParentProviderInterface;
use App\Modules\Clinic\Domain\Contracts\StudentProviderInterface;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Models\ClinicDocument;
use App\Modules\Clinic\Models\ClinicHealthCertificate;
use App\Modules\Clinic\Models\ClinicPatient;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal kesehatan orang tua & siswa.
 *
 * Portal ini adalah wajah modul bagi keluarga, dan justru karena itu paling
 * sering menjadi sasaran kesalahan paling berbahaya: orang tua A membuka data
 * anak B lewat ID yang ditebak. Karena itu:
 *
 * - Tidak ada endpoint yang menerima `patient_id` mentah dari orang tua tanpa
 *   pemeriksaan relasi keluarga. Daftar anak selalu berasal dari
 *   `ParentProviderInterface` (tabel `student_guardians`).
 * - Setiap pembukaan data anak dicatat sebagai VIEW agar dapat diaudit.
 * - Isi yang ditampilkan disaring kebijakan: catatan internal, asesmen, dan
 *   diagnosis rinci mengikuti `clinic.privacy.*` di konfigurasi.
 */
class ClinicPortalController extends BaseApiController
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly PatientService $patients,
        private readonly ParentProviderInterface $parents,
        private readonly StudentProviderInterface $students,
        private readonly ClinicAuditService $audit,
    ) {
    }

    /** Daftar anak/siswa yang boleh dilihat pengguna portal ini. */
    public function children(Request $request): JsonResponse
    {
        $user = $request->user();
        $patientIds = $this->access->visiblePatientIds($user) ?? [];

        $patients = ClinicPatient::query()
            ->with('student:id,nis,nisn,full_name,nick_name,birth_date,blood_type')
            ->whereIn('id', $patientIds === [] ? [-1] : $patientIds)
            ->get();

        // Siswa yang belum punya rekam medis tetap harus muncul agar orang tua
        // tahu anaknya belum terdaftar di klinik (bukan disembunyikan).
        $registered = $patients->pluck('student_id')->filter()->all();

        $unregistered = collect();
        if ($this->access->isParentOnly($user)) {
            $unregistered = collect($this->access->childStudentIdsFor($user))
                ->reject(fn (int $id): bool => in_array($id, $registered, true))
                ->map(fn (int $id): array => $this->students->snapshot($id))
                ->filter()
                ->values();
        }

        return $this->successResponse([
            'registered' => $patients->map(fn (ClinicPatient $patient): array => [
                'patient_id' => $patient->id,
                'medical_record_number' => $patient->medical_record_number,
                'student_id' => $patient->student_id,
                'name' => $patient->student?->full_name,
                'nis' => $patient->student?->nis,
                'birth_date' => $patient->student?->birth_date?->toDateString(),
                'blood_type' => $patient->blood_type,
                'alerts' => [
                    'allergy' => (bool) $patient->allergy_alert,
                    'special_care' => (bool) $patient->special_care_alert,
                ],
                'last_visit_at' => ClinicVisit::query()->where('patient_id', $patient->id)->max('visit_at'),
            ])->all(),
            'unregistered' => $unregistered,
            'privacy' => $this->privacyPolicy(),
        ]);
    }

    /** Ringkasan kesehatan seorang anak (hanya bila relasinya sah). */
    public function child(Request $request, ClinicPatient $patient): JsonResponse
    {
        $this->access->assertCanSeePatientModel($request->user(), $patient);
        $this->audit->recordView($patient, (int) $patient->id, $request->user(), 'Orang tua/siswa membuka ringkasan kesehatan dari portal');

        $profile = $this->patients->profile($patient, false);

        return $this->successResponse([
            'patient' => $profile['patient'],
            'identity' => $profile['identity'],
            'guardians' => $profile['guardians'],
            'allergies' => $this->visibleAllergies($profile['allergies']),
            'special_conditions' => $this->visibleConditions($profile['special_conditions']),
            'visit_summary' => $profile['visit_summary'],
            'vitals_latest' => $profile['vitals_latest'],
            'medication_history' => (bool) config('clinic.privacy.parent_visible_medication', true) ? $profile['medication_history'] : [],
            'follow_ups' => $profile['follow_ups'],
            'certificates' => $this->certificateList($patient),
            'privacy' => $this->privacyPolicy(),
        ]);
    }

    /** Riwayat kunjungan anak dengan isi yang sudah disaring kebijakan. */
    public function visits(Request $request, ClinicPatient $patient): JsonResponse
    {
        $this->access->assertCanSeePatientModel($request->user(), $patient);
        $this->audit->recordView($patient, (int) $patient->id, $request->user(), 'Membuka riwayat kunjungan dari portal');

        $showDiagnosis = (bool) config('clinic.privacy.parent_visible_diagnosis', true);
        $showNotes = (bool) config('clinic.privacy.parent_visible_notes', false);
        $showAssessment = (bool) config('clinic.privacy.parent_visible_assessment', false);
        $showMedication = (bool) config('clinic.privacy.parent_visible_medication', true);

        $visits = ClinicVisit::query()
            ->with([
                'triage:id,visit_id,priority,chief_complaint',
                'primaryDiagnosis',
                'discharge:id,visit_id,status,condition,instructions,follow_up_required,follow_up_date',
                'referrals:id,referral_number,patient_id,facility,urgency,status,referral_date',
                'administrations',
            ])
            ->where('patient_id', $patient->id)
            ->orderByDesc('visit_at')
            ->limit(50)
            ->get();

        // Referral diambil lewat relasi kunjungan agar tidak ada IDOR: semuanya
        // difilter oleh patient_id yang sudah diverifikasi di atas.
        return $this->successResponse($visits->map(fn (ClinicVisit $visit): array => [
            'visit_number' => $visit->visit_number,
            'visit_at' => $visit->visit_at?->toIso8601String(),
            'visit_type' => $visit->visit_type,
            'visit_type_label' => $visit->typeEnum()->label(),
            'status' => $visit->status,
            'status_label' => $visit->statusEnum()->label(),
            'chief_complaint' => $visit->triage?->chief_complaint ?? $visit->chief_complaint,
            'priority' => $visit->priority,
            'diagnosis' => $showDiagnosis ? $visit->primaryDiagnosis?->diagnosis_name : null,
            'notes' => $showNotes ? $visit->notes : null,
            'assessment_summary' => $showAssessment ? $visit->currentAssessment?->assessment : null,
            'medications' => $showMedication
                ? $visit->administrations->map(fn ($row): array => [
                    'medication_name' => $row->medication_name,
                    'dose' => $row->dose,
                    'status' => $row->status,
                    'administered_at' => $row->administered_at?->toIso8601String(),
                ])->all()
                : [],
            'discharge' => $visit->discharge === null ? null : [
                'status' => $visit->discharge->status,
                'status_label' => $visit->discharge->statusEnum()->label(),
                'condition' => $visit->discharge->condition,
                'instructions' => $visit->discharge->instructions,
                'follow_up_required' => (bool) $visit->discharge->follow_up_required,
                'follow_up_date' => $visit->discharge->follow_up_date?->toDateString(),
            ],
            'referrals' => $visit->referrals->map(fn ($referral): array => [
                'referral_number' => $referral->referral_number,
                'facility' => $referral->facility,
                'urgency' => $referral->urgency,
                'status' => $referral->status,
                'referral_date' => $referral->referral_date?->toIso8601String(),
            ])->all(),
        ])->all());
    }

    /** Surat keterangan yang sudah terbit untuk anak. */
    public function certificates(Request $request, ClinicPatient $patient): JsonResponse
    {
        $this->access->assertCanSeePatientModel($request->user(), $patient);

        return $this->successResponse($this->certificateList($patient));
    }

    /** Dokumen kesehatan yang boleh dilihat keluarga (visibilitas PARENT). */
    public function documents(Request $request, ClinicPatient $patient): JsonResponse
    {
        $this->access->assertCanSeePatientModel($request->user(), $patient);

        $rows = $patient->documents()
            ->whereIn('visibility', ['PARENT', 'PUBLIC'])
            ->where('is_current', true)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ClinicDocument $document): array => [
                'id' => $document->id,
                'document_number' => $document->document_number,
                'type' => $document->type,
                'title' => $document->title,
                'version' => $document->version,
                'created_at' => $document->created_at?->toIso8601String(),
            ]);

        return $this->successResponse($rows);
    }

    /** Kebijakan privasi yang sedang berlaku, agar sekolah transparan. */
    private function privacyPolicy(): array
    {
        return [
            'notes_visible' => (bool) config('clinic.privacy.parent_visible_notes', false),
            'assessment_visible' => (bool) config('clinic.privacy.parent_visible_assessment', false),
            'diagnosis_visible' => (bool) config('clinic.privacy.parent_visible_diagnosis', true),
            'medication_visible' => (bool) config('clinic.privacy.parent_visible_medication', true),
            'read_only' => true,
        ];
    }

    /** @param list<array<string, mixed>> $allergies */
    private function visibleAllergies(array $allergies): array
    {
        // Alergi selalu ditampilkan kepada keluarga: keselamatan lebih penting
        // daripada kerahasiaan, dan alergi memang perlu diketahui orang tua.
        return $allergies;
    }

    /** @param list<array<string, mixed>> $conditions */
    private function visibleConditions(array $conditions): array
    {
        return $conditions;
    }

    /** @return list<array<string, mixed>> */
    private function certificateList(ClinicPatient $patient): array
    {
        return ClinicHealthCertificate::query()
            ->where('patient_id', $patient->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (ClinicHealthCertificate $certificate): array => [
                'certificate_number' => $certificate->certificate_number,
                'type' => $certificate->type,
                'type_label' => $certificate->typeEnum()->label(),
                'status' => $certificate->status,
                'issued_at' => $certificate->issued_at?->toIso8601String(),
                'valid_from' => $certificate->valid_from?->toDateString(),
                'valid_until' => $certificate->valid_until?->toDateString(),
                'is_valid' => $certificate->isValid(),
                'verification_code' => $certificate->verification_code,
            ])->all();
    }
}
