<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\TahfidzGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Tahfidz & Halaqah (§33, §42, §43, §83) — BACA SAJA.
 *
 * Modul Tahfidz tetap sumber kebenarannya. Modul Religious hanya membaca
 * progres dan mengorkestrasi kegiatan; tidak menduplikasi hafalan.
 *
 * Catatan skema: modul Tahfidz memakai `participant_id` (peserta) yang
 * dihubungkan ke siswa lewat `tahfiz_participants`, bukan langsung
 * `student_id`. Gateway ini mendeteksi kolom yang benar-benar ada sehingga
 * tetap berfungsi bila penamaan berbeda antar versi modul.
 */
class TahfidzModuleGateway implements TahfidzGatewayInterface
{
    use GatewaySupport;

    /** Kolom yang menghubungkan sebuah tabel ke siswa. */
    protected function studentColumn(string $table): ?string
    {
        return $this->pick($this->columns($table), ['student_id', 'participant_id', 'enrollment_id']);
    }

    /** ID peserta tahfidz milik seorang siswa. */
    protected function participantIds(int $studentId): array
    {
        if (! $this->has('tahfiz_participants')
            || ! in_array('student_id', $this->columns('tahfiz_participants'), true)) {
            return [];
        }

        return DB::table('tahfiz_participants')->where('student_id', $studentId)
            ->pluck('id')->map(fn ($v): int => (int) $v)->all();
    }

    public function halaqahFor(int $studentId): array
    {
        $participantIds = $this->participantIds($studentId);
        if ($participantIds === []) {
            return [];
        }

        $result = [];

        if ($this->has('tahfiz_halaqah_members') && $this->has('tahfiz_halaqahs')
            && in_array('participant_id', $this->columns('tahfiz_halaqah_members'), true)) {
            $rows = DB::table('tahfiz_halaqah_members')
                ->join('tahfiz_halaqahs', 'tahfiz_halaqahs.id', '=', 'tahfiz_halaqah_members.halaqah_id')
                ->whereIn('tahfiz_halaqah_members.participant_id', $participantIds)
                ->select('tahfiz_halaqahs.id', 'tahfiz_halaqahs.name')
                ->limit(20)->get();

            $result = array_map(fn ($row): array => (array) $row, $rows->all());
        }

        if ($result === [] && $this->has('tahfiz_enrollments') && $this->has('tahfiz_halaqahs')
            && in_array('participant_id', $this->columns('tahfiz_enrollments'), true)
            && in_array('halaqah_id', $this->columns('tahfiz_enrollments'), true)) {
            $rows = DB::table('tahfiz_enrollments')
                ->join('tahfiz_halaqahs', 'tahfiz_halaqahs.id', '=', 'tahfiz_enrollments.halaqah_id')
                ->whereIn('tahfiz_enrollments.participant_id', $participantIds)
                ->select('tahfiz_halaqahs.id', 'tahfiz_halaqahs.name')
                ->limit(20)->get();

            $result = array_map(fn ($row): array => (array) $row, $rows->all());
        }

        return $result;
    }

    public function progressFor(int $studentId): array
    {
        $participantIds = $this->participantIds($studentId);
        $result = [
            'student_id' => $studentId,
            'participant_count' => count($participantIds),
            'halaqah' => $this->halaqahFor($studentId),
            'memorization_records' => 0,
            'revision_records' => 0,
            'available' => $this->available(),
        ];

        if ($participantIds === []) {
            $result['note'] = 'Siswa belum terdaftar sebagai peserta tahfidz.';

            return $result;
        }

        if ($this->has('tahfiz_participant_progress')
            && in_array('participant_id', $this->columns('tahfiz_participant_progress'), true)) {
            $rows = DB::table('tahfiz_participant_progress')->whereIn('participant_id', $participantIds)
                ->orderByDesc('id')->limit(50)->get();
            $result['progress'] = array_map(fn ($row): array => (array) $row, $rows->all());
            $result['juz_memorized'] = (int) $rows->max('juz');
            $result['pages_memorized'] = (int) $rows->sum('pages_memorized');
        }

        foreach ([
            'memorization_records' => 'tahfiz_memorization_records',
            'revision_records' => 'tahfiz_revision_records',
        ] as $key => $table) {
            if ($this->has($table) && in_array('participant_id', $this->columns($table), true)) {
                $result[$key] = (int) DB::table($table)->whereIn('participant_id', $participantIds)->count();
            }
        }

        return $result;
    }

    public function sessionsFor(?int $halaqahId, string $date): array
    {
        if (! $this->has('tahfiz_sessions')) {
            return [];
        }

        $columns = $this->columns('tahfiz_sessions');
        $dateColumn = $this->pick($columns, ['date', 'session_date', 'scheduled_date']);
        if ($dateColumn === null) {
            return [];
        }

        return $this->mapIds(
            DB::table('tahfiz_sessions')->where($dateColumn, $date)
                ->when($halaqahId && in_array('halaqah_id', $columns, true), fn ($q) => $q->where('halaqah_id', $halaqahId))
                ->limit(200)->get()->all(),
            fn (array $row): array => $row
        );
    }

    public function recordAttendance(array $payload): array
    {
        // §83: Tahfidz tetap sumber kebenarannya — modul ini tidak menulis ke sana.
        return [
            'written' => false,
            'reason' => 'Presensi hafalan tetap dikelola modul Tahfidz/Halaqah (§83).',
        ];
    }

    public function available(): bool
    {
        return $this->has('tahfiz_halaqahs')
            || $this->has('tahfiz_sessions')
            || $this->has('tahfiz_participants');
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Modul Tahfidz/Halaqah tidak tersedia pada instalasi ini.';
    }
}
