<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AcademicCalendar\AcademicEventRequest;
use App\Http\Requests\AcademicCalendar\EffectiveDayRequest;
use App\Http\Requests\AcademicCalendar\GenerateEffectiveDaysRequest;
use App\Http\Resources\AcademicEventResource;
use App\Http\Resources\EffectiveDayResource;
use App\Models\AcademicEvent;
use App\Models\EffectiveDay;
use App\Models\Semester;
use App\Services\EffectiveDayGenerator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * A4 — Kalender akademik & hari efektif.
 *
 * Satu controller memegang tiga hal yang saling terkait:
 *  1. Event akademik (`academic-events`) + tampilan kalender per bulan.
 *  2. Hari efektif per tanggal (`effective-days`).
 *  3. Generator hari efektif dari rentang semester dikurangi libur.
 *
 * Aturan bisnis:
 *  - `end_date` tidak boleh mendahului `start_date` (validasi request).
 *  - `semester_id` wajib milik `academic_year_id` bila tahun ajaran diisi.
 *  - Event libur tidak boleh tumpang tindih dengan event libur lain.
 *  - Duplikat (semester, jenjang, tanggal) ditolak 422.
 */
class AcademicCalendarController extends BaseApiController
{
    /** Filter yang diizinkan untuk event akademik. */
    protected array $eventFilterable = [
        'semester_id',
        'event_type_id',
        'education_level_id',
        'is_holiday',
    ];

    /** Filter yang diizinkan untuk hari efektif. */
    protected array $effectiveDayFilterable = [
        'semester_id',
        'education_level_id',
        'is_school_day',
    ];

    protected array $monthNames = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function __construct(private readonly EffectiveDayGenerator $generator)
    {
    }

    // =================================================================
    // Event akademik
    // =================================================================

    public function indexEvents(Request $request): JsonResponse
    {
        $query = AcademicEvent::query()->with($this->eventRelations());

        $this->applyEventFilters($query, $request);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Rentang `from`/`to` memakai tumpang tindih, bukan sekadar tanggal mulai,
        // supaya event multi-hari tetap muncul saat menyaring satu bulan.
        if ($from = $request->query('from')) {
            $query->whereDate('end_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('start_date', '<=', $to);
        }

        $query->orderBy('start_date')->orderBy('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                AcademicEventResource::collection($query->get()),
                'Event akademik berhasil diambil',
            );
        }

        return $this->successResponse(
            AcademicEventResource::collection($query->paginate((int) $request->query('per_page', 50))),
            'Event akademik berhasil diambil',
        );
    }

    public function storeEvent(AcademicEventRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['is_holiday'] = $request->boolean('is_holiday');
        $data['is_active'] = $request->boolean('is_active', true);

        $this->assertSemesterMatchesYear($data);
        $this->assertNoHolidayOverlap($data);
        $this->assertEventWithinSemester($data);

        $event = AcademicEvent::create($data);

        return $this->createdResponse(
            new AcademicEventResource($event->load($this->eventRelations())),
            'Event akademik berhasil ditambahkan',
        );
    }

    public function showEvent(AcademicEvent $academicEvent): JsonResponse
    {
        return $this->successResponse(
            new AcademicEventResource($academicEvent->load($this->eventRelations())),
            'Detail event akademik berhasil diambil',
        );
    }

    public function updateEvent(AcademicEventRequest $request, AcademicEvent $academicEvent): JsonResponse
    {
        $data = $request->validated();

        $data['is_holiday'] = $request->has('is_holiday')
            ? $request->boolean('is_holiday')
            : (bool) $academicEvent->is_holiday;

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        $this->assertSemesterMatchesYear($data);
        $this->assertNoHolidayOverlap($data, $academicEvent->id);
        $this->assertEventWithinSemester($data);

        $academicEvent->update($data);

        return $this->successResponse(
            new AcademicEventResource($academicEvent->refresh()->load($this->eventRelations())),
            'Event akademik berhasil diperbarui',
        );
    }

    public function destroyEvent(AcademicEvent $academicEvent): JsonResponse
    {
        $academicEvent->delete();

        return $this->successResponse(null, 'Event akademik berhasil dihapus');
    }

    /**
     * Kalender per bulan: `data.months[] = {month, label, events[]}` + `meta.total`.
     */
    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'event_type_id' => ['nullable', 'integer', 'exists:event_types,id'],
        ], [], [
            'semester_id' => 'semester',
            'event_type_id' => 'jenis event',
        ]);

        $semester = Semester::findOrFail($data['semester_id']);

        $query = AcademicEvent::query()
            ->with($this->eventRelations())
            ->where('semester_id', $semester->id)
            ->where('is_active', true);

        if (! empty($data['event_type_id'])) {
            $query->where('event_type_id', $data['event_type_id']);
        }

        $events = $query->orderBy('start_date')->get();

        $grouped = $events->groupBy(fn (AcademicEvent $event) => $event->start_date->format('Y-m'));

        $months = [];
        $cursor = $semester->start_date->copy()->startOfMonth();
        $last = $semester->end_date->copy()->startOfMonth();

        while ($cursor->lte($last)) {
            $key = $cursor->format('Y-m');

            $months[] = [
                'month' => $key,
                'label' => $this->monthLabel((int) $cursor->format('n'), (int) $cursor->format('Y')),
                'events' => AcademicEventResource::collection($grouped->get($key, collect()))->resolve(),
            ];

            $cursor->addMonth();
        }

        return response()->json([
            'success' => true,
            'message' => 'Kalender akademik berhasil diambil',
            'data' => ['months' => $months],
            'meta' => ['total' => $events->count()],
        ]);
    }

    // =================================================================
    // Hari efektif
    // =================================================================

    public function indexEffectiveDays(Request $request): JsonResponse
    {
        $query = EffectiveDay::query()->with($this->effectiveDayRelations());

        $this->applyEffectiveDayFilters($query, $request);

        if ($from = $request->query('from')) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('date', '<=', $to);
        }

        $query->orderBy('date')->orderBy('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                EffectiveDayResource::collection($query->get()),
                'Hari efektif berhasil diambil',
            );
        }

        return $this->successResponse(
            EffectiveDayResource::collection($query->paginate((int) $request->query('per_page', 100))),
            'Hari efektif berhasil diambil',
        );
    }

    public function storeEffectiveDay(EffectiveDayRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['education_level_id'] = $data['education_level_id'] ?? null;
        $data['is_school_day'] = $request->boolean('is_school_day', true);

        $this->assertDateWithinSemester((int) $data['semester_id'], $data['date']);
        $this->assertNoDuplicateEffectiveDay($data);

        $day = EffectiveDay::create($data);

        return $this->createdResponse(
            new EffectiveDayResource($day->load($this->effectiveDayRelations())),
            'Hari efektif berhasil ditambahkan',
        );
    }

    public function showEffectiveDay(EffectiveDay $effectiveDay): JsonResponse
    {
        return $this->successResponse(
            new EffectiveDayResource($effectiveDay->load($this->effectiveDayRelations())),
            'Detail hari efektif berhasil diambil',
        );
    }

    public function updateEffectiveDay(EffectiveDayRequest $request, EffectiveDay $effectiveDay): JsonResponse
    {
        $data = $request->validated();
        $data['education_level_id'] = $data['education_level_id'] ?? null;

        if ($request->has('is_school_day')) {
            $data['is_school_day'] = $request->boolean('is_school_day');
        }

        $this->assertDateWithinSemester((int) ($data['semester_id'] ?? $effectiveDay->semester_id), $data['date'] ?? $effectiveDay->date?->toDateString());
        $this->assertNoDuplicateEffectiveDay($data, $effectiveDay->id);

        $effectiveDay->update($data);

        return $this->successResponse(
            new EffectiveDayResource($effectiveDay->refresh()->load($this->effectiveDayRelations())),
            'Hari efektif berhasil diperbarui',
        );
    }

    public function destroyEffectiveDay(EffectiveDay $effectiveDay): JsonResponse
    {
        $effectiveDay->delete();

        return $this->successResponse(null, 'Hari efektif berhasil dihapus');
    }

    /**
     * Isi hari efektif dari rentang semester (Senin–Sabtu) minus libur.
     */
    public function generateEffectiveDays(GenerateEffectiveDaysRequest $request): JsonResponse
    {
        $data = $request->validated();
        $semester = Semester::findOrFail($data['semester_id']);

        $result = $this->generator->generate(
            $semester,
            $data['education_level_id'] ?? null,
            $request->boolean('overwrite'),
        );

        $message = sprintf(
            'Hari efektif dihitung: %d dibuat, %d diperbarui, %d dilewati.',
            $result['created'],
            $result['updated'],
            $result['skipped'],
        );

        return $this->successResponse($result, $message);
    }

    /**
     * Ringkasan hari efektif + rincian per bulan.
     */
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'education_level_id' => ['nullable', 'integer', 'exists:education_levels,id'],
        ], [], [
            'semester_id' => 'semester',
            'education_level_id' => 'tingkat',
        ]);

        $rows = EffectiveDay::query()
            ->where('semester_id', $data['semester_id'])
            ->when(
                ! empty($data['education_level_id']),
                fn (Builder $q) => $q->where('education_level_id', $data['education_level_id']),
            )
            ->orderBy('date')
            ->get();

        $totalSchoolDays = $rows->filter(fn (EffectiveDay $row) => $row->is_school_day)->count();
        $totalHolidays = $rows->reject(fn (EffectiveDay $row) => $row->is_school_day)->count();

        $months = $rows
            ->groupBy(fn (EffectiveDay $row) => $row->date->format('Y-m'))
            ->map(function ($group, $key) {
                [$year, $month] = explode('-', $key);
                $schoolDays = $group->filter(fn (EffectiveDay $row) => $row->is_school_day)->count();

                return [
                    'month' => $key,
                    'label' => $this->monthLabel((int) $month, (int) $year),
                    'school_days' => $schoolDays,
                    'holidays' => $group->count() - $schoolDays,
                    'effective_days' => $schoolDays,
                ];
            })
            ->values();

        return $this->successResponse([
            'total_school_days' => $totalSchoolDays,
            'total_holidays' => $totalHolidays,
            'total_effective_days' => $totalSchoolDays,
            'months' => $months,
        ], 'Ringkasan hari efektif berhasil diambil');
    }

    // =================================================================
    // Validasi aturan bisnis
    // =================================================================

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertSemesterMatchesYear(array $data): void
    {
        if (empty($data['academic_year_id'])) {
            return;
        }

        $semester = Semester::find($data['semester_id']);

        if ($semester && (int) $semester->academic_year_id !== (int) $data['academic_year_id']) {
            throw ValidationException::withMessages([
                'semester_id' => 'Semester yang dipilih bukan milik tahun ajaran tersebut.',
            ]);
        }
    }

    /**
     * Event akademik harus berada di dalam rentang semester-nya.
     *
     * Tanpa ini, event bisa "menggantung" di luar semester (mis. akhir tahun
     * yang salah) sehingga kalender bulanan dan rekap hari efektif tidak
     * konsisten.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertEventWithinSemester(array $data): void
    {
        $semester = Semester::find($data['semester_id'] ?? null);

        if (! $semester || ! $semester->start_date || ! $semester->end_date) {
            return;
        }

        $start = Carbon::parse($data['start_date'])->toDateString();
        $end = Carbon::parse($data['end_date'])->toDateString();
        $from = $semester->start_date->toDateString();
        $to = $semester->end_date->toDateString();

        if ($start < $from || $end > $to) {
            throw ValidationException::withMessages([
                'start_date' => "Tanggal event harus berada di dalam rentang semester ({$from} s/d {$to}).",
            ]);
        }
    }

    /**
     * Hari efektif harus berada di dalam rentang semester-nya.
     */
    protected function assertDateWithinSemester(int $semesterId, ?string $date): void
    {
        if (! $date) {
            return;
        }

        $semester = Semester::find($semesterId);

        if (! $semester || ! $semester->start_date || ! $semester->end_date) {
            return;
        }

        $value = Carbon::parse($date)->toDateString();
        $from = $semester->start_date->toDateString();
        $to = $semester->end_date->toDateString();

        if ($value < $from || $value > $to) {
            throw ValidationException::withMessages([
                'date' => "Tanggal harus berada di dalam rentang semester ({$from} s/d {$to}).",
            ]);
        }
    }

    /**
     * Event libur tidak boleh tumpang tindih dengan event libur lain.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertNoHolidayOverlap(array $data, ?int $ignoreId = null): void
    {
        if (empty($data['is_holiday'])) {
            return;
        }

        $conflict = AcademicEvent::query()
            ->holiday()
            ->where('semester_id', $data['semester_id'])
            ->overlapping($data['start_date'], $data['end_date'], $ignoreId)
            ->first();

        if ($conflict) {
            throw ValidationException::withMessages([
                'start_date' => sprintf(
                    'Event libur bertumpang tindih dengan "%s" (%s s/d %s).',
                    $conflict->title,
                    $conflict->start_date->toDateString(),
                    $conflict->end_date->toDateString(),
                ),
            ]);
        }
    }

    /**
     * Duplikat (semester_id, education_level_id, date) ditolak 422.
     *
     * Pemeriksaan manual ini perlu karena SQLite menganggap setiap NULL berbeda
     * pada unique index, sehingga constraint DB tidak cukup.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertNoDuplicateEffectiveDay(array $data, ?int $ignoreId = null): void
    {
        $educationLevelId = $data['education_level_id'] ?? null;

        $exists = EffectiveDay::query()
            ->where('semester_id', $data['semester_id'])
            ->whereDate('date', $data['date'])
            ->when(
                $educationLevelId === null,
                fn (Builder $q) => $q->whereNull('education_level_id'),
                fn (Builder $q) => $q->where('education_level_id', $educationLevelId),
            )
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'date' => 'Tanggal ini sudah terdaftar untuk semester dan jenjang yang sama.',
            ]);
        }
    }

    // =================================================================
    // Filter & helper
    // =================================================================

    protected function applyEventFilters(Builder $query, Request $request): void
    {
        $filters = array_merge(
            (array) $request->query('filter', []),
            array_intersect_key($request->query(), array_flip($this->eventFilterable)),
        );

        foreach ($filters as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->eventFilterable, true)) {
                continue;
            }

            if ($column === 'is_holiday') {
                $query->where('is_holiday', filter_var($value, FILTER_VALIDATE_BOOLEAN));
                continue;
            }

            // `education_level_id` belum ada di tabel academic_events; filter ini
            // hanya berlaku bila kolomnya ditambahkan di kemudian hari.
            if ($column === 'education_level_id') {
                if (Schema::hasColumn('academic_events', 'education_level_id')) {
                    $query->where('education_level_id', $value);
                }
                continue;
            }

            $query->where($column, $value);
        }
    }

    protected function applyEffectiveDayFilters(Builder $query, Request $request): void
    {
        $filters = array_merge(
            (array) $request->query('filter', []),
            array_intersect_key($request->query(), array_flip($this->effectiveDayFilterable)),
        );

        foreach ($filters as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->effectiveDayFilterable, true)) {
                continue;
            }

            if ($column === 'is_school_day') {
                $query->where('is_school_day', filter_var($value, FILTER_VALIDATE_BOOLEAN));
                continue;
            }

            $query->where($column, $value);
        }
    }

    /**
     * @return list<string>
     */
    protected function eventRelations(): array
    {
        return ['semester:id,name,academic_year_id', 'eventType:id,code,name,color,is_holiday', 'academicYear:id,name'];
    }

    /**
     * @return list<string>
     */
    protected function effectiveDayRelations(): array
    {
        return ['semester:id,name', 'educationLevel:id,name,jenjang,level_number'];
    }

    protected function monthLabel(int $month, int $year): string
    {
        return ($this->monthNames[$month] ?? $month).' '.$year;
    }
}
