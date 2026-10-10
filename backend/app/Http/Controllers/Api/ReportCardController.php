<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ReportCardResource;
use App\Models\ReportCard;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Services\Academic\ReportCardService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ReportCardController extends BaseApiController
{
    public function __construct(private readonly ReportCardService $reports) {}

    public function lookups(): JsonResponse
    {
        return $this->successResponse([
            'classes' => SchoolClass::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'academic_year_id']),
            'semesters' => Semester::query()->with('academicYear:id,name')->orderByDesc('start_date')->get(['id', 'academic_year_id', 'name', 'start_date', 'end_date', 'is_active']),
        ], 'Referensi rapor berhasil diambil');
    }

    public function index(Request $request): JsonResponse
    {
        $query = ReportCard::query()
            ->with(['student', 'schoolClass', 'semester', 'academicYear'])
            ->withCount('items')
            ->when($request->filled('class_id'), fn (Builder $q) => $q->where('school_class_id', (int) $request->input('class_id')))
            ->when($request->filled('semester_id'), fn (Builder $q) => $q->where('semester_id', (int) $request->input('semester_id')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->whereHas('student', fn (Builder $student) => $student
                    ->where('full_name', 'like', "%{$term}%")
                    ->orWhere('nis', 'like', "%{$term}%"));
            })
            ->orderByDesc('id');

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            ReportCardResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar rapor berhasil diambil',
        );
    }

    public function show(Request $request, ReportCard $reportCard): JsonResponse
    {
        $this->assertMayManage($request, $reportCard);

        $reportCard->load(['student', 'schoolClass', 'semester', 'academicYear', 'items.subject', 'publishedBy']);

        return $this->successResponse(new ReportCardResource($reportCard), 'Detail rapor berhasil diambil');
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'type' => ['required', Rule::in(ReportCard::TYPES)],
        ], [], [
            'class_id' => 'kelas',
            'semester_id' => 'semester',
            'type' => 'jenis rapor',
        ]);

        // Guru/wali kelas hanya boleh generate rapor kelas yang ia ampu.
        if (! app(\App\Services\Academic\TeachingAccess::class)->mayRecordClass($request->user(), (int) $data['class_id'])) {
            abort(403, 'Anda bukan wali kelas atau pengampu kelas ini.');
        }

        $cards = $this->reports->generate(
            SchoolClass::query()->findOrFail((int) $data['class_id']),
            Semester::query()->findOrFail((int) $data['semester_id']),
            (string) $data['type'],
        );

        return $this->successResponse([
            'generated' => $cards->count(),
            'reports' => ReportCardResource::collection($cards),
        ], 'Rapor berhasil digenerate sebagai draft');
    }

    public function update(Request $request, ReportCard $reportCard): JsonResponse
    {
        $this->assertMayManage($request, $reportCard);

        $data = $request->validate([
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'homeroom_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'principal_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'attitude' => ['sometimes', 'nullable', 'array'],
            'attitude.spiritual' => ['sometimes', 'nullable', 'array'],
            'attitude.spiritual.predicate' => ['sometimes', 'nullable', 'string', 'max:5'],
            'attitude.spiritual.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'attitude.social' => ['sometimes', 'nullable', 'array'],
            'attitude.social.predicate' => ['sometimes', 'nullable', 'string', 'max:5'],
            'attitude.social.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'extracurriculars' => ['sometimes', 'nullable', 'array', 'max:20'],
            'extracurriculars.*.name' => ['nullable', 'string', 'max:150'],
            'extracurriculars.*.predicate' => ['nullable', 'string', 'max:5'],
            'extracurriculars.*.description' => ['nullable', 'string', 'max:1000'],
            'achievements' => ['sometimes', 'nullable', 'array', 'max:50'],
            'achievements.*.name' => ['nullable', 'string', 'max:200'],
            'achievements.*.level' => ['nullable', 'string', 'max:50'],
            'achievements.*.year' => ['nullable', 'string', 'max:9'],
            'achievements.*.description' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'notes' => 'catatan rapor',
            'homeroom_note' => 'catatan wali kelas',
            'principal_note' => 'catatan kepala sekolah',
            'attitude' => 'sikap',
            'extracurriculars' => 'ekstrakurikuler',
            'achievements' => 'prestasi',
        ]);

        $data = $this->normalizeComponents($data);

        $updated = $this->reports->updateNotes($reportCard, $data);

        return $this->successResponse(new ReportCardResource($updated), 'Catatan rapor berhasil disimpan');
    }

    public function ready(Request $request, ReportCard $reportCard): JsonResponse
    {
        $this->assertMayManage($request, $reportCard);

        return $this->successResponse(
            new ReportCardResource($this->reports->markReady($reportCard)),
            'Rapor diajukan untuk review',
        );
    }

    public function approve(ReportCard $reportCard): JsonResponse
    {
        return $this->successResponse(
            new ReportCardResource($this->reports->approve($reportCard)),
            'Rapor berhasil disetujui',
        );
    }

    public function publish(Request $request, ReportCard $reportCard): JsonResponse
    {
        return $this->successResponse(
            new ReportCardResource($this->reports->publish($reportCard, $request->user()?->id)),
            'Rapor berhasil diterbitkan',
        );
    }

    public function reopen(Request $request, ReportCard $reportCard): JsonResponse
    {
        $this->assertMayManage($request, $reportCard);

        return $this->successResponse(
            new ReportCardResource($this->reports->reopen($reportCard)),
            'Rapor dikembalikan ke draft',
        );
    }

    public function pdf(Request $request, ReportCard $reportCard): Response
    {
        $this->assertMayManage($request, $reportCard);

        $reportCard->load(['student', 'schoolClass', 'semester', 'academicYear', 'items.subject']);
        $school = School::query()->where('is_active', true)->orderBy('id')->first();
        $filename = 'rapor-'.$reportCard->student?->nis.'-'.$reportCard->semester?->name.'.pdf';

        return Pdf::loadView('pdf.academic-report-card', compact('reportCard', 'school'))
            ->setPaper('a4')
            ->download($filename);
    }

    /**
     * Bersihkan komponen rapor: buang baris ekskul/prestasi tanpa nama dan
     * catatan sikap yang kosong agar tidak menyimpan baris hampa.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeComponents(array $data): array
    {
        foreach (['extracurriculars', 'achievements'] as $key) {
            if (! array_key_exists($key, $data) || ! is_array($data[$key])) {
                continue;
            }

            $rows = collect($data[$key])
                ->filter(fn ($row) => is_array($row) && trim((string) ($row['name'] ?? '')) !== '')
                ->map(function (array $row): array {
                    $row['name'] = trim((string) $row['name']);

                    return $row;
                })
                ->values()
                ->all();

            $data[$key] = $rows === [] ? null : $rows;
        }

        if (array_key_exists('attitude', $data) && is_array($data['attitude'])) {
            $attitude = array_filter($data['attitude'], fn ($section) => is_array($section) && (
                trim((string) ($section['predicate'] ?? '')) !== ''
                || trim((string) ($section['description'] ?? '')) !== ''
            ));

            $data['attitude'] = $attitude === [] ? null : $attitude;
        }

        return $data;
    }

    /**
     * Guru hanya menyentuh rapor kelas yang ia ampu/walikelasinya; admin dan
     * pengelola akademik tidak dibatasi (lihat TeachingAccess).
     */
    protected function assertMayManage(Request $request, ReportCard $reportCard): void
    {
        if (! app(\App\Services\Academic\TeachingAccess::class)->mayManageReportCard($request->user(), $reportCard)) {
            abort(403, 'Anda bukan wali kelas atau pengampu kelas ini.');
        }
    }
}
