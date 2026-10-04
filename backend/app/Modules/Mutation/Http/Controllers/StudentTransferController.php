<?php

namespace App\Modules\Mutation\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Modules\Mutation\Application\Services\StudentTransferService;
use App\Modules\Mutation\Http\Requests\RejectStudentTransferRequest;
use App\Modules\Mutation\Http\Requests\StoreStudentTransferRequest;
use App\Modules\Mutation\Http\Requests\UpdateStudentTransferRequest;
use App\Modules\Mutation\Http\Resources\StudentTransferResource;
use App\Modules\Mutation\Models\StudentTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Modul Mutation — Mutasi Siswa.
 *
 * Menangani permohonan mutasi (masuk, keluar, pindah kelas, pindah sekolah)
 * beserta alur persetujuannya. Efek terhadap data siswa diterapkan saat
 * `complete()`.
 */
class StudentTransferController extends BaseApiController
{
    /**
     * Relasi standar yang dimuat pada respons.
     *
     * @var list<string>
     */
    private const RELATIONS = ['student', 'fromClass', 'toClass', 'academicYear', 'requester', 'approver'];

    public function __construct(
        private readonly StudentTransferService $service,
    ) {}

    /**
     * Data pendukung untuk formulir mutasi.
     */
    public function lookups(): JsonResponse
    {
        $students = Student::query()
            ->select('id', 'nis', 'nisn', 'full_name')
            ->orderBy('full_name')
            ->limit(1000)
            ->get();

        $classes = SchoolClass::query()
            ->with('academicYear:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'id' => $class->id,
                'name' => $class->name,
                'code' => $class->code,
                'academic_year_id' => $class->academic_year_id,
                'academic_year' => $class->academicYear?->name,
            ]);

        $years = AcademicYear::query()
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (AcademicYear $year) => [
                'id' => $year->id,
                'name' => $year->name,
                'is_active' => (bool) $year->is_active,
            ]);

        return $this->successResponse([
            'students' => $students,
            'classes' => $classes,
            'academic_years' => $years,
        ], 'Data pendukung mutasi berhasil diambil');
    }

    public function index(Request $request): JsonResponse
    {
        $query = StudentTransfer::query()->with(self::RELATIONS);

        $query->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')));

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('document_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhereHas('student', function ($s) use ($search) {
                        $s->where('full_name', 'like', "%{$search}%")
                            ->orWhere('nis', 'like', "%{$search}%")
                            ->orWhere('nisn', 'like', "%{$search}%");
                    });
            });
        }

        $paginator = $query->latest('id')->paginate((int) $request->query('per_page', 15));

        return $this->successResponse(
            StudentTransferResource::collection($paginator),
            'Data mutasi berhasil diambil',
        );
    }

    public function store(StoreStudentTransferRequest $request): JsonResponse
    {
        $transfer = $this->service->create($request->validated(), $request->user()?->id);

        return $this->createdResponse(
            new StudentTransferResource($transfer->load(self::RELATIONS)),
            'Permohonan mutasi berhasil dibuat',
        );
    }

    public function show(StudentTransfer $transfer): JsonResponse
    {
        return $this->successResponse(
            new StudentTransferResource($transfer->load([...self::RELATIONS, 'histories.actor'])),
            'Detail mutasi berhasil diambil',
        );
    }

    public function update(UpdateStudentTransferRequest $request, StudentTransfer $transfer): JsonResponse
    {
        $transfer = $this->service->update($transfer, $request->validated());

        return $this->successResponse(
            new StudentTransferResource($transfer->load([...self::RELATIONS, 'histories.actor'])),
            'Permohonan mutasi berhasil diperbarui',
        );
    }

    public function submit(Request $request, StudentTransfer $transfer): JsonResponse
    {
        $transfer = $this->service->submit($transfer, $request->user()?->id);

        return $this->successResponse(
            new StudentTransferResource($transfer->load([...self::RELATIONS, 'histories.actor'])),
            'Permohonan mutasi berhasil diajukan',
        );
    }

    public function approve(Request $request, StudentTransfer $transfer): JsonResponse
    {
        $transfer = $this->service->approve($transfer, $request->user()?->id);

        return $this->successResponse(
            new StudentTransferResource($transfer->load([...self::RELATIONS, 'histories.actor'])),
            'Permohonan mutasi berhasil disetujui',
        );
    }

    public function reject(RejectStudentTransferRequest $request, StudentTransfer $transfer): JsonResponse
    {
        $transfer = $this->service->reject(
            $transfer,
            (string) $request->validated('note'),
            $request->user()?->id,
        );

        return $this->successResponse(
            new StudentTransferResource($transfer->load([...self::RELATIONS, 'histories.actor'])),
            'Permohonan mutasi ditolak',
        );
    }

    public function complete(Request $request, StudentTransfer $transfer): JsonResponse
    {
        $transfer = $this->service->complete($transfer, $request->user()?->id);

        return $this->successResponse(
            new StudentTransferResource($transfer->load([...self::RELATIONS, 'histories.actor'])),
            'Permohonan mutasi berhasil diselesaikan',
        );
    }

    public function destroy(StudentTransfer $transfer): JsonResponse
    {
        if ($transfer->status === StudentTransfer::STATUS_COMPLETED) {
            return $this->errorResponse(
                'Permohonan mutasi yang sudah selesai tidak dapat dihapus.',
                422,
            );
        }

        $transfer->delete();

        return $this->successResponse(null, 'Permohonan mutasi berhasil dihapus');
    }
}
