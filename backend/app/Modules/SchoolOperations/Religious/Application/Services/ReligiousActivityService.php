<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\{
    AttendanceGatewayInterface, StudentActivityGatewayInterface, TahfidzGatewayInterface
};
use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{
    Mosque, ReligiousActivity, ReligiousActivityAttendance, ReligiousActivityParticipant,
    ReligiousActivityRegistration, ReligiousActivitySession
};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kegiatan keagamaan (§32-§39, §91).
 *
 * Alur: Activity → Session → Registration → Approval → Participant → Attendance.
 * Tahfidz/Halaqah tetap memakai modulnya sendiri lewat TahfidzProviderInterface.
 */
class ReligiousActivityService
{
    public function __construct(
        protected ReligiousContext $context,
        protected AttendanceGatewayInterface $attendance,
        protected StudentActivityGatewayInterface $studentActivity,
        protected TahfidzGatewayInterface $tahfidz,
    ) {
    }

    public function create(array $payload): ReligiousActivity
    {
        $type = strtoupper((string) ($payload['activity_type'] ?? 'KAJIAN'));
        if (! array_key_exists($type, (array) config('religious.activity.types', []))) {
            throw ValidationException::withMessages([
                'activity_type' => 'Jenis kegiatan tidak dikenal: '.$type,
            ]);
        }

        $mosqueId = $payload['mosque_id'] ?? null;
        $activity = ReligiousActivity::query()->create([
            'code' => $payload['code'] ?? $this->context->nextCode('KGT', 'religious_activities'),
            'title' => $payload['title'],
            'activity_type' => $type,
            'description' => $payload['description'] ?? null,
            'category' => $payload['category'] ?? null,
            'mosque_id' => $mosqueId,
            'campus_id' => $payload['campus_id'] ?? ($mosqueId ? Mosque::query()->whereKey($mosqueId)->value('campus_id') : null),
            'organization_id' => $payload['organization_id'] ?? null,
            'location' => $payload['location'] ?? null,
            'area_id' => $payload['area_id'] ?? null,
            'instructor_personnel_id' => $payload['instructor_personnel_id'] ?? null,
            'instructor_name' => $payload['instructor_name'] ?? null,
            'target_population' => strtoupper((string) ($payload['target_population'] ?? 'ALL_STUDENTS')),
            'target_filter' => $payload['target_filter'] ?? null,
            'registration_mode' => strtoupper((string) ($payload['registration_mode'] ?? 'OPEN')),
            'capacity' => $payload['capacity'] ?? null,
            'waitlist_enabled' => (bool) ($payload['waitlist_enabled'] ?? true),
            'requires_approval' => (bool) ($payload['requires_approval'] ?? false),
            'is_mandatory' => (bool) ($payload['is_mandatory'] ?? false),
            'recurring' => (bool) ($payload['recurring'] ?? false),
            'recurrence_rule' => $payload['recurrence_rule'] ?? null,
            'assessment_enabled' => (bool) ($payload['assessment_enabled'] ?? false),
            'certificate_enabled' => (bool) ($payload['certificate_enabled'] ?? false),
            'lms_course_id' => $payload['lms_course_id'] ?? null,
            'linked_activity_id' => $payload['linked_activity_id'] ?? null,
            'starts_on' => $payload['starts_on'] ?? null,
            'ends_on' => $payload['ends_on'] ?? null,
            'status' => 'DRAFT',
            'created_by' => $this->context->actorId(),
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.activity.created', $activity, [
            'mosque_id' => $activity->mosque_id, 'type' => $type,
        ]);
        $this->context->publish('ReligiousActivityCreated', $activity, [
            'code' => $activity->code, 'type' => $type,
        ]);

        return $activity;
    }

    public function addSession(int $activityId, array $payload): ReligiousActivitySession
    {
        $activity = ReligiousActivity::query()->findOrFail($activityId);
        $number = (int) ($payload['session_number'] ?? (($activity->religiousActivitySessions()->max('session_number') ?? 0) + 1));

        $session = ReligiousActivitySession::query()->create([
            'activity_id' => $activity->getKey(),
            'session_number' => $number,
            'title' => $payload['title'] ?? null,
            'session_date' => $payload['session_date'] ?? now()->toDateString(),
            'start_time' => $payload['start_time'] ?? null,
            'end_time' => $payload['end_time'] ?? null,
            'location' => $payload['location'] ?? $activity->location,
            'area_id' => $payload['area_id'] ?? $activity->area_id,
            'instructor_personnel_id' => $payload['instructor_personnel_id'] ?? $activity->instructor_personnel_id,
            'mosque_id' => $payload['mosque_id'] ?? $activity->mosque_id,
            'topic' => $payload['topic'] ?? null,
            'capacity' => $payload['capacity'] ?? $activity->capacity,
            'status' => 'SCHEDULED',
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.activity.session_added', $session, ['activity_id' => $activity->getKey()]);

        return $session;
    }

    public function publish(int $activityId): ReligiousActivity
    {
        $activity = ReligiousActivity::query()->findOrFail($activityId);
        $activity->forceFill([
            'status' => 'PUBLISHED',
            'approved_by' => $this->context->actorId(),
        ])->save();

        $this->context->audit('religious.activity.published', $activity);
        $this->context->publish('ReligiousActivityStarted', $activity, ['code' => $activity->code]);

        return $activity;
    }

    /**
     * Pendaftaran peserta (§38, Aturan 7).
     * Bila kapasitas penuh dan waitlist aktif, pendaftar masuk daftar tunggu.
     */
    public function register(int $activityId, array $payload): ReligiousActivityRegistration
    {
        $activity = ReligiousActivity::query()->findOrFail($activityId);

        $existing = ReligiousActivityRegistration::query()->where('activity_id', $activityId)
            ->when(! empty($payload['student_id']), fn ($q) => $q->where('student_id', $payload['student_id']))
            ->when(! empty($payload['employee_id']), fn ($q) => $q->where('employee_id', $payload['employee_id']))
            ->whereNotIn('status', ['CANCELLED', 'REJECTED'])->first();

        if ($existing) {
            return $existing; // idempoten: tidak ada pendaftaran ganda
        }

        $waitlist = null;
        if ($activity->capacity) {
            $taken = ReligiousActivityRegistration::query()->where('activity_id', $activityId)
                ->whereIn('status', ['REGISTERED', 'APPROVED'])->count();

            if ($taken >= (int) $activity->capacity) {
                if (! $activity->waitlist_enabled) {
                    throw ValidationException::withMessages([
                        'activity_id' => 'Kapasitas kegiatan penuh dan daftar tunggu tidak diaktifkan (Aturan 7).',
                    ]);
                }
                $waitlist = $taken - (int) $activity->capacity + 1;
            }
        }

        $registration = ReligiousActivityRegistration::query()->create([
            'activity_id' => $activity->getKey(),
            'session_id' => $payload['session_id'] ?? null,
            'participant_type' => strtoupper((string) ($payload['participant_type'] ?? 'STUDENT')),
            'student_id' => $payload['student_id'] ?? null,
            'employee_id' => $payload['employee_id'] ?? null,
            'parent_id' => $payload['parent_id'] ?? null,
            'external_name' => $payload['external_name'] ?? null,
            'external_contact' => $payload['external_contact'] ?? null,
            'registered_at' => now(),
            'registration_channel' => strtoupper((string) ($payload['channel'] ?? 'ONLINE')),
            'status' => $waitlist ? 'WAITLISTED' : ($activity->requires_approval ? 'PENDING_APPROVAL' : 'REGISTERED'),
            'waitlist_position' => $waitlist,
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->context->audit('religious.activity.registered', $registration, [
            'activity_id' => $activity->getKey(), 'status' => $registration->status,
        ]);

        return $registration;
    }

    public function approveRegistration(int $registrationId, bool $approved = true, ?string $reason = null): ReligiousActivityRegistration
    {
        $registration = ReligiousActivityRegistration::query()->findOrFail($registrationId);
        $registration->forceFill([
            'status' => $approved ? 'REGISTERED' : 'REJECTED',
            'approval_status' => $approved ? 'APPROVED' : 'REJECTED',
            'approved_by' => $this->context->actorId(),
            'approved_at' => now(),
            'notes' => $reason ?? $registration->notes,
        ])->save();

        if ($approved) {
            $this->addParticipant($registration->activity_id, [
                'registration_id' => $registration->getKey(),
                'participant_type' => $registration->participant_type,
                'student_id' => $registration->student_id,
                'employee_id' => $registration->employee_id,
                'session_id' => $registration->session_id,
            ]);
        }

        $this->context->audit('religious.activity.registration_'.($approved ? 'approved' : 'rejected'), $registration, [], $reason);

        return $registration;
    }

    /** Tambahkan peserta (dari pendaftaran atau penugasan langsung). */
    public function addParticipant(int $activityId, array $payload): ReligiousActivityParticipant
    {
        $participant = ReligiousActivityParticipant::query()->create([
            'activity_id' => $activityId,
            'session_id' => $payload['session_id'] ?? null,
            'registration_id' => $payload['registration_id'] ?? null,
            'participant_type' => strtoupper((string) ($payload['participant_type'] ?? 'STUDENT')),
            'student_id' => $payload['student_id'] ?? null,
            'employee_id' => $payload['employee_id'] ?? null,
            'person_name' => $payload['person_name'] ?? null,
            'organization_id' => $payload['organization_id'] ?? null,
            'dormitory_id' => $payload['dormitory_id'] ?? null,
            'grade_level' => $payload['grade_level'] ?? null,
            'participant_role' => $payload['participant_role'] ?? null,
            'status' => 'ACTIVE',
            'joined_at' => now(),
            'halaqah_reference' => $payload['halaqah_reference'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        // Teruskan partisipasi ke Student Activity (§55, §82) bila tersedia.
        $this->studentActivity->recordParticipation([
            'student_id' => $participant->student_id,
            'reference_type' => 'RELIGIOUS_ACTIVITY',
            'reference_id' => $activityId,
            'role' => $participant->participant_role,
            'joined_at' => now()->toDateString(),
        ]);

        $this->context->audit('religious.activity.participant_added', $participant, ['activity_id' => $activityId]);

        return $participant;
    }

    /** Presensi kegiatan (§39) memakai Attendance Provider (§22). */
    public function recordAttendance(int $participantId, array $payload): array
    {
        $participant = ReligiousActivityParticipant::query()->findOrFail($participantId);
        $status = strtoupper((string) ($payload['status'] ?? 'PRESENT'));

        $record = ReligiousActivityAttendance::query()->create([
            'participant_id' => $participant->getKey(),
            'activity_id' => $participant->activity_id,
            'session_id' => $payload['session_id'] ?? $participant->session_id,
            'attendance_date' => $payload['attendance_date'] ?? now()->toDateString(),
            'status' => $status,
            'method' => strtoupper((string) ($payload['method'] ?? 'MANUAL')),
            'recorded_by' => $this->context->actorId(),
            'recorded_at' => now(),
            'minutes_late' => $payload['minutes_late'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        if ($payload['session_id'] ?? $participant->session_id) {
            $session = ReligiousActivitySession::query()->find($payload['session_id'] ?? $participant->session_id);
            if ($session) {
                $session->forceFill([
                    'attended_count' => ReligiousActivityAttendance::query()
                        ->where('session_id', $session->getKey())->whereIn('status', ['PRESENT', 'LATE'])->count(),
                ])->save();
            }
        }

        $this->context->audit('religious.activity.attendance_recorded', $record, ['status' => $status]);

        return [
            'recorded' => true,
            'attendance_id' => $record->getKey(),
            'status' => $status,
            'provider' => $this->attendance->available() ? 'AttendanceProvider' : 'LOCAL_ONLY',
        ];
    }

    /** Selesaikan kegiatan; peserta ditandai selesai (§33, §74). */
    public function complete(int $activityId, array $payload = []): array
    {
        $activity = ReligiousActivity::query()->findOrFail($activityId);
        $activity->forceFill(['status' => 'COMPLETED', 'ends_on' => $payload['ends_on'] ?? now()->toDateString()])->save();

        $completed = ReligiousActivityParticipant::query()->where('activity_id', $activityId)
            ->update(['completed_at' => now(), 'completion_status' => 'COMPLETED', 'updated_at' => now()]);

        $this->context->audit('religious.activity.completed', $activity, ['participants_completed' => $completed]);
        $this->context->publish('ReligiousActivityCompleted', $activity, ['code' => $activity->code]);

        return ['activity_id' => $activityId, 'status' => 'COMPLETED', 'participants_completed' => $completed];
    }

    public function roster(int $activityId): array
    {
        $participants = ReligiousActivityParticipant::query()->where('activity_id', $activityId)->limit(2000)->get();

        return array_merge([
            'activity_id' => $activityId,
            'total' => $participants->count(),
        ], (array) $participants->groupBy('status')->map->count()->all());
    }

    /** Kegiatan hari ini (dipakai dashboard & layar masjid §65). */
    public function today(?int $mosqueId = null, ?string $date = null): array
    {
        $date ??= now()->toDateString();

        return ReligiousActivitySession::query()->whereDate('session_date', $date)
            ->when($mosqueId, fn ($q) => $q->where('mosque_id', $mosqueId))
            ->orderBy('start_time')->get()
            ->map(fn (ReligiousActivitySession $s): array => [
                'id' => $s->getKey(),
                'activity_id' => $s->activity_id,
                'title' => $s->title ?? $s->topic,
                'start_time' => $s->start_time,
                'end_time' => $s->end_time,
                'location' => $s->location,
                'status' => $s->status,
            ])->all();
    }
}
