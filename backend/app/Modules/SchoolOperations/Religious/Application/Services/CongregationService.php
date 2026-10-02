<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{CongregationalPrayer, ImamProfile, MuadzinProfile};
use Illuminate\Validation\ValidationException;

/**
 * Shalat berjamaah (§15, §17, §19, §21).
 *
 * Alur: Prayer Schedule → Congregation → Imam → Muadzin → Participants.
 * Penugasan pengganti tetap tersimpan sebagai riwayat, bukan menimpa diam-diam.
 */
class CongregationService
{
    public function __construct(protected ReligiousContext $context, protected PersonnelService $personnel)
    {
    }

    /** Bentuk/ambil baris jamaah untuk jadwal tertentu (unggul & idempoten). */
    public function ensure(array $payload): CongregationalPrayer
    {
        $mosqueId = $payload['mosque_id'] ?? null;
        if (! $mosqueId) {
            throw ValidationException::withMessages([
                'mosque_id' => 'Shalat berjamaah wajib terikat masjid.',
            ]);
        }

        $row = CongregationalPrayer::query()->firstOrNew([
            'mosque_id' => (int) $mosqueId,
            'congregation_date' => $payload['congregation_date'] ?? now()->toDateString(),
            'prayer_type' => strtoupper((string) $payload['prayer_type']),
        ]);

        $row->fill([
            'prayer_schedule_id' => $payload['prayer_schedule_id'] ?? $row->prayer_schedule_id,
            'imam_id' => $payload['imam_id'] ?? $row->imam_id,
            'muadzin_id' => $payload['muadzin_id'] ?? $row->muadzin_id,
            'start_time' => $payload['start_time'] ?? $row->start_time,
            'end_time' => $payload['end_time'] ?? $row->end_time,
            'participant_estimate' => $payload['participant_estimate'] ?? $row->participant_estimate,
            'status' => strtoupper((string) ($payload['status'] ?? 'SCHEDULED')),
            'roster_id' => $payload['roster_id'] ?? $row->roster_id,
            'notes' => $payload['notes'] ?? $row->notes,
            'meta' => $payload['meta'] ?? $row->meta,
        ]);

        $row->save();

        if ($row->wasRecentlyCreated) {
            $this->context->audit('religious.congregation.created', $row, [
                'mosque_id' => $row->mosque_id,
            ]);
            $this->context->publish('CongregationalPrayerCreated', $row, [
                'date' => (string) $row->congregation_date, 'prayer_type' => $row->prayer_type,
            ]);
        }

        return $row;
    }

    /** Tugaskan imam/muadzin dengan validasi bentrok (§60). */
    public function assign(int $congregationId, string $role, int $profileId): CongregationalPrayer
    {
        $row = CongregationalPrayer::query()->findOrFail($congregationId);
        $role = strtoupper($role);

        $personnelId = $role === 'IMAM'
            ? ImamProfile::query()->whereKey($profileId)->value('personnel_id')
            : MuadzinProfile::query()->whereKey($profileId)->value('personnel_id');

        if (in_array($role, ['IMAM', 'MUADZIN'], true) && $personnelId) {
            $conflict = $this->personnel->conflictFor((int) $personnelId, [
                'effective_from' => (string) $row->congregation_date,
                'prayer_type' => (string) $row->prayer_type,
            ]);
            if ($conflict) {
                throw ValidationException::withMessages([
                    'role' => ucfirst(strtolower($role)).' sudah bertugas pada '.$conflict,
                ]);
            }
        }

        $row->forceFill($role === 'IMAM'
            ? ['imam_id' => $profileId]
            : ['muadzin_id' => $profileId])->save();

        $this->context->audit('religious.congregation.assigned', $row, [
            'role' => $role, 'profile_id' => $profileId, 'mosque_id' => $row->mosque_id,
        ]);
        $this->context->publish($role === 'IMAM' ? 'ImamAssigned' : 'MuadzinAssigned', $row, [
            'congregation_id' => $row->getKey(),
        ]);

        return $row;
    }

    /**
     * Catat pengganti tanpa menghapus penugasan asli (§21).
     * Bila `approved` false, status tetap SCHEDULED dengan catatan pengajuan.
     */
    public function substitute(int $congregationId, string $role, int $substituteProfileId, array $payload = []): CongregationalPrayer
    {
        $row = CongregationalPrayer::query()->findOrFail($congregationId);
        $role = strtoupper($role);

        $row->forceFill($role === 'IMAM'
            ? ['substitute_imam_id' => $substituteProfileId]
            : ['substitute_muadzin_id' => $substituteProfileId])->save();

        $this->context->audit('religious.congregation.substitute_set', $row, [
            'role' => $role, 'substitute_id' => $substituteProfileId,
        ], $payload['reason'] ?? null);

        return $row;
    }

    /** Tetapkan peserta hadir dan tandai selesai (§77: PrayerCompleted). */
    public function complete(int $congregationId, int $participantActual): CongregationalPrayer
    {
        $row = CongregationalPrayer::query()->findOrFail($congregationId);
        $row->forceFill(['participant_actual' => $participantActual, 'status' => 'COMPLETED'])->save();

        $this->context->audit('religious.congregation.completed', $row);
        $this->context->publish('PrayerCompleted', $row, [
            'mosque_id' => $row->mosque_id, 'participants' => $participantActual,
        ]);

        return $row;
    }

    /** Daftar jamaah pada tanggal tertentu (termasuk petugasnya). */
    public function forDate(string $date, ?int $mosqueId = null): array
    {
        $rows = CongregationalPrayer::query()->whereDate('congregation_date', $date)
            ->when($mosqueId, fn ($q) => $q->where('mosque_id', $mosqueId))
            ->orderBy('start_time')->get();

        $imams = ImamProfile::query()->with('personnel')->get()->keyBy('id');
        $muadzins = MuadzinProfile::query()->with('personnel')->get()->keyBy('id');

        return $rows->map(fn (CongregationalPrayer $r): array => [
            'id' => $r->getKey(),
            'mosque_id' => $r->mosque_id,
            'prayer_type' => $r->prayer_type,
            'date' => (string) $r->congregation_date,
            'start_time' => $r->start_time,
            'imam' => $imams->get($r->imam_id)?->personnel?->full_name,
            'muadzin' => $muadzins->get($r->muadzin_id)?->personnel?->full_name,
            'substitute_imam' => $imams->get($r->substitute_imam_id)?->personnel?->full_name,
            'status' => $r->status,
            'participant_estimate' => $r->participant_estimate,
            'participant_actual' => $r->participant_actual,
        ])->all();
    }

    /** Rekap estimasi vs aktual untuk analitik (§71). */
    public function summary(string $from, string $to, ?int $mosqueId = null): array
    {
        $query = CongregationalPrayer::query()->whereBetween('congregation_date', [$from, $to])
            ->when($mosqueId, fn ($q) => $q->where('mosque_id', $mosqueId));

        return [
            'congregations' => (clone $query)->count(),
            'with_imam' => (clone $query)->whereNotNull('imam_id')->count(),
            'with_muadzin' => (clone $query)->whereNotNull('muadzin_id')->count(),
            'estimated' => (int) (clone $query)->sum('participant_estimate'),
            'actual' => (int) (clone $query)->sum('participant_actual'),
            'period' => ['from' => $from, 'to' => $to],
        ];
    }
}
