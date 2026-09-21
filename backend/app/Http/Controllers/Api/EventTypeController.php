<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AcademicCalendar\EventTypeRequest;
use App\Http\Resources\EventTypeResource;
use App\Models\EventType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A4 — Jenis event kalender akademik.
 *
 * Jenis event menentukan warna di kalender dan apakah event itu secara default
 * meniadakan hari sekolah (`is_holiday`).
 */
class EventTypeController extends BaseApiController
{
    /** Kolom yang boleh difilter lewat query string. */
    protected array $filterable = ['is_active', 'is_holiday'];

    public function index(Request $request): JsonResponse
    {
        $query = EventType::query();

        $this->applyFilters($query, $request);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $query->orderBy('name');

        if ($request->boolean('all')) {
            return $this->successResponse(
                EventTypeResource::collection($query->get()),
                'Jenis event berhasil diambil',
            );
        }

        return $this->successResponse(
            EventTypeResource::collection($query->paginate((int) $request->query('per_page', 50))),
            'Jenis event berhasil diambil',
        );
    }

    public function store(EventTypeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['is_holiday'] = $request->boolean('is_holiday');
        $data['is_active'] = $request->boolean('is_active', true);

        $eventType = EventType::create($data);

        return $this->createdResponse(
            new EventTypeResource($eventType),
            'Jenis event berhasil ditambahkan',
        );
    }

    public function show(EventType $eventType): JsonResponse
    {
        return $this->successResponse(
            new EventTypeResource($eventType),
            'Detail jenis event berhasil diambil',
        );
    }

    public function update(EventTypeRequest $request, EventType $eventType): JsonResponse
    {
        $data = $request->validated();

        if ($request->has('is_holiday')) {
            $data['is_holiday'] = $request->boolean('is_holiday');
        }
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        $eventType->update($data);

        return $this->successResponse(
            new EventTypeResource($eventType->refresh()),
            'Jenis event berhasil diperbarui',
        );
    }

    public function destroy(EventType $eventType): JsonResponse
    {
        // Jenis event yang masih dipakai tidak boleh dihapus agar kalender lama
        // tetap terbaca.
        if ($eventType->academicEvents()->exists()) {
            return $this->errorResponse(
                'Jenis event masih dipakai oleh event akademik sehingga tidak bisa dihapus.',
                422,
            );
        }

        $eventType->delete();

        return $this->successResponse(null, 'Jenis event berhasil dihapus');
    }

    /**
     * Terapkan filter `filter[kolom]=nilai` maupun `?kolom=nilai`.
     */
    protected function applyFilters($query, Request $request): void
    {
        $filters = array_merge(
            (array) $request->query('filter', []),
            array_intersect_key($request->query(), array_flip($this->filterable)),
        );

        foreach ($filters as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->filterable, true)) {
                continue;
            }

            if (in_array($column, ['is_active', 'is_holiday'], true)) {
                $query->where($column, filter_var($value, FILTER_VALIDATE_BOOLEAN));
                continue;
            }

            $query->where($column, $value);
        }
    }
}
