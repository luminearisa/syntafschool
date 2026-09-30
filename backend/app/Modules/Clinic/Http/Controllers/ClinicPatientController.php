<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Clinic\Application\Services\ClinicAccessService;
use App\Modules\Clinic\Application\Services\ClinicAuditService;
use App\Modules\Clinic\Application\Services\PatientService;
use App\Modules\Clinic\Domain\Contracts\StudentProviderInterface;
use App\Modules\Clinic\Domain\Enums\AllergyCategory;
use App\Modules\Clinic\Domain\Enums\AllergySeverity;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Domain\Enums\DisclosureStatus;
use App\Modules\Clinic\Domain\Enums\HistoryCategory;
use App\Modules\Clinic\Domain\Enums\PatientType;
use App\Modules\Clinic\Domain\Enums\SpecialConditionCategory;
use App\Modules\Clinic\Models\ClinicAllergy;
use App\Modules\Clinic\Models\ClinicPatient;
use App\Modules\Clinic\Models\ClinicSpecialCondition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pasien & rekam medis.
 *
 * Dua aturan yang tidak boleh dilanggar di sini:
 *
 * 1. **Setiap pembukaan rekam medis dicatat** (VIEW) — jejak audit privasi.
 * 2. **Setiap baris diperiksa kepemilikannya** lewat ClinicAccessService;
 *    memanggil `records/{id}` untuk pasien orang lain berakhir 403, bukan data.
 */
class ClinicPatientController extends BaseApiController
{
    public function __construct(
        private readonly PatientService $patients,
        private readonly ClinicAccessService $access,
        private readonly ClinicAuditService $audit,
        private readonly StudentProviderInterface $students,
    ) {
    }

    /** Daftar/pencarian pasien dalam cakupan pengguna. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $term = $request->query('search');
        $limit = min(100, max(1, (int) $request->query('limit', 25)));

        $rows = $this->access->visiblePatients($user, is_string($term) ? $term : null, $limit);

        return $this->successResponse([
            'rows' => $rows->map(fn (ClinicPatient $patient): array => [
                'patient_id' => $patient->id,
                'medical_record_number' => $patient->medical_record_number,
                'patient_type' => $patient->patient_type,
                'status' => $patient->status,
                'student' => $patient->student === null ? null : [
                    'id' => $patient->student->id,
                    'nis' => $patient->student->nis,
                    'name' => $patient->student->full_name,
                    'birth_date' => $patient->student->birth_date?->toDateString(),
                ],
                'alerts' => [
                    'allergy' => (bool) $patient->allergy_alert,
                    'special_care' => (bool) $patient->special_care_alert,
                ],
                'last_visit_at' => $patient->latestVisit?->visit_at?->toIso8601String()
                    ?? \App\Modules\Clinic\Models\ClinicVisit::query()->where('patient_id', $patient->id)->max('visit_at'),
            ])->all(),
            'total_visible' => $this->access->visiblePatientCount($user),
        ]);
    }

    /** Pencarian siswa untuk pendaftaran pasien baru (langsung ke master ERP). */
    public function searchStudents(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:60'],
        ]);

        $results = $this->students->search($validated['q'], 20);

        // Tandai mana yang sudah punya rekam medis agar pendaftaran tidak dobel.
        $existing = ClinicPatient::query()
            ->whereIn('student_id', array_column($results, 'id'))
            ->pluck('medical_record_number', 'student_id');

        return $this->successResponse(array_map(function (array $row) use ($existing): array {
            $row['has_medical_record'] = isset($existing[$row['id']]);
            $row['medical_record_number'] = $existing[$row['id']] ?? null;

            return $row;
        }, $results));
    }

    /** Daftarkan rekam medis pasien (idempotent per siswa). */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'patient_type' => ['nullable', Rule::in(PatientType::values())],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:40'],
        ]);

        if (empty($validated['student_id']) && empty($validated['user_id'])) {
            return $this->validationErrorResponse(['student_id' => 'Pilih siswa atau pengguna sebagai pasien.']);
        }

        $actor = $request->user();

        if (! empty($validated['student_id'])) {
            $patient = $this->patients->forStudent((int) $validated['student_id'], $actor);
        } else {
            $user = \App\Models\User::query()->findOrFail($validated['user_id']);
            $patient = $this->patients->forUser(
                $user,
                PatientType::from($validated['patient_type'] ?? PatientType::STAFF->value),
                $actor,
            );
        }

        $contact = array_filter([
            'emergency_contact_name' => $validated['emergency_contact_name'] ?? null,
            'emergency_contact_phone' => $validated['emergency_contact_phone'] ?? null,
            'emergency_contact_relation' => $validated['emergency_contact_relation'] ?? null,
        ], fn ($value): bool => $value !== null);

        if ($contact !== []) {
            $patient = $this->patients->updateContact($patient, $contact, $actor);
        }

        return $this->createdResponse($this->patients->profile($patient), 'Rekam medis pasien siap');
    }

    /** Profil kesehatan lengkap (rekam medis). */
    public function show(Request $request, ClinicPatient $patient): JsonResponse
    {
        $user = $request->user();
        $this->access->assertCanSeePatientModel($user, $patient);
        $this->audit->recordView($patient, (int) $patient->id, $user, 'Membuka profil kesehatan pasien');

        // Detail klinis hanya untuk petugas klinik; orang tua menerima ringkasan.
        $detailed = $this->access->isClinicalStaff($user) || $user->hasAnyRole(['super-admin', 'admin-sekolah']);

        return $this->successResponse($this->patients->profile($patient, $detailed));
    }

    /** Perbarui kontak darurat/asuransi. */
    public function update(Request $request, ClinicPatient $patient): JsonResponse
    {
        $this->access->assertCanSeePatientModel($request->user(), $patient);

        $validated = $request->validate([
            'blood_type' => ['nullable', 'string', 'max:4'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:40'],
            'insurance_provider' => ['nullable', 'string', 'max:120'],
            'insurance_number' => ['nullable', 'string', 'max:60'],
        ]);

        $updated = $this->patients->updateContact($patient, $validated, $request->user());

        return $this->successResponse($this->patients->profile($updated), 'Data pasien diperbarui');
    }

    /** Grafik tanda vital (timeline) untuk layar riwayat. */
    public function vitals(Request $request, ClinicPatient $patient): JsonResponse
    {
        $this->access->assertCanSeePatientModel($request->user(), $patient);

        $rows = $patient->vitals()
            ->orderByDesc('measured_at')
            ->limit((int) $request->query('limit', 50))
            ->get()
            ->map(fn ($vital): array => [
                'id' => $vital->id,
                'measured_at' => $vital->measured_at->toIso8601String(),
                'temperature' => $vital->temperature === null ? null : (float) $vital->temperature,
                'systolic' => $vital->systolic,
                'diastolic' => $vital->diastolic,
                'heart_rate' => $vital->heart_rate,
                'respiratory_rate' => $vital->respiratory_rate,
                'oxygen_saturation' => $vital->oxygen_saturation,
                'weight_kg' => $vital->weight_kg === null ? null : (float) $vital->weight_kg,
                'height_cm' => $vital->height_cm === null ? null : (float) $vital->height_cm,
                'bmi' => $vital->bmi === null ? null : (float) $vital->bmi,
                'bmi_category' => $vital->bmi_category,
                'pain_score' => $vital->pain_score,
            ]);

        return $this->successResponse($rows);
    }

    /** Tambah alergi. */
    public function storeAllergy(Request $request, ClinicPatient $patient): JsonResponse
    {
        $this->access->assertCanSeePatientModel($request->user(), $patient);

        $validated = $request->validate([
            'category' => ['nullable', Rule::in(AllergyCategory::values())],
            'allergen' => ['required', 'string', 'max:120'],
            'reaction' => ['nullable', 'string', 'max:191'],
            'severity' => ['nullable', Rule::in(AllergySeverity::values())],
            'medication_id' => ['nullable', 'integer', 'exists:clinic_medications,id'],
            'identified_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $allergy = $this->patients->addAllergy($patient, $validated, $request->user());

        return $this->createdResponse($allergy, 'Alergi pasien dicatat');
    }

    /** Ubah status alergi (selesai/dibantah). */
    public function resolveAllergy(Request $request, ClinicAllergy $allergy): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $allergy->patient_id);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'RESOLVED', 'REFUTED'])],
        ]);

        return $this->successResponse(
            $this->patients->resolveAllergy($allergy, $request->user(), $validated['status']),
            'Status alergi diperbarui',
        );
    }

    /** Tambah kondisi khusus (pantangan makan, pembatasan aktivitas, instruksi darurat). */
    public function storeCondition(Request $request, ClinicPatient $patient): JsonResponse
    {
        $this->access->assertCanSeePatientModel($request->user(), $patient);

        $validated = $request->validate([
            'category' => ['required', Rule::in(SpecialConditionCategory::values())],
            'condition' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'care_instruction' => ['nullable', 'string', 'max:2000'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'visible_to_kitchen' => ['nullable', 'boolean'],
            'visible_to_boarding' => ['nullable', 'boolean'],
        ]);

        $condition = $this->patients->addSpecialCondition($patient, $validated, $request->user());

        return $this->createdResponse($condition, 'Kondisi khusus dicatat');
    }

    /** Tambah riwayat medis. */
    public function storeHistory(Request $request, ClinicPatient $patient): JsonResponse
    {
        $this->access->assertCanSeePatientModel($request->user(), $patient);

        $validated = $request->validate([
            'category' => ['required', Rule::in(HistoryCategory::values())],
            'title' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'year' => ['nullable', 'string', 'max:12'],
            'disclosure' => ['nullable', Rule::in(DisclosureStatus::values())],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $history = $this->patients->addMedicalHistory($patient, $validated, $request->user());

        return $this->createdResponse($history, 'Riwayat medis dicatat');
    }

    /**
     * Informasi operasional minimum untuk dapur & asrama: hanya instruksi
     * (pantangan makan, perawatan khusus), tanpa diagnosis atau catatan klinis.
     */
    public function operationalNotes(Request $request, ClinicPatient $patient): JsonResponse
    {
        $this->access->assertCanSeePatientModel($request->user(), $patient);

        $rows = $patient->specialConditions()
            ->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->where('visible_to_kitchen', true)->orWhere('visible_to_boarding', true))
            ->get()
            ->map(fn (ClinicSpecialCondition $condition): array => [
                'category' => $condition->category,
                'condition' => $condition->condition,
                'care_instruction' => $condition->care_instruction,
                'visible_to_kitchen' => (bool) $condition->visible_to_kitchen,
                'visible_to_boarding' => (bool) $condition->visible_to_boarding,
            ]);

        return $this->successResponse([
            'student_id' => $patient->student_id,
            'notes' => $rows,
            'disclaimer' => 'Informasi ini hanya instruksi operasional. Diagnosis dan catatan klinis tidak dibagikan ke dapur/asrama.',
        ]);
    }

    /** Riwayat kunjungan pasien (ringkas). */
    public function visits(Request $request, ClinicPatient $patient): JsonResponse
    {
        $this->access->assertCanSeePatientModel($request->user(), $patient);
        $this->audit->recordView($patient, (int) $patient->id, $request->user(), 'Membuka riwayat kunjungan');

        $rows = $patient->visits()
            ->with(['triage:id,visit_id,priority,chief_complaint', 'primaryDiagnosis', 'discharge:id,visit_id,status'])
            ->orderByDesc('visit_at')
            ->limit((int) $request->query('limit', 50))
            ->get();

        $visibleDiagnosis = (bool) config('clinic.privacy.parent_visible_diagnosis', true);
        $detailed = $this->access->isClinicalStaff($request->user());

        return $this->successResponse($rows->map(fn ($visit): array => [
            'id' => $visit->id,
            'visit_number' => $visit->visit_number,
            'visit_at' => $visit->visit_at?->toIso8601String(),
            'visit_type' => $visit->visit_type,
            'status' => $visit->status,
            'status_label' => $visit->statusEnum()->label(),
            'priority' => $visit->priority,
            'chief_complaint' => $visit->triage?->chief_complaint ?? $visit->chief_complaint,
            'diagnosis' => ($detailed || $visibleDiagnosis) ? $visit->primaryDiagnosis?->diagnosis_name : null,
            'discharge_status' => $visit->discharge?->status,
            'notes' => ($detailed && (bool) config('clinic.privacy.parent_visible_notes', false)) ? $visit->notes : null,
        ])->all());
    }
}
