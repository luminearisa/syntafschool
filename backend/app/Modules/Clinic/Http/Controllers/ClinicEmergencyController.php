<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Clinic\Application\Services\ClinicAccessService;
use App\Modules\Clinic\Application\Services\EmergencyService;
use App\Modules\Clinic\Application\Services\InjuryService;
use App\Modules\Clinic\Domain\Enums\EmergencyStatus;
use App\Modules\Clinic\Domain\Enums\InjurySeverity;
use App\Modules\Clinic\Domain\Enums\Priority;
use App\Modules\Clinic\Domain\Enums\VisitSource;
use App\Modules\Clinic\Models\ClinicEmergency;
use App\Modules\Clinic\Models\ClinicInjury;
use App\Modules\Clinic\Models\ClinicPatient;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gawat darurat & cedera.
 *
 * Alur gawat darurat sengaja bisa dibuka **dari kunjungan mana pun** dan juga
 * dari pasien langsung: keadaan darurat tidak menunggu administrasi lengkap
 * (Rule 11). Yang dipastikan adalah jejaknya: siapa menangani, kapan, apa yang
 * dilakukan, apakah orang tua sudah dihubungi, dan bagaimana akhirnya.
 */
class ClinicEmergencyController extends BaseApiController
{
    public function __construct(
        private readonly EmergencyService $emergencies,
        private readonly InjuryService $injuries,
        private readonly ClinicAccessService $access,
    ) {
    }

    /** Daftar peristiwa gawat darurat dalam cakupan pengguna. */
    public function index(Request $request): JsonResponse
    {
        $ids = $this->access->visiblePatientIds($request->user());

        $query = ClinicEmergency::query()
            ->with(['patient.student:id,nis,full_name', 'visit:id,visit_number'])
            ->when($ids !== null, fn ($q) => $q->whereIn('patient_id', $ids === [] ? [-1] : $ids))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('open_only'), fn ($q) => $q->whereIn('status', ['OPEN', 'STABILIZED', 'REFERRED']))
            ->orderByDesc('incident_at');

        return $this->successResponse($query->limit(100)->get());
    }

    /** Buka peristiwa gawat darurat; membuat kunjungan darurat bila belum ada. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['nullable', 'integer', 'exists:clinic_patients,id'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'visit_id' => ['nullable', 'integer', 'exists:clinic_visits,id'],
            'severity' => ['nullable', Rule::in(Priority::values())],
            'incident_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:3000'],
            'action_taken' => ['nullable', 'string', 'max:3000'],
            'stabilization' => ['nullable', 'string', 'max:3000'],
            'parent_contacted' => ['nullable', 'boolean'],
            'contacted_guardian_id' => ['nullable', 'integer', 'exists:guardians,id'],
            'referral_required' => ['nullable', 'boolean'],
            'ambulance_required' => ['nullable', 'boolean'],
            'hospital' => ['nullable', 'string', 'max:191'],
            'transport_method' => ['nullable', 'string', 'max:60'],
        ]);

        $actor = $request->user();
        $visit = null;

        if (! empty($validated['visit_id'])) {
            $visit = ClinicVisit::query()->findOrFail($validated['visit_id']);
            $this->access->assertCanSeeVisit($actor, $visit);
            $patient = $visit->patient;
        } elseif (! empty($validated['patient_id'])) {
            $this->access->assertCanSeePatient($actor, (int) $validated['patient_id']);
            $patient = ClinicPatient::query()->findOrFail($validated['patient_id']);
        } elseif (! empty($validated['student_id'])) {
            $patient = app(\App\Modules\Clinic\Application\Services\PatientService::class)
                ->forStudent((int) $validated['student_id'], $actor);
        } else {
            return $this->validationErrorResponse(['patient_id' => 'Pilih pasien, siswa, atau kunjungan.']);
        }

        if ($visit === null) {
            $visit = app(\App\Modules\Clinic\Application\Services\VisitService::class)->register([
                'patient_id' => $patient->id,
                'visit_type' => 'EMERGENCY',
                'source' => $validated['source'] ?? VisitSource::EMERGENCY->value,
                'chief_complaint' => mb_substr($validated['description'], 0, 255),
                'visit_at' => $validated['incident_at'] ?? now(),
            ], $actor);
        }

        $emergency = $this->emergencies->open($visit, $validated, $actor);

        return $this->createdResponse([
            'emergency' => $emergency,
            'visit_id' => $visit->id,
            'visit_number' => $visit->visit_number,
        ], 'Gawat darurat '.$emergency->incident_number.' dibuka');
    }

    public function show(Request $request, ClinicEmergency $emergency): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $emergency->patient_id);

        return $this->successResponse([
            'emergency' => $emergency->load(['patient.student', 'visit:id,visit_number,status', 'contactedGuardian:id,full_name,phone']),
            'contacts' => $this->emergencies->contactOptions($emergency),
        ]);
    }

    public function update(Request $request, ClinicEmergency $emergency): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $emergency->patient_id);

        $validated = $request->validate([
            'severity' => ['nullable', Rule::in(Priority::values())],
            'action_taken' => ['nullable', 'string', 'max:3000'],
            'stabilization' => ['nullable', 'string', 'max:3000'],
            'parent_contacted' => ['nullable', 'boolean'],
            'contacted_guardian_id' => ['nullable', 'integer', 'exists:guardians,id'],
            'referral_required' => ['nullable', 'boolean'],
            'ambulance_required' => ['nullable', 'boolean'],
            'hospital' => ['nullable', 'string', 'max:191'],
            'transport_method' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::in(EmergencyStatus::values())],
            'outcome' => ['nullable', 'string', 'max:3000'],
        ]);

        return $this->successResponse($this->emergencies->update($emergency, $validated, $request->user()), 'Penanganan diperbarui');
    }

    public function close(Request $request, ClinicEmergency $emergency): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $emergency->patient_id);

        $validated = $request->validate(['outcome' => ['required', 'string', 'max:3000']]);

        return $this->successResponse($this->emergencies->close($emergency, $validated['outcome'], $request->user()), 'Peristiwa ditutup');
    }

    // ------------------------- Cedera / kecelakaan -------------------------

    public function injuries(Request $request): JsonResponse
    {
        $ids = $this->access->visiblePatientIds($request->user());

        $rows = ClinicInjury::query()
            ->with(['patient.student:id,nis,full_name'])
            ->when($ids !== null, fn ($q) => $q->whereIn('patient_id', $ids === [] ? [-1] : $ids))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('type'), fn ($q, $type) => $q->where('injury_type', $type))
            ->orderByDesc('incident_at')
            ->limit(100)
            ->get();

        return $this->successResponse($rows);
    }

    /** Catat cedera; kunjungan INJURY dibuat otomatis bila belum ada. */
    public function storeInjury(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['nullable', 'integer', 'exists:clinic_patients,id'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'visit_id' => ['nullable', 'integer', 'exists:clinic_visits,id'],
            'emergency_id' => ['nullable', 'integer', 'exists:clinic_emergencies,id'],
            'incident_at' => ['nullable', 'date'],
            'location' => ['required', 'string', 'max:160'],
            'activity' => ['nullable', 'string', 'max:160'],
            'witnesses' => ['nullable', 'string', 'max:2000'],
            'injury_type' => ['required', 'string', 'max:40'],
            'body_part' => ['nullable', 'string', 'max:80'],
            'mechanism' => ['nullable', 'string', 'max:2000'],
            'severity' => ['nullable', Rule::in(InjurySeverity::values())],
            'treatment' => ['nullable', 'string', 'max:3000'],
            'referral_required' => ['nullable', 'boolean'],
        ]);

        $actor = $request->user();

        if (! empty($validated['patient_id'])) {
            $this->access->assertCanSeePatient($actor, (int) $validated['patient_id']);
            $patient = ClinicPatient::query()->findOrFail($validated['patient_id']);
        } elseif (! empty($validated['visit_id'])) {
            $visit = ClinicVisit::query()->findOrFail($validated['visit_id']);
            $this->access->assertCanSeeVisit($actor, $visit);
            $patient = $visit->patient;
        } elseif (! empty($validated['student_id'])) {
            $patient = app(\App\Modules\Clinic\Application\Services\PatientService::class)
                ->forStudent((int) $validated['student_id'], $actor);
        } else {
            return $this->validationErrorResponse(['patient_id' => 'Pilih pasien, siswa, atau kunjungan.']);
        }

        $injury = $this->injuries->record($patient, $validated, $actor);

        return $this->createdResponse($injury, 'Cedera '.$injury->incident_number.' dicatat');
    }

    public function updateInjury(Request $request, ClinicInjury $injury): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $injury->patient_id);

        $validated = $request->validate([
            'injury_type' => ['nullable', 'string', 'max:40'],
            'body_part' => ['nullable', 'string', 'max:80'],
            'mechanism' => ['nullable', 'string', 'max:2000'],
            'severity' => ['nullable', Rule::in(InjurySeverity::values())],
            'treatment' => ['nullable', 'string', 'max:3000'],
            'referral_required' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['OPEN', 'TREATED', 'REFERRED', 'CLOSED'])],
            'follow_up' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->successResponse($this->injuries->update($injury, $validated, $request->user()), 'Cedera diperbarui');
    }

    public function closeInjury(Request $request, ClinicInjury $injury): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $injury->patient_id);

        $validated = $request->validate(['follow_up' => ['nullable', 'string', 'max:2000']]);

        return $this->successResponse($this->injuries->close($injury, $request->user(), $validated['follow_up'] ?? null), 'Cedera ditutup');
    }
}
