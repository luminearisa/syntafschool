<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingActivityScheduleRequest;
use App\Http\Resources\BoardingActivityScheduleResource;
use App\Models\BoardingActivitySchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Jadwal mingguan kegiatan (§19). Deteksi bentrok waktu ditolak di sini
 * karena jadwal yang tumpang tindih membuat "sesi" ambigu bagi petugas.
 */
class BoardingActivityScheduleController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->baseQuery($request)
            ->orderBy('day_of_week')
            ->orderBy('start_time');

        if ($request->input('all')) {
            return $this->successResponse(BoardingActivityScheduleResource::collection($query->get()), 'Daftar jadwal berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingActivityScheduleResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar jadwal berhasil diambil',
        );
    }

    public function show(BoardingActivitySchedule $schedule): JsonResponse
    {
        $schedule->load($this->loads());

        return $this->successResponse(new BoardingActivityScheduleResource($schedule), 'Detail jadwal berhasil diambil');
    }

    public function store(BoardingActivityScheduleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->assertNoOverlap($data);

        $schedule = BoardingActivitySchedule::create($data);

        return $this->createdResponse(
            new BoardingActivityScheduleResource($schedule->load($this->loads())),
            'Jadwal berhasil dibuat',
        );
    }

    public function update(BoardingActivityScheduleRequest $request, BoardingActivitySchedule $schedule): JsonResponse
    {
        $data = $request->validated();
        $this->assertNoOverlap([...$schedule->only(['activity_id', 'day_of_week', 'start_time', 'end_time', 'dormitory_id', 'group_id']), ...$data], $schedule->id);

        $schedule->update($data);

        return $this->successResponse(
            new BoardingActivityScheduleResource($schedule->refresh()->load($this->loads())),
            'Jadwal berhasil diperbarui',
        );
    }

    public function destroy(BoardingActivitySchedule $schedule): JsonResponse
    {
        $schedule->delete();

        return $this->successResponse(null, 'Jadwal berhasil dihapus');
    }

    /** Peta mingguan satu asrama/kelompok untuk layar penjadwalan. */
    public function weekly(Request $request): JsonResponse
    {
        $rows = $this->baseQuery($request)
            ->with($this->loads())
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->groupBy(fn (BoardingActivitySchedule $s): int => (int) $s->day_of_week);

        $calendar = [];

        foreach (BoardingActivitySchedule::DAYS as $day) {
            $calendar[$day] = [
                'day' => $day,
                'day_label' => BoardingActivitySchedule::DAY_LABELS[$day] ?? (string) $day,
                'schedules' => BoardingActivityScheduleResource::collection($rows->get($day, collect()))->resolve(request()),
            ];
        }

        return $this->successResponse(array_values($calendar), 'Jadwal mingguan berhasil diambil');
    }

    private function baseQuery(Request $request): Builder
    {
        return BoardingActivitySchedule::query()
            ->with($this->loads())
            ->when($request->filled('activity_id'), fn (Builder $q) => $q->where('activity_id', (int) $request->input('activity_id')))
            ->when($request->filled('dormitory_id'), fn (Builder $q) => $q->where('dormitory_id', (int) $request->input('dormitory_id')))
            ->when($request->filled('group_id'), fn (Builder $q) => $q->where('group_id', (int) $request->input('group_id')))
            ->when($request->filled('day_of_week'), fn (Builder $q) => $q->where('day_of_week', (int) $request->input('day_of_week')))
            ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')));
    }

    /** @return list<string> */
    private function loads(): array
    {
        return ['activity:id,code,name,category', 'dormitory:id,code,name', 'group:id,code,name'];
    }

    /**
     * Bentrok = kegiatan SAMA, hari sama, cakupan sama, rentang waktu berpotongan.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNoOverlap(array $data, ?int $ignoreId = null): void
    {
        $match = function (Builder $q, string $column) use ($data): void {
            $value = $data[$column] ?? null;

            if ($value === null || $value === '') {
                $q->whereNull($column);
            } else {
                $q->where($column, (int) $value);
            }
        };

        $conflict = BoardingActivitySchedule::query()
            ->where('activity_id', (int) ($data['activity_id'] ?? 0))
            ->where('day_of_week', (int) ($data['day_of_week'] ?? 0))
            ->tap(fn (Builder $q) => $match($q, 'dormitory_id'))
            ->tap(fn (Builder $q) => $match($q, 'group_id'))
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->where('start_time', '<', (string) ($data['end_time'] ?? '00:00'))
            ->where('end_time', '>', (string) ($data['start_time'] ?? '00:00'))
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'start_time' => 'Jadwal ini bertumpuk dengan jadwal kegiatan yang sama pada hari dan cakupan yang sama.',
            ]);
        }
    }
}
