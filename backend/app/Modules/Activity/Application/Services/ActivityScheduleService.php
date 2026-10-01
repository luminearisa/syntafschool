<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Enums\AuditAction;
use App\Modules\Activity\Domain\Enums\SessionStatus;
use App\Modules\Activity\Domain\Exceptions\ActivityConflictException;
use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityAnnouncement;
use App\Modules\Activity\Models\ActivityFacilityBooking;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivitySchedule;
use App\Modules\Activity\Models\ActivitySession;
use App\Modules\Activity\Models\ActivitySessionMaterial;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Jadwal, sesi pertemuan, materi, dan pengumuman (§29–32, §30, §69, §101).
 *
 * Keputusan penting: **sesi nyata** dibuat dari jadwal berulang, dan pembuatan
 * itu idempoten lewat `generation_key`. Tanpa itu, menjalankan perintah
 * pembuatan sesi dua kali akan melipatgandakan pertemuan — dan kehadiran siswa
 * menjadi tidak dapat dipercaya.
 *
 * Pemesanan ruang tidak dikerjakan sendiri: setiap jadwal meminta fasilitas
 * lewat `FacilityProviderInterface`, dan bila bentrok, jadwal ditolak dengan
 * alasan yang menyebut pemesanan yang menghalangi.
 */
class ActivityScheduleService
{
    public function __construct(
        private readonly ActivityAuditService $audit,
        private readonly ActivityIntegrationService $integrations,
        private readonly ActivityAccessService $access,
    ) {}

    // -------------------------------------------------------------- jadwal

    public function createSchedule(ActivityProgram $program, array $attributes, User $actor): ActivitySchedule
    {
        $this->assertManage($program, $actor);

        $schedule = DB::transaction(function () use ($program, $attributes, $actor) {
            $schedule = ActivitySchedule::query()->create([
                'program_id' => $program->id,
                'period_id' => $attributes['period_id'] ?? $program->period_id,
                'day_of_week' => (int) $attributes['day_of_week'],
                'start_time' => $attributes['start_time'],
                'end_time' => $attributes['end_time'],
                'room_id' => $attributes['room_id'] ?? null,
                'coach_employee_id' => $attributes['coach_employee_id'] ?? $program->coach_employee_id,
                'location' => $attributes['location'] ?? null,
                'session_type' => $attributes['session_type'] ?? 'REGULAR',
                'is_recurring' => (bool) ($attributes['is_recurring'] ?? true),
                'effective_start' => $attributes['effective_start'] ?? $program->starts_on?->toDateString(),
                'effective_end' => $attributes['effective_end'] ?? $program->ends_on?->toDateString(),
                'status' => 'ACTIVE',
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            if ($schedule->room_id !== null && ($attributes['book_facility'] ?? true) === true) {
                $this->requestFacility($schedule, $program, $actor, $attributes['facility_date'] ?? null);
            }

            $this->audit->record(AuditAction::SCHEDULE_CREATED, [
                'subject' => $schedule,
                'program_id' => $program->id,
                'after' => $schedule,
                'actor' => $actor->id,
            ]);

            return $schedule;
        });

        return $schedule->refresh();
    }

    public function updateSchedule(ActivitySchedule $schedule, array $attributes, User $actor): ActivitySchedule
    {
        $program = $schedule->program;

        if ($program === null) {
            throw ActivityValidationException::withErrors(['Jadwal tidak memiliki program.']);
        }

        $this->assertManage($program, $actor);

        $before = $schedule->attributesToArray();
        $schedule->fill(collect($attributes)->only([
            'day_of_week', 'start_time', 'end_time', 'room_id', 'coach_employee_id', 'location',
            'session_type', 'is_recurring', 'effective_start', 'effective_end', 'status', 'notes',
        ])->all())->save();

        $this->audit->record(AuditAction::SCHEDULE_CHANGED, [
            'subject' => $schedule,
            'program_id' => $program->id,
            'before' => $before,
            'after' => $schedule,
            'actor' => $actor->id,
        ]);

        return $schedule->refresh();
    }

    public function deleteSchedule(ActivitySchedule $schedule, User $actor): void
    {
        $program = $schedule->program;

        if ($program !== null) {
            $this->assertManage($program, $actor);
        }

        if ($schedule->sessions()->whereIn('status', [SessionStatus::SCHEDULED->value, SessionStatus::OPEN->value])->exists()) {
            throw ActivityConflictException::make('Jadwal masih memiliki sesi yang belum berjalan — batalkan sesinya lebih dahulu.');
        }

        $schedule->delete();
    }

    // --------------------------------------------------------------- sesi

    /**
     * Buat sesi dari jadwal berulang pada rentang tanggal tertentu.
     *
     * @return array{created: int, skipped: int, sessions: list<int>}
     */
    public function generateSessions(ActivityProgram $program, ?string $from = null, ?string $to = null, ?User $actor = null): array
    {
        $from = $from ?? ($program->starts_on?->toDateString() ?? now()->toDateString());
        $to = $to ?? ($program->ends_on?->toDateString() ?? now()->addMonths(3)->toDateString());

        if ($from > $to) {
            throw ActivityValidationException::withErrors(['Rentang tanggal pembuatan sesi tidak valid.']);
        }

        $schedules = $program->schedules()->where('status', 'ACTIVE')->where('is_recurring', true)->get();

        $created = 0;
        $skipped = 0;
        $ids = [];

        foreach ($schedules as $schedule) {
            $cursor = CarbonImmutable::parse($from);
            $end = CarbonImmutable::parse($to);

            while ($cursor->lte($end)) {
                if ($cursor->isoWeekday() === (int) $schedule->day_of_week) {
                    $generationKey = 'program|'.$program->id.'|schedule|'.$schedule->id.'|date|'.$cursor->toDateString();

                    $exists = ActivitySession::query()->where('generation_key', $generationKey)->exists();

                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    $session = ActivitySession::query()->create([
                        'program_id' => $program->id,
                        'period_id' => $schedule->period_id ?? $program->period_id,
                        'schedule_id' => $schedule->id,
                        'session_number' => $this->nextSessionNumber($program),
                        'date' => $cursor->toDateString(),
                        'start_time' => $schedule->start_time,
                        'end_time' => $schedule->end_time,
                        'room_id' => $schedule->room_id,
                        'location' => $schedule->location,
                        'coach_employee_id' => $schedule->coach_employee_id,
                        'status' => SessionStatus::SCHEDULED->value,
                        'created_by' => $actor?->id,
                        'generation_key' => $generationKey,
                    ]);

                    $ids[] = (int) $session->id;
                    $created++;
                }

                $cursor = $cursor->addDay();
            }
        }

        if ($created > 0) {
            $this->audit->record(AuditAction::SESSION_CREATED, [
                'subject' => $program,
                'program_id' => $program->id,
                'after' => ['created' => $created, 'from' => $from, 'to' => $to],
                'actor' => $actor?->id,
            ]);
        }

        return ['created' => $created, 'skipped' => $skipped, 'sessions' => $ids];
    }

    public function createSession(ActivityProgram $program, array $attributes, User $actor): ActivitySession
    {
        $this->assertManage($program, $actor);

        $session = ActivitySession::query()->create([
            'program_id' => $program->id,
            'period_id' => $attributes['period_id'] ?? $program->period_id,
            'schedule_id' => $attributes['schedule_id'] ?? null,
            'session_number' => $attributes['session_number'] ?? $this->nextSessionNumber($program),
            'date' => $attributes['date'],
            'start_time' => $attributes['start_time'],
            'end_time' => $attributes['end_time'],
            'room_id' => $attributes['room_id'] ?? null,
            'location' => $attributes['location'] ?? null,
            'coach_employee_id' => $attributes['coach_employee_id'] ?? $program->coach_employee_id,
            'topic' => $attributes['topic'] ?? null,
            'objective' => $attributes['objective'] ?? null,
            'status' => $attributes['status'] ?? SessionStatus::SCHEDULED->value,
            'notes' => $attributes['notes'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->audit->record(AuditAction::SESSION_CREATED, [
            'subject' => $session,
            'program_id' => $program->id,
            'after' => $session,
            'actor' => $actor->id,
        ]);

        return $session;
    }

    public function updateSession(ActivitySession $session, array $attributes, User $actor): ActivitySession
    {
        $program = $session->program;

        if ($program !== null) {
            $this->assertManage($program, $actor);
        }

        if ($session->attendance_locked_at !== null && ! $actor->can('activity.attendance.override')) {
            throw ActivityConflictException::make('Kehadiran sesi ini sudah dikunci — perubahan jadwal tidak diizinkan.');
        }

        $before = $session->attributesToArray();

        $session->fill(collect($attributes)->only([
            'date', 'start_time', 'end_time', 'room_id', 'location', 'coach_employee_id',
            'topic', 'objective', 'status', 'notes',
        ])->all())->save();

        $this->audit->record(AuditAction::SCHEDULE_CHANGED, [
            'subject' => $session,
            'program_id' => $session->program_id,
            'before' => $before,
            'after' => $session,
            'actor' => $actor->id,
        ]);

        return $session->refresh();
    }

    public function cancelSession(ActivitySession $session, User $actor, string $reason): ActivitySession
    {
        if (trim($reason) === '') {
            throw ActivityValidationException::withErrors(['Alasan pembatalan sesi wajib diisi.']);
        }

        $program = $session->program;

        if ($program !== null) {
            $this->assertManage($program, $actor);
        }

        $session->forceFill([
            'status' => SessionStatus::CANCELLED->value,
            'cancellation_reason' => $reason,
        ])->save();

        $this->audit->record(AuditAction::SESSION_CANCELLED, [
            'subject' => $session,
            'program_id' => $session->program_id,
            'after' => ['reason' => $reason],
            'actor' => $actor->id,
        ]);

        if ($session->room_id !== null) {
            $this->releaseFacility($session, $actor);
        }

        return $session->refresh();
    }

    /** Daftar sesi untuk layar ruang kerja pembina. */
    public function sessions(array $filters = []): array
    {
        return ActivitySession::query()
            ->with(['program:id,name', 'room:id,name', 'coach:id,full_name'])
            ->withCount(['attendance', 'materials'])
            ->when(isset($filters['program_id']), fn ($q) => $q->where('program_id', $filters['program_id']))
            ->when(isset($filters['from']), fn ($q) => $q->where('date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->where('date', '<=', $filters['to']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('date')
            ->orderBy('start_time')
            ->limit((int) ($filters['limit'] ?? 100))
            ->get()
            ->map(fn (ActivitySession $session) => $this->present($session))
            ->all();
    }

    public function present(ActivitySession $session): array
    {
        return [
            'id' => (int) $session->id,
            'program_id' => (int) $session->program_id,
            'program_name' => $session->program?->name,
            'session_number' => (int) $session->session_number,
            'date' => $session->date?->toDateString(),
            'start_time' => substr((string) $session->start_time, 0, 5),
            'end_time' => substr((string) $session->end_time, 0, 5),
            'room' => $session->room?->name,
            'location' => $session->location,
            'coach' => $session->coach?->full_name,
            'topic' => $session->topic,
            'status' => $session->status?->value,
            'status_label' => $session->status?->label(),
            'attendance_count' => (int) ($session->attendance_count ?? 0),
            'materials_count' => (int) ($session->materials_count ?? 0),
            'attendance_locked' => $session->attendance_locked_at !== null,
        ];
    }

    // -------------------------------------------------------------- materi

    public function addMaterial(ActivityProgram $program, array $attributes, User $actor): ActivitySessionMaterial
    {
        $this->assertManage($program, $actor);

        $material = ActivitySessionMaterial::query()->create([
            'program_id' => $program->id,
            'session_id' => $attributes['session_id'] ?? null,
            'title' => $attributes['title'],
            'type' => $attributes['type'] ?? 'DOCUMENT',
            'description' => $attributes['description'] ?? null,
            'url' => $attributes['url'] ?? null,
            'file_path' => $attributes['file_path'] ?? null,
            'lms_reference' => $attributes['lms_reference'] ?? null,
            'is_published' => (bool) ($attributes['is_published'] ?? false),
            'published_at' => ($attributes['is_published'] ?? false) ? now() : null,
            'created_by' => $actor->id,
        ]);

        if ($material->lms_reference !== null) {
            $this->integrations->call('LMS', 'material.linked', [
                'subject_type' => ActivitySessionMaterial::class,
                'subject_id' => $material->id,
                'reference' => $material->lms_reference,
            ], fn () => ['status' => 'OK', 'reference' => $material->lms_reference]);
        }

        return $material;
    }

    public function materials(ActivityProgram $program): array
    {
        return $program->sessions()
            ->with('materials')
            ->get()
            ->flatMap(fn (ActivitySession $session) => $session->materials)
            ->merge(ActivitySessionMaterial::query()->where('program_id', $program->id)->whereNull('session_id')->get())
            ->map(fn (ActivitySessionMaterial $material) => [
                'id' => (int) $material->id,
                'session_id' => $material->session_id === null ? null : (int) $material->session_id,
                'title' => $material->title,
                'type' => $material->type?->value,
                'type_label' => $material->type?->label(),
                'url' => $material->url,
                'lms_reference' => $material->lms_reference,
                'is_published' => (bool) $material->is_published,
            ])
            ->unique('id')
            ->values()
            ->all();
    }

    // ---------------------------------------------------------- pengumuman

    public function announce(ActivityProgram $program, array $attributes, User $actor): ActivityAnnouncement
    {
        $this->assertManage($program, $actor);

        $announcement = ActivityAnnouncement::query()->create([
            'program_id' => $program->id,
            'title' => $attributes['title'],
            'body' => $attributes['body'],
            'audience' => $attributes['audience'] ?? 'MEMBERS',
            'notify' => (bool) ($attributes['notify'] ?? true),
            'attachments' => $attributes['attachments'] ?? null,
            'published_at' => $attributes['published_at'] ?? now(),
            'expires_at' => $attributes['expires_at'] ?? null,
            'created_by' => $actor->id,
        ]);

        if ($announcement->notify && $announcement->published_at !== null) {
            $this->integrations->call('EVENT', 'announcement.published', [
                'subject_type' => ActivityAnnouncement::class,
                'subject_id' => $announcement->id,
            ], fn () => ['status' => 'OK']);
        }

        return $announcement;
    }

    public function announcements(ActivityProgram $program): array
    {
        return ActivityAnnouncement::query()
            ->with('creator:id,name')
            ->where('program_id', $program->id)
            ->orderByDesc('published_at')
            ->get()
            ->map(fn (ActivityAnnouncement $announcement) => [
                'id' => (int) $announcement->id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'audience' => $announcement->audience?->value,
                'published_at' => $announcement->published_at?->toIso8601String(),
                'author' => $announcement->creator?->name,
            ])
            ->all();
    }

    // ------------------------------------------------------------ fasilitas

    /** Minta pemesanan ruang untuk satu jadwal (§30, §64). */
    public function requestFacility(ActivitySchedule $schedule, ActivityProgram $program, User $actor, ?string $date = null): ActivityFacilityBooking
    {
        $date = $date ?? ($schedule->effective_start?->toDateString() ?? now()->toDateString());

        $booking = ActivityFacilityBooking::query()->firstOrCreate(
            ['idempotency_key' => 'facility|schedule|'.$schedule->id.'|'.$date],
            [
                'program_id' => $program->id,
                'schedule_id' => $schedule->id,
                'room_id' => $schedule->room_id,
                'date' => $date,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'status' => 'REQUESTED',
                'requested_by' => $actor->id,
                'requested_at' => now(),
            ],
        );

        $result = $this->integrations->call('FACILITY', 'book', [
            'subject_type' => ActivityFacilityBooking::class,
            'subject_id' => $booking->id,
            'room_id' => $schedule->room_id,
        ], fn () => $this->integrations->facilities()->book([
            'room_id' => (int) $schedule->room_id,
            'date' => $date,
            'start_time' => $schedule->start_time,
            'end_time' => $schedule->end_time,
            'title' => $program->name,
            'employee_id' => $schedule->coach_employee_id,
            'purpose' => 'ACTIVITY',
        ]));

        $booking->forceFill([
            'status' => $result['status'] ?? 'REQUESTED',
            'external_reference' => $result['reference'] ?? null,
            'conflict_reason' => $result['conflict']['reason'] ?? null,
            'confirmed_at' => ($result['status'] ?? null) === 'CONFIRMED' ? now() : null,
        ])->save();

        $this->audit->record(AuditAction::FACILITY_REQUESTED, [
            'subject' => $booking,
            'program_id' => $program->id,
            'after' => $booking,
            'actor' => $actor->id,
        ]);

        if (($result['status'] ?? null) === 'CONFLICT' && (bool) config('activity.facility.block_on_conflict', true)) {
            throw ActivityConflictException::make(
                $result['conflict']['reason'] ?? 'Fasilitas bentrok pada jam tersebut.',
                $result['conflict'] ?? [],
            );
        }

        return $booking->refresh();
    }

    protected function releaseFacility(ActivitySession $session, User $actor): void
    {
        $bookings = ActivityFacilityBooking::query()->where('session_id', $session->id)->get();

        foreach ($bookings as $booking) {
            if ($booking->external_reference !== null) {
                $this->integrations->call('FACILITY', 'cancel', [
                    'subject_type' => ActivityFacilityBooking::class,
                    'subject_id' => $booking->id,
                    'reference' => $booking->external_reference,
                ], fn () => ['status' => 'OK', 'cancelled' => $this->integrations->facilities()->cancel((string) $booking->external_reference)]);
            }

            $booking->forceFill(['status' => 'CANCELLED'])->save();
        }
    }

    protected function nextSessionNumber(ActivityProgram $program): int
    {
        return (int) $program->sessions()->max('session_number') + 1;
    }

    protected function assertManage(ActivityProgram $program, User $actor): void
    {
        if (! $this->access->managesProgram($actor, $program)) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang mengelola jadwal program ini.');
        }
    }
}
