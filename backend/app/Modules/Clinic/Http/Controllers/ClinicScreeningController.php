<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Clinic\Application\Services\ClinicAccessService;
use App\Modules\Clinic\Application\Services\ScreeningService;
use App\Modules\Clinic\Domain\Contracts\StudentProviderInterface;
use App\Modules\Clinic\Domain\Enums\ScreeningResult;
use App\Modules\Clinic\Domain\Enums\ScreeningType;
use App\Modules\Clinic\Models\ClinicPatient;
use App\Modules\Clinic\Models\ClinicScreening;
use App\Modules\Clinic\Models\ClinicScreeningProgram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Skrining kesehatan berkala & imunisasi.
 *
 * Skrining massal memakai siswa langsung dari master ERP (per kelas atau seluruh
 * sekolah aktif) dan menyimpan hasil per pasien. Pengiriman hasil yang sama dua
 * kali tidak menggandakan baris — penting karena petugas sering mengirim ulang
 * ketika jaringan sekolah tidak stabil.
 */
class ClinicScreeningController extends BaseApiController
{
    public function __construct(
        private readonly ScreeningService $screenings,
        private readonly ClinicAccessService $access,
        private readonly StudentProviderInterface $students,
    ) {
    }

    public function programs(Request $request): JsonResponse
    {
        return $this->successResponse(
            ClinicScreeningProgram::query()
                ->with('academicYear:id,name')
                ->orderByDesc('id')
                ->limit(100)
                ->get(),
        );
    }

    public function storeProgram(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:clinic_screening_programs,code'],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['nullable', Rule::in(ScreeningType::values())],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'status' => ['nullable', Rule::in(['DRAFT', 'ACTIVE', 'COMPLETED', 'CANCELLED'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'notify_parent' => ['nullable', 'boolean'],
        ]);

        $program = $this->screenings->createProgram($validated, $request->user());

        return $this->createdResponse($program, 'Program skrining dibuat');
    }

    /** Sasaran program: daftar siswa per kelas atau seluruh sekolah. */
    public function targets(Request $request, ClinicScreeningProgram $program): JsonResponse
    {
        $validated = $request->validate([
            'school_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'education_level_id' => ['nullable', 'integer', 'exists:education_levels,id'],
        ]);

        $studentIds = $this->screenings->targetStudents(
            $program,
            isset($validated['school_class_id']) ? (int) $validated['school_class_id'] : null,
            isset($validated['education_level_id']) ? (int) $validated['education_level_id'] : null,
        );

        $snapshots = $this->students->snapshots(array_slice($studentIds, 0, 500));

        return $this->successResponse([
            'total' => count($studentIds),
            'progress' => $this->screenings->progress($program),
            'students' => $snapshots->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $ids = $this->access->visiblePatientIds($request->user());

        $rows = ClinicScreening::query()
            ->with(['patient.student:id,nis,full_name', 'program:id,code,name'])
            ->when($ids !== null, fn ($q) => $q->whereIn('patient_id', $ids === [] ? [-1] : $ids))
            ->when($request->query('program_id'), fn ($q, $programId) => $q->where('program_id', (int) $programId))
            ->when($request->query('result'), fn ($q, $result) => $q->where('result', $result))
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('screened_at')
            ->limit(300)
            ->get();

        return $this->successResponse($rows);
    }

    /** Catat hasil skrining seorang siswa (idempotent per siswa+tanggal+jenis). */
    public function store(Request $request, ClinicScreeningProgram $program): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'type' => ['nullable', Rule::in(ScreeningType::values())],
            'screened_at' => ['nullable', 'date'],
            'height_cm' => ['nullable', 'numeric', 'between:30,250'],
            'weight_kg' => ['nullable', 'numeric', 'between:1,300'],
            'vision_left' => ['nullable', 'string', 'max:12'],
            'vision_right' => ['nullable', 'string', 'max:12'],
            'hearing_left' => ['nullable', 'string', 'max:24'],
            'hearing_right' => ['nullable', 'string', 'max:24'],
            'dental_status' => ['nullable', 'string', 'max:60'],
            'result' => ['nullable', Rule::in(ScreeningResult::values())],
            'findings' => ['nullable', 'string', 'max:2000'],
            'recommendation' => ['nullable', 'string', 'max:2000'],
            'follow_up_required' => ['nullable', 'boolean'],
            'clinic_id' => ['nullable', 'integer', 'exists:clinic_clinics,id'],
            'notify_parent' => ['nullable', 'boolean'],
        ]);

        $screening = $this->screenings->record($program, (int) $validated['student_id'], $validated, $request->user());

        return $this->createdResponse($screening, 'Hasil skrining dicatat');
    }

    /** Imunisasi: daftar & pencatatan. */
    public function vaccinations(Request $request): JsonResponse
    {
        $ids = $this->access->visiblePatientIds($request->user());

        $rows = \App\Modules\Clinic\Models\ClinicVaccination::query()
            ->with(['patient.student:id,nis,full_name'])
            ->when($ids !== null, fn ($q) => $q->whereIn('patient_id', $ids === [] ? [-1] : $ids))
            ->when($request->query('vaccine'), fn ($q, $vaccine) => $q->where('vaccine', 'like', '%'.$vaccine.'%'))
            ->orderByDesc('given_at')
            ->limit(300)
            ->get();

        return $this->successResponse($rows);
    }

    public function storeVaccination(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['nullable', 'integer', 'exists:clinic_patients,id'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'vaccine' => ['required', 'string', 'max:120'],
            'dose' => ['nullable', 'integer', 'min:1', 'max:20'],
            'given_at' => ['nullable', 'date'],
            'batch_number' => ['nullable', 'string', 'max:60'],
            'provider' => ['nullable', 'string', 'max:160'],
            'location' => ['nullable', 'string', 'max:160'],
            'next_due_at' => ['nullable', 'date'],
            'reaction' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'consent_obtained' => ['nullable', 'boolean'],
        ]);

        $actor = $request->user();

        if (! empty($validated['patient_id'])) {
            $this->access->assertCanSeePatient($actor, (int) $validated['patient_id']);
            $patient = ClinicPatient::query()->findOrFail($validated['patient_id']);
        } elseif (! empty($validated['student_id'])) {
            $patient = app(\App\Modules\Clinic\Application\Services\PatientService::class)
                ->forStudent((int) $validated['student_id'], $actor);
        } else {
            return $this->validationErrorResponse(['patient_id' => 'Pilih pasien atau siswa.']);
        }

        $vaccination = $this->screenings->recordVaccination($patient, $validated, $actor);

        return $this->createdResponse($vaccination, 'Imunisasi dicatat');
    }
}
