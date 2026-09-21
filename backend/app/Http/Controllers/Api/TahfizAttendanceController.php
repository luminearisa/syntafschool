<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizAttendanceRequest;
use App\Http\Resources\TahfizAttendanceResource;
use App\Models\TahfizAttendance;
use App\Models\TahfizHalaqah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Kehadiran peserta Tahfiz.
 *
 * Satu peserta hanya boleh memiliki satu catatan per (sesi) atau per
 * (halaqah, tanggal). Status izin/sakit wajib disertai keterangan.
 */
class TahfizAttendanceController extends BaseApiController
{
    protected array $filterable = [
        'session_id', 'halaqah_id', 'participant_id', 'status',
    ];

    protected array $with = ['session', 'halaqah', 'participant'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizAttendance::query()->with($this->with);

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

        $query->orderByDesc('date')->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizAttendanceResource::collection($query->get()),
                'Daftar kehadiran Tahfiz berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizAttendanceResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar kehadiran Tahfiz berhasil diambil'
        );
    }

    public function store(TahfizAttendanceRequest $request): JsonResponse
    {
        $this->assertNoDuplicate(
            $request->input('session_id'),
            $request->input('halaqah_id'),
            $request->input('participant_id'),
            $request->input('date'),
        );

        $data = $request->validated();
        $data['recorded_by'] = $request->user()?->id;

        $attendance = TahfizAttendance::create($data);

        return $this->createdResponse(
            new TahfizAttendanceResource($attendance->load($this->with)),
            'Kehadiran berhasil dicatat'
        );
    }

    public function update(TahfizAttendanceRequest $request, TahfizAttendance $attendance): JsonResponse
    {
        $attendance->update($request->validated());

        return $this->successResponse(
            new TahfizAttendanceResource($attendance->load($this->with)),
            'Kehadiran berhasil diperbarui'
        );
    }

    public function destroy(TahfizAttendance $attendance): JsonResponse
    {
        $attendance->delete();

        return $this->successResponse(null, 'Kehadiran berhasil dihapus');
    }

    /**
     * Catat kehadiran seluruh anggota halaqah pada satu sesi/tanggal sekaligus.
     *
     * @return JsonResponse
     */
    public function bulk(Request $request)
    {
        $request->validate([
            'session_id' => ['nullable', 'integer', 'exists:tahfiz_sessions,id'],
            'halaqah_id' => ['required', 'integer', 'exists:tahfiz_halaqahs,id'],
            'date' => ['required', 'date'],
            'attendances' => ['required', 'array'],
            'attendances.*.participant_id' => ['required', 'integer', 'exists:tahfiz_participants,id'],
            'attendances.*.status' => ['required', 'string', 'in:'.implode(',', \App\Models\TahfizAttendance::STATUSES)],
            'attendances.*.arrival_time' => ['nullable', 'date_format:H:i'],
            'attendances.*.permission_reason' => ['nullable', 'string', 'max:150'],
        ]);

        $halaqahId = (int) $request->input('halaqah_id');
        $sessionId = $request->input('session_id');
        $date = $request->input('date');
        $userId = $request->user()?->id;

        $halaqah = TahfizHalaqah::query()->whereKey($halaqahId)->firstOrFail();
        $memberIds = $halaqah->activeMembers()->pluck('participant_id')->all();

        // Validasi seluruh entri sebelum menulis satu pun.
        $entries = $request->input('attendances');
        $errors = [];
        foreach ($entries as $index => $entry) {
            if (! in_array((int) $entry['participant_id'], $memberIds, true)) {
                $errors["attendances.{$index}.participant_id"] = 'Peserta bukan anggota aktif halaqah ini.';
            }
            if (in_array($entry['status'], ['izin', 'sakit'], true) && blank($entry['permission_reason'] ?? null)) {
                $errors["attendances.{$index}.permission_reason"] = "Keterangan wajib diisi untuk status {$entry['status']}.";
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $created = 0;
        DB::transaction(function () use ($entries, $sessionId, $halaqahId, $date, $userId, &$created) {
            foreach ($entries as $entry) {
                $participantId = (int) $entry['participant_id'];

                $existing = TahfizAttendance::query()
                    ->where('participant_id', $participantId)
                    ->whereDate('date', $date)
                    ->when($sessionId, fn ($q) => $q->where('session_id', $sessionId))
                    ->when(! $sessionId, fn ($q) => $q->where('halaqah_id', $halaqahId))
                    ->first();

                $payload = [
                    'session_id' => $sessionId,
                    'halaqah_id' => $sessionId ? null : $halaqahId,
                    'participant_id' => $participantId,
                    'date' => $date,
                    'status' => $entry['status'],
                    'arrival_time' => $entry['arrival_time'] ?? null,
                    'permission_reason' => $entry['permission_reason'] ?? null,
                    'recorded_by' => $userId,
                ];

                if ($existing) {
                    $existing->update($payload);
                } else {
                    TahfizAttendance::create($payload);
                }

                $created++;
            }
        });

        return $this->successResponse(
            ['saved' => $created],
            "Kehadiran {$created} peserta berhasil disimpan."
        );
    }

    /**
     * Mencegah duplikat catatan kehadiran.
     * Sama seperti presensi sekolah (A6): SQLite memperlakukan NULL berbeda,
     * sehingga indeks unik tidak cukup — aturan ini ditegakkan di aplikasi.
     */
    protected function assertNoDuplicate(?int $sessionId, ?int $halaqahId, int $participantId, string $date): void
    {
        $query = TahfizAttendance::query()
            ->where('participant_id', $participantId)
            ->whereDate('date', $date);

        if ($sessionId) {
            $query->where('session_id', $sessionId);
        } else {
            $query->where('halaqah_id', $halaqahId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'participant_id' => 'Peserta sudah memiliki catatan kehadiran pada sesi/tanggal ini.',
            ]);
        }
    }
}
