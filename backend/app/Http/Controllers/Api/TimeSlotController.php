<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\TimeSlotResource;
use App\Models\Schedule;
use App\Models\TimeSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Jam pelajaran — kerangka waktu yang dipakai grid jadwal.
 *
 * Contoh isi: "Senin jam ke-1, 07:00–07:45, pelajaran" dan
 * "Senin Istirahat, 09:15–09:30, istirahat".
 */
class TimeSlotController extends BaseApiController
{
    /** Kolom yang boleh difilter lewat query string. */
    protected array $filterable = ['day_id', 'type', 'is_active'];

    public function index(Request $request): JsonResponse
    {
        $query = TimeSlot::query()->with('day');

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->filterable, true)) {
                continue;
            }
            $query->where($column, $value);
        }

        $query->orderBy('day_id')->orderBy('slot_number');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TimeSlotResource::collection($query->get()),
                'Jam pelajaran berhasil diambil',
            );
        }

        return $this->successResponse(
            TimeSlotResource::collection($query->paginate((int) $request->query('per_page', 50))),
            'Jam pelajaran berhasil diambil',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $this->assertNoOverlap($data);
        $this->assertSlotNumberUnique($data);

        $slot = TimeSlot::create($data);

        return $this->createdResponse(
            new TimeSlotResource($slot->load('day')),
            'Jam pelajaran berhasil ditambahkan',
        );
    }

    public function update(Request $request, TimeSlot $timeSlot): JsonResponse
    {
        $data = $this->validatePayload($request, $timeSlot);

        $this->assertNoOverlap($data, $timeSlot);
        $this->assertSlotNumberUnique($data, $timeSlot);

        $timeSlot->update($data);

        return $this->successResponse(
            new TimeSlotResource($timeSlot->refresh()->load('day')),
            'Jam pelajaran berhasil diperbarui',
        );
    }

    public function destroy(TimeSlot $timeSlot): JsonResponse
    {
        // Jadwal yang memakai jam ini tidak dihapus — hanya penanda jamnya yang
        // dilepas (FK nullOnDelete), karena jam mulai/selesai jadwal tetap valid.
        $affected = Schedule::where('time_slot_id', $timeSlot->id)->count();

        $timeSlot->delete();

        $message = 'Jam pelajaran berhasil dihapus';
        if ($affected > 0) {
            $message .= ". {$affected} jadwal kehilangan penanda jam (jam mulai/selesai tetap tersimpan)";
        }

        return $this->successResponse(null, $message);
    }

    /**
     * Isi jam pelajaran dari template default untuk hari-hari terpilih.
     *
     * Mempercepat setup awal tahun ajaran; sekolah tetap bisa menyunting
     * hasilnya satu per satu.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'day_ids' => ['required', 'array', 'min:1'],
            'day_ids.*' => ['integer', 'exists:days,id'],
            'overwrite' => ['boolean'],
        ], [], ['day_ids' => 'hari']);

        $template = TimeSlot::defaultTemplate();
        $overwrite = $request->boolean('overwrite');

        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($data, $template, $overwrite, &$created, &$updated, &$skipped) {
            foreach ($data['day_ids'] as $dayId) {
                $slots = $template[(int) $dayId] ?? null;

                // Hari di luar template (mis. Sabtu/Minggu) dilewati, bukan digagalkan.
                if ($slots === null) {
                    $skipped++;

                    continue;
                }

                foreach ($slots as $slot) {
                    $existing = TimeSlot::where('day_id', $dayId)
                        ->where('slot_number', $slot['slot_number'])
                        ->first();

                    if ($existing && ! $overwrite) {
                        $skipped++;

                        continue;
                    }

                    $payload = [
                        'label' => $slot['label'],
                        'start_time' => $slot['start_time'].':00',
                        'end_time' => $slot['end_time'].':00',
                        'type' => $slot['type'],
                    ];

                    // Jangan biarkan template membuat jam yang beririsan.
                    $this->assertNoOverlap(['day_id' => $dayId] + $payload, $existing);

                    // Hanya slot baru yang diaktifkan; slot lama yang sengaja
                    // dinonaktifkan tidak dihidupkan paksa oleh generate.
                    if (! $existing) {
                        $payload['is_active'] = true;
                    }

                    TimeSlot::updateOrCreate(
                        ['day_id' => $dayId, 'slot_number' => $slot['slot_number']],
                        $payload,
                    );

                    $existing ? $updated++ : $created++;
                }
            }
        });

        return $this->createdResponse(
            ['created' => $created, 'updated' => $updated, 'skipped' => $skipped],
            "Jam pelajaran: {$created} dibuat, {$updated} diperbarui, {$skipped} dilewati",
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatePayload(Request $request, ?TimeSlot $ignore = null): array
    {
        $data = $request->validate([
            'day_id' => ['required', 'integer', 'exists:days,id'],
            'slot_number' => ['required', 'integer', 'min:1', 'max:30'],
            'label' => ['nullable', 'string', 'max:50'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'type' => ['required', 'in:pelajaran,istirahat,upacara,lainnya'],
            'is_active' => ['boolean'],
        ], [], [
            'day_id' => 'hari',
            'slot_number' => 'nomor jam',
            'label' => 'label',
            'start_time' => 'jam mulai',
            'end_time' => 'jam selesai',
            'type' => 'jenis',
        ]);

        // Jam terbalik / durasi nol.
        if ($data['start_time'] >= $data['end_time']) {
            throw ValidationException::withMessages([
                'end_time' => 'Jam selesai harus lebih besar dari jam mulai.',
            ]);
        }

        // Simpan dalam H:i:s agar perbandingan antar jam konsisten.
        $data['start_time'] .= ':00';
        $data['end_time'] .= ':00';
        $data['is_active'] = $request->boolean('is_active', true);
        $data['label'] = $data['label'] ?? null;

        return $data;
    }

    /**
     * Dua jam pelajaran pada hari yang sama tidak boleh beririsan.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertNoOverlap(array $data, ?TimeSlot $ignore = null): void
    {
        $clash = TimeSlot::query()
            ->where('day_id', $data['day_id'])
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore->id))
            ->first();

        if ($clash) {
            throw ValidationException::withMessages([
                'start_time' => sprintf(
                    'Jam ini beririsan dengan "%s" (%s–%s) pada hari yang sama.',
                    $clash->label ?? 'jam lain',
                    substr($clash->start_time, 0, 5),
                    substr($clash->end_time, 0, 5),
                ),
            ]);
        }
    }

    /**
     * Nomor jam harus unik per hari — "jam ke-3" hanya boleh ada satu.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertSlotNumberUnique(array $data, ?TimeSlot $ignore = null): void
    {
        $exists = TimeSlot::query()
            ->where('day_id', $data['day_id'])
            ->where('slot_number', $data['slot_number'])
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'slot_number' => 'Nomor jam ini sudah dipakai pada hari tersebut.',
            ]);
        }
    }
}
