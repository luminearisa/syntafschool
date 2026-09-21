<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizScheduleRequest;
use App\Http\Resources\TahfizScheduleResource;
use App\Models\TahfizSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Jadwal berulang per jenis aktivitas Tahfiz.
 *
 * Satu halaqah dapat memiliki beberapa jadwal dengan jenis aktivitas
 * berbeda (setoran pada Senin, tahsin pada Rabu, dan seterusnya).
 */
class TahfizScheduleController extends BaseApiController
{
    protected array $filterable = [
        'halaqah_id', 'program_id', 'activity_type', 'day_of_week', 'status',
    ];

    protected array $with = ['halaqah', 'room', 'instructor'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizSchedule::query()->with($this->with);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        if ($date = $request->query('date')) {
            $query->effectiveOn($date);
        }

        $query->orderBy('day_of_week')->orderBy('start_time');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizScheduleResource::collection($query->get()),
                'Daftar jadwal Tahfiz berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizScheduleResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar jadwal Tahfiz berhasil diambil'
        );
    }

    public function show(TahfizSchedule $schedule): JsonResponse
    {
        return $this->successResponse(
            new TahfizScheduleResource($schedule->load($this->with)),
            'Detail jadwal Tahfiz berhasil diambil'
        );
    }

    public function store(TahfizScheduleRequest $request): JsonResponse
    {
        $schedule = TahfizSchedule::create($request->validated());

        return $this->createdResponse(
            new TahfizScheduleResource($schedule->load($this->with)),
            'Jadwal Tahfiz berhasil dibuat'
        );
    }

    public function update(TahfizScheduleRequest $request, TahfizSchedule $schedule): JsonResponse
    {
        $schedule->update($request->validated());

        return $this->successResponse(
            new TahfizScheduleResource($schedule->load($this->with)),
            'Jadwal Tahfiz berhasil diperbarui'
        );
    }

    public function destroy(TahfizSchedule $schedule): JsonResponse
    {
        $schedule->delete();

        return $this->successResponse(null, 'Jadwal Tahfiz berhasil dihapus');
    }
}
