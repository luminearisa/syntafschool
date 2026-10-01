<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Contracts\BoardingProviderInterface;
use App\Modules\Bk\Domain\Contracts\StudentProviderInterface;
use App\Modules\Bk\Domain\Enums\AppointmentStatus;
use App\Modules\Bk\Domain\Enums\AttendanceStatus;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\CaseEventType;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Domain\Enums\SessionMode;
use App\Modules\Bk\Domain\Enums\SessionType;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkAppointment;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCounselorAvailability;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Janji temu konseling — penjadwalan yang sadar bentrok.
 *
 * Sebelum janji dibuat, `detectConflicts()` memeriksa enam sumber: jadwal
 * pelajaran siswa, ketersediaan konselor, janji lain konselor, janji lain siswa,
 * komitmen asrama, serta ujian/kegiatan sekolah. Bentrok **tidak diblokir mati**:
 * koordinator dapat menembusnya dengan `force` dan alasannya dicatat, karena
 * kadang sesi darurat memang harus dijadwalkan di tengah jam pelajaran.
 */
class BkAppointmentService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkTimelineService $timeline,
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
        private readonly BkAccessService $access,
        private readonly StudentProviderInterface $students,
        private readonly BoardingProviderInterface $boarding,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $actor, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = BkAppointment::query()->with(['student:id,full_name,nis', 'counselor:id,name', 'case:id,case_number']);

        $this->access->scopeAppointments($query, $actor);

        return $query
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['counselor_id'] ?? null, fn ($q, $id) => $q->where('counselor_id', $id))
            ->when($filters['student_id'] ?? null, fn ($q, $id) => $q->where('student_id', $id))
            ->when($filters['case_id'] ?? null, fn ($q, $id) => $q->where('case_id', $id))
            ->when($filters['date'] ?? null, fn ($q, $date) => $q->whereDate('scheduled_start', $date))
            ->when(filter_var($filters['upcoming'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->upcoming())
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('scheduled_start', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('scheduled_start', '<=', $to))
            ->orderBy('scheduled_start')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Periksa bentrok jadwal.
     *
     * @return list<array{type: string, label: string, detail: ?string, start: ?string, end: ?string}>
     */
    public function detectConflicts(int $studentId, int $counselorUserId, string $start, string $end, ?int $ignoreAppointmentId = null): array
    {
        $conflicts = [];
        $date = substr($start, 0, 10);
        $startTime = substr($start, 11, 5);
        $endTime = substr($end, 11, 5);

        foreach ($this->academicCommitments($studentId, $date) as $commitment) {
            if ($this->overlaps($startTime, $endTime, $commitment['start'], $commitment['end'])) {
                $conflicts[] = ['type' => 'ACADEMIC_SCHEDULE', 'label' => 'Jadwal pelajaran', 'detail' => $commitment['label'], 'start' => $commitment['start'], 'end' => $commitment['end']];
            }
        }

        foreach ($this->boarding->commitments($studentId, $date) as $commitment) {
            if ($this->overlaps($startTime, $endTime, $commitment['start'], $commitment['end'])) {
                $conflicts[] = ['type' => 'BOARDING', 'label' => 'Kegiatan asrama', 'detail' => $commitment['label'], 'start' => $commitment['start'], 'end' => $commitment['end']];
            }
        }

        foreach ($this->existingAppointments($counselorUserId, null, $start, $end, $ignoreAppointmentId) as $row) {
            $conflicts[] = ['type' => 'COUNSELOR_BUSY', 'label' => 'Konselor sudah punya janji', 'detail' => $row, 'start' => $startTime, 'end' => $endTime];
        }

        foreach ($this->existingAppointments(null, $studentId, $start, $end, $ignoreAppointmentId) as $row) {
            $conflicts[] = ['type' => 'STUDENT_BUSY', 'label' => 'Siswa sudah punya janji lain', 'detail' => $row, 'start' => $startTime, 'end' => $endTime];
        }

        $availability = $this->availabilityWindow($counselorUserId, $date);

        if ($availability !== null && ($startTime < $availability['start'] || $endTime > $availability['end'])) {
            $conflicts[] = ['type' => 'OUTSIDE_AVAILABILITY', 'label' => 'Di luar jam ketersediaan konselor',
                'detail' => $availability['start'].'–'.$availability['end'], 'start' => $availability['start'], 'end' => $availability['end']];
        }

        if ($availability === null && BkCounselorAvailability::query()->whereHas('counselor', fn ($q) => $q->where('user_id', $counselorUserId))->exists()) {
            $conflicts[] = ['type' => 'NO_AVAILABILITY', 'label' => 'Konselor belum menetapkan ketersediaan pada hari itu', 'detail' => null, 'start' => null, 'end' => null];
        }

        foreach ($this->schoolEvents($date) as $event) {
            $conflicts[] = ['type' => $event['is_exam'] ? 'EXAM' : 'SCHOOL_EVENT', 'label' => $event['is_exam'] ? 'Ujian' : 'Kegiatan sekolah',
                'detail' => $event['title'], 'start' => null, 'end' => null];
        }

        return $conflicts;
    }

    /**
     * Buat janji temu.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): BkAppointment
    {
        $case = isset($data['case_id']) ? BkCase::query()->findOrFail($data['case_id']) : null;

        if ($case !== null) {
            $this->access->assertCanViewCase($actor, $case, 'Menjadwalkan janji temu');
        }

        $studentId = (int) ($data['student_id'] ?? $case?->student_id);
        $counselorId = (int) ($data['counselor_id'] ?? $case?->assigned_counselor_id ?? $actor->id);

        if (! $this->access->canManageAppointment($actor, new BkAppointment(['counselor_id' => $counselorId]))) {
            throw new BkValidationException(['counselor_id' => ['Hanya konselor terkait, koordinator, atau administrator BK yang dapat membuat janji untuk konselor ini.']]);
        }

        $start = $data['scheduled_start'];
        $end = $data['scheduled_end'];

        if (strtotime($end) <= strtotime($start)) {
            throw new BkValidationException(['scheduled_end' => ['Waktu selesai harus setelah waktu mulai.']]);
        }

        $conflicts = $this->detectConflicts($studentId, $counselorId, $start, $end);
        $force = (bool) ($data['force'] ?? false);

        if ($conflicts !== [] && ! $force) {
            throw new BkValidationException([
                'scheduled_start' => array_map(
                    fn (array $conflict): string => sprintf('%s: %s', $conflict['label'], $conflict['detail'] ?? '-'),
                    $conflicts,
                ),
            ]);
        }

        if ($conflicts !== [] && $force && ! $this->access->canMonitorWorkload($actor)) {
            throw new BkValidationException(['scheduled_start' => ['Hanya koordinator yang dapat menembus bentrok jadwal.']]);
        }

        return DB::transaction(function () use ($actor, $data, $case, $studentId, $counselorId, $start, $end, $conflicts): BkAppointment {
            $appointment = BkAppointment::query()->create([
                'appointment_number' => $this->numbers->next('BK_APPOINTMENT', 'BK-APT'),
                'case_id' => $case?->id,
                'referral_id' => $data['referral_id'] ?? $case?->source_referral_id,
                'student_id' => $studentId,
                'counselor_id' => $counselorId,
                'created_by' => $actor->id,
                'session_type' => $data['session_type'] ?? SessionType::INDIVIDUAL->value,
                'mode' => $data['mode'] ?? SessionMode::IN_PERSON->value,
                'scheduled_start' => $start,
                'scheduled_end' => $end,
                'location' => $data['location'] ?? null,
                'room_id' => $data['room_id'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'status' => AppointmentStatus::SCHEDULED->value,
                'attendance_status' => AttendanceStatus::UNKNOWN->value,
                'recurrence_rule' => $data['recurrence_rule'] ?? null,
                'conflict_state' => $conflicts === [] ? 'NONE' : 'OVERRIDDEN',
                'conflict_details' => $conflicts === [] ? null : $conflicts,
                'confidentiality_level' => $data['confidentiality_level']
                    ?? ($case?->confidentiality_level->value ?? ConfidentialityLevel::COUNSELOR_ONLY->value),
            ]);

            if ($case !== null) {
                $this->timeline->record(CaseEventType::SESSION_SCHEDULED, $actor, $case,
                    summary: 'Janji temu dijadwalkan pada '.date('d M Y H:i', strtotime($start)).'.',
                    related: $appointment,
                    payload: ['appointment_number' => $appointment->appointment_number, 'overridden' => $conflicts !== []]);
            }

            $this->audit->record(AuditAction::CREATE, $appointment, $actor, [
                'student_id' => $studentId,
                'changes' => ['scheduled_start' => ['from' => null, 'to' => $start]],
            ]);

            $this->notifyParticipants($appointment, NotificationEvent::APPOINTMENT_CREATED, 'Janji temu BK dijadwalkan pada '.date('d M Y H:i', strtotime($start)).'.');
            $this->expandRecurrence($actor, $appointment);

            return $appointment->refresh();
        });
    }

    /** Ubah waktu/lokasi janji (jadwal ulang). */
    public function reschedule(User $actor, BkAppointment $appointment, string $start, string $end, ?string $reason = null): BkAppointment
    {
        $this->assertManage($actor, $appointment, 'Menjadwalkan ulang janji temu');

        if (! in_array($appointment->status, [AppointmentStatus::SCHEDULED, AppointmentStatus::CONFIRMED, AppointmentStatus::RESCHEDULED], true)) {
            throw new BkValidationException(['status' => ['Janji ini sudah selesai/dibatalkan.']]);
        }

        $conflicts = $this->detectConflicts((int) $appointment->student_id, (int) $appointment->counselor_id, $start, $end, (int) $appointment->id);

        if ($conflicts !== []) {
            throw new BkValidationException(['scheduled_start' => array_map(
                fn (array $conflict): string => sprintf('%s: %s', $conflict['label'], $conflict['detail'] ?? '-'),
                $conflicts,
            )]);
        }

        $before = $appointment->scheduled_start?->toDateTimeString();

        $appointment->forceFill([
            'scheduled_start' => $start,
            'scheduled_end' => $end,
            'status' => AppointmentStatus::RESCHEDULED->value,
            'conflict_state' => 'NONE',
            'conflict_details' => null,
        ])->save();

        $this->audit->record(AuditAction::UPDATE, $appointment->refresh(), $actor, [
            'reason' => $reason,
            'changes' => ['scheduled_start' => ['from' => $before, 'to' => $start]],
        ]);

        if ($appointment->case !== null) {
            $this->timeline->record(CaseEventType::SESSION_SCHEDULED, $actor, $appointment->case,
                summary: 'Janji temu dijadwalkan ulang ke '.date('d M Y H:i', strtotime($start)).'.', related: $appointment);
        }

        $this->notifyParticipants($appointment, NotificationEvent::APPOINTMENT_CHANGED,
            'Jadwal janji temu BK berubah menjadi '.date('d M Y H:i', strtotime($start)).'.');

        return $appointment->refresh();
    }

    public function cancel(User $actor, BkAppointment $appointment, string $reason): BkAppointment
    {
        $this->assertManage($actor, $appointment, 'Membatalkan janji temu');

        $appointment->forceFill([
            'status' => AppointmentStatus::CANCELLED->value,
            'cancelled_by' => $actor->id,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ])->save();

        $this->audit->record(AuditAction::UPDATE, $appointment->refresh(), $actor, ['reason' => $reason]);

        $this->notifyParticipants($appointment, NotificationEvent::APPOINTMENT_CHANGED, 'Janji temu BK dibatalkan: '.$reason);

        return $appointment->refresh();
    }

    /** Tandai kehadiran (hadir/terlambat/tidak hadir) & tutup janji. */
    public function recordAttendance(User $actor, BkAppointment $appointment, AttendanceStatus $status, ?string $note = null): BkAppointment
    {
        $this->assertManage($actor, $appointment, 'Mencatat kehadiran janji temu');

        $appointment->forceFill([
            'attendance_status' => $status->value,
            'status' => match ($status) {
                AttendanceStatus::PRESENT, AttendanceStatus::LATE => AppointmentStatus::COMPLETED->value,
                AttendanceStatus::ABSENT => AppointmentStatus::NO_SHOW->value,
                default => $appointment->status->value,
            },
        ])->save();

        if ($appointment->case !== null && $status === AttendanceStatus::ABSENT) {
            $this->timeline->record(CaseEventType::STATUS_CHANGED, $actor, $appointment->case,
                summary: 'Siswa tidak hadir pada janji temu '.$appointment->appointment_number.'.',
                related: $appointment, payload: ['note' => $note]);
        }

        $this->audit->record(AuditAction::UPDATE, $appointment->refresh(), $actor, ['reason' => $note]);

        return $appointment->refresh();
    }

    /** Kirim pengingat untuk janji dalam 24 jam ke depan (perintah terjadwal). */
    public function sendReminders(int $hoursAhead = 24): int
    {
        $appointments = BkAppointment::query()
            ->whereIn('status', [AppointmentStatus::SCHEDULED->value, AppointmentStatus::CONFIRMED->value])
            ->whereNull('reminder_sent_at')
            ->whereBetween('scheduled_start', [now(), now()->addHours($hoursAhead)])
            ->get();

        foreach ($appointments as $appointment) {
            $this->notifyParticipants($appointment, NotificationEvent::APPOINTMENT_REMINDER,
                'Pengingat: janji temu BK pada '.$appointment->scheduled_start?->format('d M Y H:i').'.');

            $appointment->forceFill(['reminder_sent_at' => now()])->save();
        }

        return $appointments->count();
    }

    /** Buat janji berulang sesuai `recurrence_rule` (mis. WEEKLY:3). */
    private function expandRecurrence(User $actor, BkAppointment $appointment): void
    {
        $rule = $appointment->recurrence_rule;

        if ($rule === null || ! str_contains($rule, ':')) {
            return;
        }

        [$unit, $count] = explode(':', $rule, 2);
        $count = max(0, min(12, (int) $count));

        if ($count === 0) {
            return;
        }

        $start = $appointment->scheduled_start;
        $end = $appointment->scheduled_end;

        for ($i = 1; $i <= $count; $i++) {
            $nextStart = match (strtoupper($unit)) {
                'DAILY' => $start->copy()->addDays($i),
                'WEEKLY' => $start->copy()->addWeeks($i),
                'BIWEEKLY' => $start->copy()->addWeeks($i * 2),
                default => null,
            };

            if ($nextStart === null) {
                return;
            }

            $nextEnd = $end->copy()->addSeconds($nextStart->diffInSeconds($start, false));

            $conflicts = $this->detectConflicts((int) $appointment->student_id, (int) $appointment->counselor_id,
                $nextStart->toDateTimeString(), $nextEnd->toDateTimeString());

            if ($conflicts !== []) {
                continue;
            }

            BkAppointment::query()->create([
                'appointment_number' => $this->numbers->next('BK_APPOINTMENT', 'BK-APT'),
                'case_id' => $appointment->case_id,
                'referral_id' => $appointment->referral_id,
                'student_id' => $appointment->student_id,
                'counselor_id' => $appointment->counselor_id,
                'created_by' => $actor->id,
                'session_type' => $appointment->session_type->value,
                'mode' => $appointment->mode->value,
                'scheduled_start' => $nextStart,
                'scheduled_end' => $nextEnd,
                'location' => $appointment->location,
                'purpose' => $appointment->purpose,
                'status' => AppointmentStatus::SCHEDULED->value,
                'series_parent_id' => $appointment->id,
                'confidentiality_level' => $appointment->confidentiality_level->value,
            ]);
        }
    }

    private function assertManage(User $actor, BkAppointment $appointment, string $reason): void
    {
        if (! $this->access->canManageAppointment($actor, $appointment)) {
            throw new BkValidationException(['appointment' => ['Anda tidak berwenang mengubah janji temu ini.']]);
        }

        $this->audit->record(AuditAction::UPDATE, $appointment, $actor, ['reason' => $reason]);
    }

    /** @return list<array{label: string, start: string, end: string}> */
    private function academicCommitments(int $studentId, string $date): array
    {
        $snapshot = $this->students->snapshot($studentId);
        $classId = $snapshot['class_id'] ?? null;

        if ($classId === null) {
            return [];
        }

        $dayId = $this->dayIdFor($date);

        if ($dayId === null) {
            return [];
        }

        return DB::table('schedules')
            ->leftJoin('subjects', 'subjects.id', '=', 'schedules.subject_id')
            ->where('schedules.school_class_id', $classId)
            ->where('schedules.day_id', $dayId)
            ->get(['schedules.start_time', 'schedules.end_time', 'subjects.name as subject'])
            ->map(fn ($row): array => [
                'label' => (string) ($row->subject ?? 'Mata pelajaran'),
                'start' => substr((string) $row->start_time, 0, 5),
                'end' => substr((string) $row->end_time, 0, 5),
            ])
            ->all();
    }

    /** Pemetaan tanggal → id hari pada tabel `days` (nama Indonesia/Inggris). */
    private function dayIdFor(string $date): ?int
    {
        $name = date('l', strtotime($date));
        $indonesian = ['Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis',
            'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu'];
        $target = $indonesian[$name] ?? $name;

        $row = DB::table('days')->where('name', $target)->orWhere('name', $name)->first(['id']);

        return $row === null ? null : (int) $row->id;
    }

    private function availabilityWindow(int $counselorUserId, string $date): ?array
    {
        $dayOfWeek = (int) date('N', strtotime($date));

        $row = BkCounselorAvailability::query()
            ->whereHas('counselor', fn ($q) => $q->where('user_id', $counselorUserId))
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'start' => substr((string) $row->start_time, 0, 5),
            'end' => substr((string) $row->end_time, 0, 5),
        ];
    }

    /** @return list<string> */
    private function existingAppointments(?int $counselorId, ?int $studentId, string $start, string $end, ?int $ignoreId): array
    {
        $query = BkAppointment::query()
            ->whereIn('status', ['SCHEDULED', 'CONFIRMED', 'RESCHEDULED'])
            ->where('scheduled_start', '<', $end)
            ->where('scheduled_end', '>', $start)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId));

        if ($counselorId !== null) {
            $query->where('counselor_id', $counselorId);
        }

        if ($studentId !== null) {
            $query->where('student_id', $studentId);
        }

        return $query->get(['appointment_number', 'scheduled_start'])
            ->map(fn (BkAppointment $row): string => $row->appointment_number.' ('.$row->scheduled_start?->format('H:i').')')
            ->all();
    }

    /** @return list<array{title: string, is_exam: bool}> */
    private function schoolEvents(string $date): array
    {
        if (! DB::getSchemaBuilder()->hasTable('academic_events')) {
            return [];
        }

        return DB::table('academic_events')
            ->leftJoin('event_types', 'event_types.id', '=', 'academic_events.event_type_id')
            ->whereDate('academic_events.start_date', '<=', $date)
            ->whereDate('academic_events.end_date', '>=', $date)
            ->where('academic_events.is_active', true)
            ->whereNull('academic_events.deleted_at')
            ->get(['academic_events.title', 'event_types.name as type_name', 'academic_events.is_holiday'])
            ->map(fn ($row): array => [
                'title' => (string) $row->title,
                'is_exam' => str_contains(strtoupper((string) ($row->type_name ?? '')), 'UJIAN')
                    || str_contains(strtoupper((string) $row->title), 'UJIAN'),
            ])
            ->all();
    }

    private function notifyParticipants(BkAppointment $appointment, NotificationEvent $event, string $summary): void
    {
        $this->notifications->notifyUser((int) $appointment->counselor_id, $event, $summary, ['appointment_id' => $appointment->id]);

        $this->notifications->notifyGuardians((int) $appointment->student_id, $event, $summary, ['appointment_id' => $appointment->id]);
    }

    private function overlaps(string $startA, string $endA, string $startB, string $endB): bool
    {
        return $startA < $endB && $endA > $startB;
    }
}
