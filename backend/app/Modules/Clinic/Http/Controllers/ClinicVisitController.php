<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Clinic\Application\Services\ClinicAccessService;
use App\Modules\Clinic\Application\Services\ClinicAuditService;
use App\Modules\Clinic\Application\Services\ClinicalRecordService;
use App\Modules\Clinic\Application\Services\DischargeService;
use App\Modules\Clinic\Application\Services\PatientService;
use App\Modules\Clinic\Application\Services\VisitService;
use App\Modules\Clinic\Domain\Contracts\BoardingProviderInterface;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Domain\Enums\Consciousness;
use App\Modules\Clinic\Domain\Enums\DiagnosisType;
use App\Modules\Clinic\Domain\Enums\DischargeCondition;
use App\Modules\Clinic\Domain\Enums\DischargeStatus;
use App\Modules\Clinic\Domain\Enums\GeneralCondition;
use App\Modules\Clinic\Domain\Enums\ObservationCondition;
use App\Modules\Clinic\Domain\Enums\ObservationStatus;
use App\Modules\Clinic\Domain\Enums\Priority;
use App\Modules\Clinic\Domain\Enums\VisitSource;
use App\Modules\Clinic\Domain\Enums\VisitStatus;
use App\Modules\Clinic\Domain\Enums\VisitType;
use App\Modules\Clinic\Models\ClinicDiagnosis;
use App\Modules\Clinic\Models\ClinicObservation;
use App\Modules\Clinic\Models\ClinicPatient;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Workspace kunjungan klinis.
 *
 * Endpoint `show` adalah layar kerja petugas: ia merakit **header pasien**
 * (identitas dari master ERP + peringatan alergi/kondisi khusus) bersama seluruh
 * bagian klinis dalam satu permintaan, sehingga alur triase → asesmen →
 * tindakan → obat → pemulangan dapat dikerjakan tanpa berpindah halaman.
 *
 * Cakupan baris tetap ditegakkan: kunjungan milik pasien di luar lingkup
 * pengguna berakhir 403, termasuk bila ID-nya ditebak.
 */
class ClinicVisitController extends BaseApiController
{
    public function __construct(
        private readonly VisitService $visits,
        private readonly ClinicalRecordService $records,
        private readonly DischargeService $discharges,
        private readonly PatientService $patients,
        private readonly ClinicAccessService $access,
        private readonly ClinicAuditService $audit,
        private readonly BoardingProviderInterface $boarding,
    ) {
    }

    /** Daftar kunjungan dengan filter. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $this->access->scopePatients(
            ClinicVisit::query()->with([
                'patient.student:id,nis,full_name',
                'triage:id,visit_id,priority,chief_complaint',
                'discharge:id,visit_id,status',
            ]),
            $user,
        );

        $query
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('visit_type'), fn ($q, $type) => $q->where('visit_type', $type))
            ->when($request->query('priority'), fn ($q, $priority) => $q->where('priority', $priority))
            ->when($request->query('clinic_id'), fn ($q, $clinicId) => $q->where('clinic_id', (int) $clinicId))
            ->when($request->query('date'), fn ($q, $date) => $q->whereDate('visit_at', $date))
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('visit_at', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('visit_at', '<=', $to))
            ->when($request->boolean('open_only'), fn ($q) => $q->whereNotIn('status', ['DISCHARGED', 'CANCELLED']))
            ->when($request->query('search'), function ($q, $term): void {
                $like = '%'.$term.'%';
                $q->where(fn ($inner) => $inner
                    ->where('visit_number', 'like', $like)
                    ->orWhere('chief_complaint', 'like', $like)
                    ->orWhereHas('patient', fn ($p) => $p
                        ->where('medical_record_number', 'like', $like)
                        ->orWhereHas('student', fn ($s) => $s->where('full_name', 'like', $like)->orWhere('nis', 'like', $like))));
            })
            ->orderByRaw("CASE WHEN status IN ('DISCHARGED','CANCELLED') THEN 1 ELSE 0 END")
            ->orderByDesc('visit_at');

        $page = $query->paginate(min(100, max(5, (int) $request->query('per_page', 20))));

        return $this->successResponse($page->through(fn (ClinicVisit $visit): array => [
            'id' => $visit->id,
            'visit_number' => $visit->visit_number,
            'visit_at' => $visit->visit_at?->toIso8601String(),
            'visit_type' => $visit->visit_type,
            'visit_type_label' => $visit->typeEnum()->label(),
            'priority' => $visit->priority,
            'priority_label' => $visit->priorityEnum()->label(),
            'status' => $visit->status,
            'status_label' => $visit->statusEnum()->label(),
            'is_emergency' => (bool) $visit->is_emergency,
            'chief_complaint' => $visit->triage?->chief_complaint ?? $visit->chief_complaint,
            'patient' => [
                'patient_id' => $visit->patient_id,
                'mrn' => $visit->patient?->medical_record_number,
                'name' => $visit->patient?->student?->full_name,
                'nis' => $visit->patient?->student?->nis,
            ],
            'discharge_status' => $visit->discharge?->status,
        ]));
    }

    /** Daftarkan kunjungan baru (aman terhadap pengiriman ulang). */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['nullable', 'integer', 'exists:clinic_patients,id'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'clinic_id' => ['nullable', 'integer', 'exists:clinic_clinics,id'],
            'appointment_id' => ['nullable', 'integer', 'exists:clinic_appointments,id'],
            'visit_type' => ['nullable', Rule::in(VisitType::values())],
            'source' => ['nullable', Rule::in(VisitSource::values())],
            'priority' => ['nullable', Rule::in(Priority::values())],
            'chief_complaint' => ['nullable', 'string', 'max:255'],
            'complaint_id' => ['nullable', 'integer', 'exists:clinic_complaints,id'],
            'attended_by' => ['nullable', 'integer', 'exists:users,id'],
            'visit_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        if (empty($validated['patient_id']) && empty($validated['student_id'])) {
            return $this->validationErrorResponse(['patient_id' => 'Pilih pasien atau siswa terlebih dahulu.']);
        }

        if (! empty($validated['patient_id'])) {
            $this->access->assertCanSeePatient($request->user(), (int) $validated['patient_id']);
        }

        $visit = $this->visits->register($validated, $request->user());

        return $this->createdResponse(
            $this->workspace($visit, $request->user()),
            'Kunjungan '.$visit->visit_number.' terdaftar',
        );
    }

    /** Workspace kunjungan. */
    public function show(Request $request, ClinicVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);
        $this->audit->recordView($visit, (int) $visit->patient_id, $request->user(), 'Membuka workspace kunjungan');

        return $this->successResponse($this->workspace($visit, $request->user()));
    }

    /** Catat triase. */
    public function triage(Request $request, ClinicVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        $validated = $request->validate([
            'priority' => ['nullable', Rule::in(Priority::values())],
            'chief_complaint' => ['nullable', 'string', 'max:255'],
            'pain_score' => ['nullable', 'integer', 'min:0', 'max:10'],
            'consciousness' => ['nullable', Rule::in(Consciousness::values())],
            'general_condition' => ['nullable', Rule::in(GeneralCondition::values())],
            'duration' => ['nullable', 'string', 'max:60'],
            'onset' => ['nullable', Rule::in(['SUDDEN', 'GRADUAL', 'UNKNOWN'])],
            'severity_self' => ['nullable', Rule::in(['MILD', 'MODERATE', 'SEVERE'])],
            'associated_symptoms' => ['nullable', 'array'],
            'associated_symptoms.*' => ['string', 'max:60'],
            'is_infectious_suspect' => ['nullable', 'boolean'],
            'requires_isolation' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $triage = $this->visits->recordTriage($visit, $validated, $request->user());

        return $this->successResponse($triage, 'Triase dicatat');
    }

    /** Catat tanda vital (mengembalikan peringatan ambang klinis). */
    public function vitals(Request $request, ClinicVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        $validated = $request->validate([
            'temperature' => ['nullable', 'numeric', 'between:30,45'],
            'systolic' => ['nullable', 'integer', 'between:50,300'],
            'diastolic' => ['nullable', 'integer', 'between:30,200'],
            'heart_rate' => ['nullable', 'integer', 'between:20,260'],
            'respiratory_rate' => ['nullable', 'integer', 'between:5,80'],
            'oxygen_saturation' => ['nullable', 'integer', 'between:50,100'],
            'pain_score' => ['nullable', 'integer', 'min:0', 'max:10'],
            'weight_kg' => ['nullable', 'numeric', 'between:1,300'],
            'height_cm' => ['nullable', 'numeric', 'between:30,250'],
            'blood_glucose' => ['nullable', 'numeric', 'between:10,800'],
            'blood_pressure_note' => ['nullable', 'string', 'max:60'],
            'device_ref' => ['nullable', 'string', 'max:60'],
            'measured_at' => ['nullable', 'date'],
        ]);

        $result = $this->visits->recordVitals($visit, $validated, $request->user());

        return $this->createdResponse([
            'vital' => $result['vital'],
            'warnings' => $result['warnings'],
            'note' => 'Peringatan ini bersifat indikatif dari ambang konfigurasi sekolah, bukan diagnosis.',
        ], 'Tanda vital dicatat');
    }

    /** Asesmen klinis (SOAP). */
    public function assessment(Request $request, ClinicVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        if (! $this->access->canRecordClinical($request->user())) {
            return $this->errorResponse('Anda tidak berwenang menulis asesmen klinis.', 403);
        }

        $validated = $request->validate([
            'method' => ['nullable', Rule::in(['SOAP', 'FREE_TEXT'])],
            'subjective' => ['nullable', 'string', 'max:5000'],
            'objective' => ['nullable', 'string', 'max:5000'],
            'assessment' => ['nullable', 'string', 'max:5000'],
            'plan' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $reason = $validated['reason'] ?? null;
        unset($validated['reason']);

        $assessment = $this->records->saveAssessment($visit, $validated, $request->user(), $reason);

        return $this->createdResponse($assessment, 'Asesmen klinis disimpan (versi '.$assessment->version.')');
    }

    /** Tambah diagnosis. */
    public function diagnosis(Request $request, ClinicVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        if (! $this->access->canDiagnose($request->user())) {
            return $this->errorResponse('Hanya tenaga medis berwenang yang dapat menegakkan diagnosis.', 403);
        }

        $validated = $request->validate([
            'diagnosis_code' => ['nullable', 'string', 'max:24'],
            'diagnosis_name' => ['nullable', 'string', 'max:191', 'required_without:diagnosis_code'],
            'type' => ['nullable', Rule::in(DiagnosisType::values())],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $diagnosis = $this->records->addDiagnosis($visit, $validated, $request->user());

        return $this->createdResponse($diagnosis, 'Diagnosis ditambahkan');
    }

    /** Koreksi diagnosis (membuat versi baru, versi lama tersimpan). */
    public function amendDiagnosis(Request $request, ClinicDiagnosis $diagnosis): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $diagnosis->patient_id ?? (int) $diagnosis->visit->patient_id);

        $validated = $request->validate([
            'diagnosis_code' => ['nullable', 'string', 'max:24'],
            'diagnosis_name' => ['nullable', 'string', 'max:191'],
            'type' => ['nullable', Rule::in(DiagnosisType::values())],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $reason = $validated['reason'];
        unset($validated['reason']);

        $new = $this->records->amendDiagnosis($diagnosis, $validated, $reason, $request->user());

        return $this->successResponse($new, 'Diagnosis dikoreksi menjadi versi '.$new->version);
    }

    /** Catat tindakan. */
    public function procedure(Request $request, ClinicVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        $validated = $request->validate([
            'type_id' => ['nullable', 'integer', 'exists:clinic_procedure_types,id'],
            'procedure' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'supplies' => ['nullable', 'array'],
            'supplies.*.name' => ['required_with:supplies', 'string', 'max:120'],
            'supplies.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'consent_note' => ['nullable', 'string', 'max:500'],
        ]);

        $procedure = $this->records->addProcedure($visit, $validated, $request->user());

        return $this->createdResponse($procedure, 'Tindakan dicatat');
    }

    /** Mulai observasi. */
    public function startObservation(Request $request, ClinicVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        $validated = $request->validate([
            'location' => ['nullable', 'string', 'max:120'],
            'bed' => ['nullable', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:191'],
            'started_at' => ['nullable', 'date'],
            'supervisor_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $observation = $this->records->startObservation($visit, $validated, $request->user());

        return $this->createdResponse($observation, 'Observasi dimulai');
    }

    /** Catat kondisi berkala selama observasi. */
    public function observationRecord(Request $request, ClinicObservation $observation): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $observation->patient_id);

        $validated = $request->validate([
            'condition' => ['nullable', Rule::in(ObservationCondition::values())],
            'notes' => ['nullable', 'string', 'max:2000'],
            'action' => ['nullable', 'string', 'max:2000'],
            'recorded_at' => ['nullable', 'date'],
            'vitals' => ['nullable', 'array'],
            'vitals.temperature' => ['nullable', 'numeric', 'between:30,45'],
            'vitals.heart_rate' => ['nullable', 'integer', 'between:20,260'],
            'vitals.oxygen_saturation' => ['nullable', 'integer', 'between:50,100'],
            'vitals.systolic' => ['nullable', 'integer', 'between:50,300'],
            'vitals.diastolic' => ['nullable', 'integer', 'between:30,200'],
        ]);

        $record = $this->records->recordObservation($observation, $validated, $request->user());

        return $this->createdResponse($record, 'Catatan observasi ditambahkan');
    }

    /** Akhiri observasi. */
    public function endObservation(Request $request, ClinicObservation $observation): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $observation->patient_id);

        $validated = $request->validate([
            'status' => ['required', Rule::in([ObservationStatus::COMPLETED->value, ObservationStatus::ESCALATED->value, ObservationStatus::REFERRED->value])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $ended = $this->records->endObservation(
            $observation,
            ObservationStatus::from($validated['status']),
            $request->user(),
            $validated['notes'] ?? null,
        );

        return $this->successResponse($ended, 'Observasi diakhiri');
    }

    /** Buat tindak lanjut. */
    public function followUp(Request $request, ClinicVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        $validated = $request->validate([
            'due_date' => ['required', 'date', 'after_or_equal:today'],
            'type' => ['nullable', 'string', 'max:24'],
            'instruction' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $followUp = $this->records->createFollowUp($validated + [
            'patient_id' => $visit->patient_id,
            'visit_id' => $visit->id,
        ], $request->user());

        return $this->createdResponse($followUp, 'Tindak lanjut dijadwalkan');
    }

    /** Pemulangan pasien. */
    public function discharge(Request $request, ClinicVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        $validated = $request->validate([
            'status' => ['required', Rule::in(DischargeStatus::values())],
            'condition' => ['nullable', Rule::in(DischargeCondition::values())],
            'instructions' => ['nullable', 'string', 'max:3000'],
            'follow_up_required' => ['nullable', 'boolean'],
            'follow_up_date' => ['nullable', 'date', 'required_if:follow_up_required,true'],
            'discharge_time' => ['nullable', 'date'],
        ]);

        $result = $this->discharges->discharge($visit, $validated, $request->user());

        return $this->createdResponse([
            'discharge' => $result['discharge'],
            'attendance' => $result['attendance'],
            'boarding' => $result['boarding'],
            'note' => 'Presensi & status asrama hanya berubah bila kebijakan sekolah mengizinkannya.',
        ], 'Pasien dipulangkan');
    }

    /** Koreksi pemulangan. */
    public function amendDischarge(Request $request, \App\Modules\Clinic\Models\ClinicDischarge $discharge): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $discharge->patient_id);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(DischargeStatus::values())],
            'condition' => ['nullable', Rule::in(DischargeCondition::values())],
            'instructions' => ['nullable', 'string', 'max:3000'],
            'follow_up_required' => ['nullable', 'boolean'],
            'follow_up_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $reason = $validated['reason'];
        unset($validated['reason']);

        $new = $this->discharges->amend($discharge, $validated, $reason, $request->user());

        return $this->successResponse($new, 'Pemulangan dikoreksi menjadi versi '.$new->version);
    }

    /** Ubah status kunjungan (mis. batal). */
    public function updateStatus(Request $request, ClinicVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        $validated = $request->validate([
            'status' => ['required', Rule::in(VisitStatus::values())],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = $this->visits->advanceStatus($visit, VisitStatus::from($validated['status']), $request->user());

        if ($validated['status'] === VisitStatus::CANCELLED->value) {
            $this->visits->close($updated, VisitStatus::CANCELLED, $request->user());
            $updated = $updated->fresh();
        }

        return $this->successResponse($updated, 'Status kunjungan diperbarui');
    }

    /**
     * Payload workspace: header pasien + seluruh bagian klinis.
     *
     * @return array<string, mixed>
     */
    private function workspace(ClinicVisit $visit, \App\Models\User $user): array
    {
        $visit->load([
            'patient.student',
            'clinic:id,code,name,type,location',
            'complaint:id,name,category',
            'provider:id,name',
            'registrar:id,name',
            'triage',
            'queue',
            'vitals',
            'assessments' => fn ($q) => $q->orderByDesc('version'),
            'diagnoses',
            'procedures.type:id,name',
            'observations.records',
            'prescriptions.items.medication:id,name,unit,dosage_form',
            'administrations',
            'referrals',
            'followUps',
            'emergencies',
            'injuries',
            'discharge',
            'certificates:id,certificate_number,type,status,issued_at,patient_id,visit_id',
        ]);

        $patient = $visit->patient;
        $detailed = $this->access->isClinicalStaff($user) || $user->hasAnyRole(['super-admin', 'admin-sekolah']);

        $header = [
            'patient_id' => $patient?->id,
            'medical_record_number' => $patient?->medical_record_number,
            'patient_type' => $patient?->patient_type,
            'identity' => $this->patients->profile($patient, false)['identity'] ?? null,
            'blood_type' => $patient?->blood_type ?? $visit->patient?->blood_type,
            // Penanda ringkas untuk lencana di UI; isinya tetap daftar lengkap di bawah.
            'alerts' => [
                'allergy' => (bool) ($patient?->allergy_alert),
                'special_care' => (bool) ($patient?->special_care_alert),
            ],
            'allergies' => $patient?->activeAllergies()->get()->map(fn ($a): array => [
                'id' => $a->id,
                'allergen' => $a->allergen,
                'category' => $a->category,
                'severity' => $a->severity,
                'reaction' => $a->reaction,
                'is_critical' => $a->isCritical(),
            ])->all() ?? [],
            'special_conditions' => $patient?->activeSpecialConditions()->get()->map(fn ($c): array => [
                'id' => $c->id,
                'category' => $c->category,
                'condition' => $c->condition,
                'care_instruction' => $c->care_instruction,
            ])->all() ?? [],
            'emergency_contact' => [
                'name' => $patient?->emergency_contact_name,
                'phone' => $patient?->emergency_contact_phone,
                'relation' => $patient?->emergency_contact_relation,
            ],
            'guardians' => $patient?->student_id === null ? [] : app(\App\Modules\Clinic\Domain\Contracts\ParentProviderInterface::class)
                ->guardiansForStudent((int) $patient->student_id),
        ];

        if ($patient?->student_id !== null) {
            $header['boarding'] = $this->boarding->placementForStudent((int) $patient->student_id);
        }

        $payload = [
            'visit' => [
                'id' => $visit->id,
                'visit_number' => $visit->visit_number,
                'visit_at' => $visit->visit_at?->toIso8601String(),
                'visit_type' => $visit->visit_type,
                'visit_type_label' => $visit->typeEnum()->label(),
                'source' => $visit->source,
                'source_label' => $visit->sourceEnum()->label(),
                'priority' => $visit->priority,
                'priority_label' => $visit->priorityEnum()->label(),
                'status' => $visit->status,
                'status_label' => $visit->statusEnum()->label(),
                'is_emergency' => (bool) $visit->is_emergency,
                'requires_observation' => (bool) $visit->requires_observation,
                'parent_notified' => (bool) $visit->parent_notified,
                'clinic' => $visit->clinic?->only(['id', 'code', 'name', 'location']),
                'provider' => $visit->provider?->only(['id', 'name']),
                'registrar' => $visit->registrar?->only(['id', 'name']),
                'complaint' => $visit->complaint?->only(['id', 'name', 'category']),
                'chief_complaint' => $visit->chief_complaint,
                'notes' => $visit->notes,
                'closed_at' => $visit->closed_at?->toIso8601String(),
                'can_be_written' => $visit->isOpen(),
            ],
            'patient_header' => $header,
            'queue' => $visit->queue,
            'triage' => $visit->triage,
            'vitals' => $visit->vitals->sortByDesc('measured_at')->values(),
            'assessments' => $visit->assessments,
            'current_assessment' => $visit->assessments->firstWhere('is_current', true),
            'diagnoses' => $visit->diagnoses,
            'procedures' => $visit->procedures,
            'observations' => $visit->observations,
            'prescriptions' => $visit->prescriptions,
            'administrations' => $visit->administrations,
            'referrals' => $visit->referrals,
            'follow_ups' => $visit->followUps,
            'emergencies' => $visit->emergencies,
            'injuries' => $visit->injuries,
            'discharge' => $visit->discharge,
            'certificates' => $detailed ? $visit->certificates : [],
            // Dokumen kesehatan melekat pada pasien (bukan pada satu kunjungan),
            // jadi yang ditampilkan adalah dokumen pasien yang masih berlaku.
            'documents' => $detailed
                ? $patient?->documents()->where('is_current', true)->orderByDesc('id')->limit(20)->get()
                    ->map(fn ($document): array => [
                        'id' => $document->id,
                        'document_number' => $document->document_number,
                        'type' => $document->type,
                        'title' => $document->title,
                        'visibility' => $document->visibility,
                        'version' => $document->version,
                    ])->all()
                : [],
            'timeline' => $this->timeline($visit),
        ];

        // Bagian berkas klinis hanya untuk petugas; orang tua/siswa menerima
        // ringkasan operasional dari endpoint portal, bukan workspace penuh.
        if (! $detailed) {
            $payload['assessments'] = [];
            $payload['current_assessment'] = null;
            $payload['procedures'] = [];
            $payload['observations'] = [];
            $payload['emergencies'] = [];
            $payload['injuries'] = [];
            $payload['timeline'] = [];
        }

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    private function timeline(ClinicVisit $visit): array
    {
        $events = collect();

        $events->push(['at' => $visit->visit_at?->toIso8601String(), 'type' => 'REGISTERED', 'label' => 'Kunjungan terdaftar']);

        if ($visit->triage !== null) {
            $events->push(['at' => $visit->triage->triaged_at?->toIso8601String(), 'type' => 'TRIAGE', 'label' => 'Triase: '.$visit->triage->chief_complaint]);
        }

        foreach ($visit->vitals as $vital) {
            $events->push(['at' => $vital->measured_at?->toIso8601String(), 'type' => 'VITALS', 'label' => 'Tanda vital dicatat']);
        }

        foreach ($visit->assessments as $assessment) {
            $events->push(['at' => $assessment->assessment_at?->toIso8601String(), 'type' => 'ASSESSMENT', 'label' => 'Asesmen v'.$assessment->version]);
        }

        foreach ($visit->diagnoses as $diagnosis) {
            $events->push(['at' => $diagnosis->diagnosed_at?->toIso8601String(), 'type' => 'DIAGNOSIS', 'label' => $diagnosis->diagnosis_name]);
        }

        foreach ($visit->procedures as $procedure) {
            $events->push(['at' => $procedure->performed_at?->toIso8601String(), 'type' => 'PROCEDURE', 'label' => $procedure->procedure]);
        }

        foreach ($visit->prescriptions as $prescription) {
            $events->push(['at' => $prescription->prescribed_at?->toIso8601String(), 'type' => 'PRESCRIPTION', 'label' => 'Resep '.$prescription->prescription_number]);
        }

        foreach ($visit->administrations as $administration) {
            $events->push(['at' => $administration->administered_at?->toIso8601String(), 'type' => 'MEDICATION', 'label' => $administration->medication_name.' — '.$administration->status]);
        }

        foreach ($visit->referrals as $referral) {
            $events->push(['at' => $referral->referral_date?->toIso8601String(), 'type' => 'REFERRAL', 'label' => 'Rujukan ke '.$referral->facility]);
        }

        if ($visit->discharge !== null) {
            $events->push(['at' => $visit->discharge->discharge_time?->toIso8601String(), 'type' => 'DISCHARGE', 'label' => 'Pemulangan: '.$visit->discharge->statusEnum()->label()]);
        }

        return $events->filter(fn (array $event): bool => $event['at'] !== null)
            ->sortBy('at')
            ->values()
            ->all();
    }
}
