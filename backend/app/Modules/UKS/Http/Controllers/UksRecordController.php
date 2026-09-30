<?php

namespace App\Modules\UKS\Http\Controllers;

use App\Modules\UKS\Application\Services\HealthRecordService;
use App\Modules\UKS\Application\Services\UksAccessService;
use App\Modules\UKS\Domain\Enums\ScreeningResult;
use App\Modules\UKS\Domain\Enums\ScreeningType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Profil kesehatan, pemeriksaan berkala, dan imunisasi. */
class UksRecordController extends UksController
{
    public function __construct(
        private readonly HealthRecordService $records,
        private readonly UksAccessService $access,
    ) {
    }

    public function show(Request $request, int $student): JsonResponse
    {
        return $this->successResponse(
            $this->records->studentRecord($request->user(), $student),
            'Rekam kesehatan siswa.',
        );
    }

    public function saveProfile(Request $request, int $student): JsonResponse
    {
        $data = $request->validate([
            'blood_type' => ['nullable', 'string', 'max:3'],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'chronic_conditions' => ['nullable', 'string', 'max:1000'],
            'regular_medications' => ['nullable', 'string', 'max:1000'],
            'special_needs' => ['nullable', 'string', 'max:1000'],
            'insurance_provider' => ['nullable', 'string', 'max:80'],
            'insurance_number' => ['nullable', 'string', 'max:40'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->successResponse(
            $this->records->saveProfile($student, $data, $request->user()),
            'Profil kesehatan disimpan.',
        );
    }

    public function screenings(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'screening_type' => ['nullable', Rule::enum(ScreeningType::class)],
            'result' => ['nullable', Rule::enum(ScreeningResult::class)],
            'student_id' => ['nullable', 'integer'],
            'school_class_id' => ['nullable', 'integer'],
        ]);

        return $this->successResponse(
            $this->records->listScreenings($request->user(), $filters, $this->perPage($request)),
            'Daftar pemeriksaan berkala.',
        );
    }

    public function storeScreening(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'school_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'screening_type' => ['required', Rule::enum(ScreeningType::class)],
            'screened_at' => ['nullable', 'date'],
            'height_cm' => ['nullable', 'numeric', 'between:30,250'],
            'weight_kg' => ['nullable', 'numeric', 'between:2,300'],
            'vision_left' => ['nullable', 'string', 'max:10'],
            'vision_right' => ['nullable', 'string', 'max:10'],
            'dental_status' => ['nullable', 'string', 'max:40'],
            'result' => ['nullable', Rule::enum(ScreeningResult::class)],
            'findings' => ['nullable', 'string', 'max:2000'],
            'recommendation' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->createdResponse($this->records->recordScreening($data, $request->user()), 'Pemeriksaan dicatat.');
    }

    public function immunizations(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'student_id' => ['nullable', 'integer'],
            'due_soon' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse(
            $this->records->listImmunizations($request->user(), $filters, $this->perPage($request)),
            'Daftar imunisasi.',
        );
    }

    public function storeImmunization(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'vaccine_name' => ['required', 'string', 'max:120'],
            'dose_number' => ['nullable', 'integer', 'between:1,10'],
            'given_at' => ['nullable', 'date'],
            'batch_no' => ['nullable', 'string', 'max:40'],
            'provider' => ['nullable', 'string', 'max:120'],
            'next_due_at' => ['nullable', 'date', 'after:given_at'],
            'reaction' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->createdResponse($this->records->recordImmunization($data, $request->user()), 'Imunisasi dicatat.');
    }

    /** Siswa yang boleh dilihat pengguna ini (untuk pemilih siswa di layar). */
    public function visibleStudents(Request $request): JsonResponse
    {
        $ids = $this->access->visibleStudentIds($request->user());

        $query = \App\Models\Student::query()->select('id', 'full_name', 'nis')->orderBy('full_name');
        if ($ids !== null) {
            $query->whereIn('id', $ids === [] ? [-1] : $ids);
        }
        if ($search = trim((string) $request->string('search'))) {
            $safe = '%'.addcslashes(mb_substr($search, 0, 60), '%_\\').'%';
            $query->where(fn ($q) => $q->where('full_name', 'like', $safe)->orWhere('nis', 'like', $safe));
        }

        return $this->successResponse($query->limit(30)->get(), 'Siswa dalam cakupan Anda.');
    }
}
