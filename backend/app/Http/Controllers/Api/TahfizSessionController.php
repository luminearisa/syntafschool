<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizSessionRequest;
use App\Http\Resources\TahfizSessionResource;
use App\Models\TahfizHalaqah;
use App\Models\TahfizSchedule;
use App\Models\TahfizSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sesi konkret: satu pertemuan pada satu tanggal.
 *
 * Sesi dapat dibuat manual atau di-generate dari jadwal berulang untuk satu
 * rentang tanggal. Kehadiran dan setoran bergantung ke sesi.
 */
class TahfizSessionController extends BaseApiController
{
    protected array $filterable = ['halaqah_id', 'activity_type', 'status', 'schedule_id'];

    protected array $with = ['halaqah', 'schedule', 'room', 'instructor'];

    protected array $counts = ['attendances', 'memorizationRecords'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizSession::query()
            ->with($this->with)
            ->withCount($this->counts);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        if ($from = $request->query('from')) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('date', '<=', $to);
        }

        $query->orderBy('date')->orderBy('start_time');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizSessionResource::collection($query->get()),
                'Daftar sesi Tahfiz berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizSessionResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar sesi Tahfiz berhasil diambil'
        );
    }

    public function show(TahfizSession $session): JsonResponse
    {
        return $this->successResponse(
            new TahfizSessionResource($session->load($this->with)->loadCount($this->counts)),
            'Detail sesi Tahfiz berhasil diambil'
        );
    }

    public function store(TahfizSessionRequest $request): JsonResponse
    {
        $session = TahfizSession::create($request->validated());

        return $this->createdResponse(
            new TahfizSessionResource($session->load($this->with)->loadCount($this->counts)),
            'Sesi Tahfiz berhasil dibuat'
        );
    }

    public function update(TahfizSessionRequest $request, TahfizSession $session): JsonResponse
    {
        $session->update($request->validated());

        return $this->successResponse(
            new TahfizSessionResource($session->load($this->with)->loadCount($this->counts)),
            'Sesi Tahfiz berhasil diperbarui'
        );
    }

    public function destroy(TahfizSession $session): JsonResponse
    {
        $session->delete();

        return $this->successResponse(null, 'Sesi Tahfiz berhasil dihapus');
    }

    /**
     * Ubah status sesi (planned → ongoing → done / cancelled).
     */
    public function transition(Request $request, TahfizSession $session): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'string', 'in:planned,ongoing,done,cancelled'],
        ]);

        $session->update(['status' => $request->input('status')]);

        return $this->successResponse(
            new TahfizSessionResource($session->load($this->with)),
            'Status sesi berhasil diubah ke '.$session->status
        );
    }

    /**
     * Generate sesi dari jadwal berulang untuk rentang tanggal.
     *
     * Ini menghindari pembuatan satu per satu: admin menentukan rentang,
     * sistem membuat sesi untuk setiap jadwal yang berlaku pada harinya.
     */
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'halaqah_id' => ['required', 'integer', 'exists:tahfiz_halaqahs,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $halaqah = TahfizHalaqah::query()->whereKey($request->input('halaqah_id'))->firstOrFail();
        $from = $request->date('from');
        $to = $request->date('to');

        if ($to->diffInDays($from) > 60) {
            throw ValidationException::withMessages([
                'to' => 'Rentang generate maksimal 60 hari.',
            ]);
        }

        $schedules = TahfizSchedule::query()
            ->where('halaqah_id', $halaqah->id)
            ->active()
            ->effectiveOn($from->toDateString())
            ->get();

        if ($schedules->isEmpty()) {
            throw ValidationException::withMessages([
                'halaqah_id' => 'Halaqah ini belum memiliki jadwal aktif yang berlaku.',
            ]);
        }

        $created = 0;
        $date = $from->copy();

        DB::transaction(function () use ($date, $to, $schedules, $halaqah, &$created) {
            while ($date <= $to) {
                foreach ($schedules as $schedule) {
                    if ($schedule->day_of_week !== (int) $date->dayOfWeek) {
                        continue;
                    }

                    // whereDate, bukan where biasa: kolom date disimpan dengan
                    // sufiks waktu oleh grammar SQL, sehingga where('date', ...)
                    // tidak pernah cocok dan generate akan menduplikasi sesi.
                    $exists = TahfizSession::query()
                        ->where('halaqah_id', $halaqah->id)
                        ->where('schedule_id', $schedule->id)
                        ->whereDate('date', $date->toDateString())
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    TahfizSession::create([
                        'halaqah_id' => $halaqah->id,
                        'schedule_id' => $schedule->id,
                        'date' => $date->toDateString(),
                        'activity_type' => $schedule->activity_type,
                        'start_time' => $schedule->start_time,
                        'end_time' => $schedule->end_time,
                        'room_id' => $schedule->room_id,
                        'instructor_id' => $schedule->instructor_id ?? $halaqah->instructor_id,
                        'status' => TahfizSession::STATUS_PLANNED,
                    ]);

                    $created++;
                }

                $date->addDay();
            }
        });

        return $this->successResponse(
            ['created' => $created],
            "Berhasil membuat {$created} sesi dari jadwal berulang."
        );
    }
}
