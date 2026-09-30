<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Clinic\Application\Services\ClinicAccessService;
use App\Modules\Clinic\Application\Services\ClinicAuditService;
use App\Modules\Clinic\Application\Services\ClinicHealthService;
use App\Modules\Clinic\Application\Services\ClinicReportService;
use App\Modules\Clinic\Domain\Contracts\AssetProviderInterface;
use App\Modules\Clinic\Domain\Contracts\DiagnosisCodeProviderInterface;
use App\Modules\Clinic\Domain\Enums\AllergyCategory;
use App\Modules\Clinic\Domain\Enums\AllergySeverity;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Domain\Enums\CertificateType;
use App\Modules\Clinic\Domain\Enums\DischargeCondition;
use App\Modules\Clinic\Domain\Enums\DischargeStatus;
use App\Modules\Clinic\Domain\Enums\DosageForm;
use App\Modules\Clinic\Domain\Enums\HistoryCategory;
use App\Modules\Clinic\Domain\Enums\InjurySeverity;
use App\Modules\Clinic\Domain\Enums\MedicationRoute;
use App\Modules\Clinic\Domain\Enums\Priority;
use App\Modules\Clinic\Domain\Enums\ProcedureCategory;
use App\Modules\Clinic\Domain\Enums\ReferralUrgency;
use App\Modules\Clinic\Domain\Enums\ScreeningType;
use App\Modules\Clinic\Domain\Enums\SpecialConditionCategory;
use App\Modules\Clinic\Domain\Enums\StaffRole;
use App\Modules\Clinic\Domain\Enums\VisitSource;
use App\Modules\Clinic\Domain\Enums\VisitType;
use App\Modules\Clinic\Models\ClinicComplaint;
use App\Modules\Clinic\Models\ClinicDiagnosisMaster;
use App\Modules\Clinic\Models\ClinicLocation;
use App\Modules\Clinic\Models\ClinicProcedureType;
use App\Modules\Clinic\Models\ClinicScreeningProgram;
use App\Modules\Clinic\Models\ClinicStaff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Konteks modul: pilihan master data, dashboard, dan pemeriksaan kesehatan.
 *
 * Endpoint `metadata` sengaja mengirim seluruh daftar pilihan sekaligus agar
 * layar klinis dapat menampilkan formulir tanpa puluhan permintaan tambahan —
 * penting saat petugas bekerja di tablet dengan jaringan sekolah.
 */
class ClinicMetaController extends BaseApiController
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly ClinicReportService $reports,
        private readonly ClinicHealthService $health,
        private readonly DiagnosisCodeProviderInterface $diagnosisCodes,
        private readonly AssetProviderInterface $assets,
    ) {
    }

    /** Siapa saya di modul ini: peran klinis, cakupan, dan izin efektif. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $staff = $this->access->staffFor($user);

        return $this->successResponse([
            'staff' => $staff === null ? null : [
                'id' => $staff->id,
                'clinic' => $staff->clinic?->only(['id', 'code', 'name', 'type']),
                'role' => $staff->role,
                'role_label' => $staff->roleEnum()->label(),
                'license_number' => $staff->license_number,
                'specialization' => $staff->specialization,
            ],
            'capabilities' => [
                'view_all' => $this->access->canViewAll($user),
                'record_clinical' => $this->access->canRecordClinical($user),
                'diagnose' => $this->access->canDiagnose($user),
                'prescribe' => $this->access->canPrescribe($user),
                'dispense' => $this->access->canDispense($user),
                'export' => $this->access->canExport($user),
                'parent_portal' => $this->access->isParentOnly($user) || $this->access->isStudentOnly($user),
            ],
            'scope' => [
                'visible_patients' => $this->access->visiblePatientCount($user),
                'children' => $this->access->childStudentIdsFor($user),
            ],
        ]);
    }

    /** Seluruh pilihan master data untuk formulir klinis. */
    public function metadata(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse([
            'clinics' => ClinicLocation::query()
                ->active()
                ->get(['id', 'code', 'name', 'type', 'location', 'bed_capacity', 'supports_emergency', 'supports_observation', 'is_default']),
            'complaints' => ClinicComplaint::query()->active()->get(['id', 'code', 'name', 'category', 'is_infectious']),
            'procedure_types' => ClinicProcedureType::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'code', 'name', 'category', 'requires_consent', 'is_billable']),
            'diagnosis_coding_system' => $this->diagnosisCodes->systemName(),
            'diagnosis_common' => ClinicDiagnosisMaster::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->limit(40)
                ->get(['code', 'name', 'category', 'is_infectious']),
            'screening_programs' => ClinicScreeningProgram::query()
                ->whereIn('status', ['ACTIVE', 'DRAFT'])
                ->orderByDesc('id')
                ->get(['id', 'code', 'name', 'type', 'status', 'period_start', 'period_end']),
            'staff' => ClinicStaff::query()
                ->with('user:id,name')
                ->where('status', 'ACTIVE')
                ->get()
                ->map(fn (ClinicStaff $staff): array => [
                    'id' => $staff->id,
                    'user_id' => $staff->user_id,
                    'name' => $staff->user?->name,
                    'role' => $staff->role,
                    'role_label' => $staff->roleEnum()->label(),
                    'clinic_id' => $staff->clinic_id,
                ]),
            'equipment' => $this->access->canRecordClinical($user) ? $this->assets->equipmentForClinic(null, 30) : [],
            'options' => [
                'staff_roles' => StaffRole::options(),
                'visit_types' => VisitType::options(),
                'visit_sources' => VisitSource::options(),
                'priorities' => Priority::options(),
                'procedures' => ProcedureCategory::options(),
                'allergy_categories' => AllergyCategory::options(),
                'allergy_severities' => AllergySeverity::options(),
                'history_categories' => HistoryCategory::options(),
                'special_conditions' => SpecialConditionCategory::options(),
                'dosage_forms' => DosageForm::options(),
                'medication_routes' => MedicationRoute::options(),
                'screening_types' => ScreeningType::options(),
                'certificate_types' => CertificateType::options(),
                'discharge_statuses' => DischargeStatus::options(),
                'discharge_conditions' => DischargeCondition::options(),
                'referral_urgencies' => ReferralUrgency::options(),
                'injury_severities' => InjurySeverity::options(),
            ],
        ]);
    }

    /** Dashboard klinik sesuai cakupan peran. */
    public function dashboard(Request $request): JsonResponse
    {
        app(ClinicAuditService::class)->record(
            AuditAction::VIEW,
            null,
            null,
            $request->user(),
            ['screen' => 'dashboard'],
            [],
            'Membuka dashboard klinik',
        );

        return $this->successResponse($this->reports->dashboard($request->user()));
    }

    /** Pemeriksaan kesiapan modul & integrasi — tanpa data klinis. */
    public function health(): JsonResponse
    {
        $report = $this->health->report();

        return $this->successResponse($report, 'Status modul Clinic: '.$report['status'], $report['status'] === 'OK' ? 200 : 503);
    }

    /**
     * Ringkasan profil kesehatan untuk pemilih pasien: peringatan alergi ikut
     * dibawa agar petugas tahu sebelum pasien dibuka.
     */
    public function patientAlerts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_ids' => ['required', 'array', 'max:50'],
            'patient_ids.*' => ['integer'],
        ]);

        $rows = \App\Modules\Clinic\Models\ClinicPatient::query()
            ->whereIn('id', $validated['patient_ids'])
            ->get()
            ->map(fn ($patient): array => [
                'patient_id' => $patient->id,
                'medical_record_number' => $patient->medical_record_number,
                'allergy_alert' => (bool) $patient->allergy_alert,
                'special_care_alert' => (bool) $patient->special_care_alert,
            ]);

        return $this->successResponse($rows);
    }
}
