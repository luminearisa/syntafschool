<?php

namespace App\Modules\LMS\Application\Services;

use App\Models\User;
use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Application\Support\LiveMeetingRegistry;
use App\Modules\LMS\Domain\Enums\ActorRole;
use App\Modules\LMS\Domain\Enums\GamificationEventType;
use App\Modules\LMS\Domain\Enums\LearningEventType;
use App\Modules\LMS\Domain\Enums\LiveAttendanceStatus;
use App\Modules\LMS\Domain\Enums\LiveSessionProvider;
use App\Modules\LMS\Domain\Enums\LiveSessionStatus;
use App\Modules\LMS\Domain\Enums\LiveSessionType;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsLiveSession;
use App\Modules\LMS\Models\LmsLiveSessionAttendance;
use App\Modules\LMS\Models\LmsLiveSessionRecording;
use App\Modules\LMS\Models\LmsUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kelas langsung, jam konsultasi, kehadiran, dan rekaman (§13–§17).
 *
 * Prinsip yang dipegang:
 *  - Provider diselesaikan lewat `LiveMeetingRegistry`: hanya tautan yang
 *    disimpan, tidak ada SDK yang di-hardcode (§17). Karena itu kelas tetap
 *    dapat dijadwalkan walau sekolah belum punya kredensial Zoom/Meet.
 *  - Kehadiran TIDAK pernah diklaim otomatis. Nilai `source` menegaskan asal
 *    data: MANUAL (guru), SELF (siswa menandai diri saat gabung), atau PROVIDER
 *    (hanya bila provider benar-benar mengirim data) (§15).
 *  - Sesi yang belum diterbitkan (`published_at`) tidak terlihat siswa; guru
 *    selalu melihat seluruh sesi course.
 *  - `passcode`/`host_url` tidak pernah dibocorkan ke siswa pada payload.
 */
class LiveSessionService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly LearningEventService $events,
        private readonly GamificationService $gamification,
        private readonly LmsNotifier $notifier,
        private readonly LiveMeetingRegistry $providers,
    ) {}

    // ------------------------------------------------------------------ metadata untuk UI

    /** @return list<array<string, mixed>> */
    public function types(): array
    {
        return array_map(fn (LiveSessionType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'icon' => $type->icon(),
        ], LiveSessionType::cases());
    }

    /** @return list<array<string, mixed>> */
    public function providers(): array
    {
        return $this->providers->describe();
    }

    /** @return list<array<string, mixed>> */
    public function attendanceStatuses(): array
    {
        return array_map(fn (LiveAttendanceStatus $status) => [
            'value' => $status->value,
            'label' => $status->label(),
            'counts_as_present' => $status->countsAsPresent(),
        ], LiveAttendanceStatus::cases());
    }

    // ------------------------------------------------------------------ daftar sesi

    /**
     * Sesi pada satu course.
     *
     * @param  array<string, mixed>  $filters  type, status, from, to, upcoming
     * @return list<array<string, mixed>>
     */
    public function forCourse(LmsCourse $course, bool $forStaff = false, array $filters = []): array
    {
        $sessions = LmsLiveSession::query()
            ->where('course_id', $course->id)
            ->when(! $forStaff, fn ($query) => $query->whereNotNull('published_at'))
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['from']), fn ($query) => $query->where('start_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->where('start_at', '<=', $filters['to']))
            ->when(($filters['upcoming'] ?? false) === true, fn ($query) => $query->where('start_at', '>=', now()->subMinutes($this->graceMinutes())))
            ->orderBy('start_at')
            ->get();

        // Rekap kehadiran diambil sekali untuk seluruh daftar — bukan satu
        // query per sesi saat guru membuka halaman kelas langsung.
        $summaries = $forStaff
            ? $this->attendanceSummaries($sessions->pluck('id')->map(fn ($id) => (int) $id)->all())
            : [];

        return $sessions
            ->map(fn (LmsLiveSession $session) => $this->row($session, $forStaff, $summaries[(int) $session->id] ?? null))
            ->all();
    }

    /** Sesi terdekat untuk dasbor siswa, lintas course yang diikuti. */
    /**
     * Payload satu sesi untuk UI (guru mendapat rekap kehadiran).
     *
     * @return array<string, mixed>
     */
    public function payload(LmsLiveSession $session, bool $forStaff = false): array
    {
        $summary = $forStaff ? $this->attendanceSummary($session) : null;

        return $this->row($session->fresh(), $forStaff, $summary);
    }

    /**
     * Sesi yang sedang berlangsung (LIVE) pada course yang diikuti seorang siswa.
     *
     * @return list<array<string, mixed>>
     */
    public function liveNowForUser(int $userId, int $limit = 5): array
    {
        $courseIds = LmsEnrollment::query()
            ->where('user_id', $userId)
            ->where('role', 'STUDENT')
            ->pluck('course_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($courseIds === []) {
            return [];
        }

        return LmsLiveSession::query()
            ->with('course:id,name,code')
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('published_at')
            ->where('status', LiveSessionStatus::LIVE->value)
            ->orderBy('start_at')
            ->limit($limit)
            ->get()
            ->map(fn (LmsLiveSession $session) => $this->row($session, false))
            ->all();
    }

    /**
     * Sesi berikutnya yang belum selesai pada sebuah course (§13).
     *
     * @return array<string, mixed>|null
     */
    public function nextSession(LmsCourse $course): ?array
    {
        $session = LmsLiveSession::query()
            ->where('course_id', $course->id)
            ->whereNotNull('published_at')
            ->whereNotIn('status', [LiveSessionStatus::CANCELLED->value, LiveSessionStatus::COMPLETED->value])
            ->where('start_at', '>=', now()->subMinutes($this->graceMinutes()))
            ->orderBy('start_at')
            ->first();

        return $session === null ? null : $this->row($session, false);
    }

    public function forStudent(int $userId, int $limit = 5): array
    {
        $courseIds = LmsEnrollment::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['ACTIVE', 'COMPLETED'])
            ->pluck('course_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($courseIds === []) {
            return [];
        }

        return LmsLiveSession::query()
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('published_at')
            ->whereIn('status', [LiveSessionStatus::SCHEDULED->value, LiveSessionStatus::LIVE->value])
            ->where('start_at', '>=', now()->subMinutes($this->graceMinutes()))
            ->orderBy('start_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (LmsLiveSession $session) => $this->row($session, false))
            ->all();
    }

    public function find(int $id): LmsLiveSession
    {
        $session = LmsLiveSession::query()->with(['course', 'section'])->find($id);

        if ($session === null) {
            LmsFault::notFound('Sesi kelas langsung tidak ditemukan.');
        }

        return $this->refreshStatus($session);
    }

    /** Sesi yang sedang berlangsung atau akan mulai dalam jendela gabung. */
    public function liveNowForCourse(LmsCourse $course): array
    {
        return LmsLiveSession::query()
            ->where('course_id', $course->id)
            ->whereNotNull('published_at')
            ->where('status', LiveSessionStatus::LIVE->value)
            ->orderBy('start_at')
            ->get()
            ->map(fn (LmsLiveSession $session) => $this->row($session, false))
            ->all();
    }

    // ------------------------------------------------------------------ pengelolaan sesi

    /**
     * Buat sesi baru (belum terlihat siswa sampai `publish()`).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(LmsCourse $course, array $data, LmsActor $actor): LmsLiveSession
    {
        if (trim((string) ($data['title'] ?? '')) === '') {
            LmsFault::invalid('Judul sesi wajib diisi.', 'title');
        }

        $startAt = Carbon::parse($data['start_at'] ?? now());
        $endAt = isset($data['end_at']) && $data['end_at'] !== null ? Carbon::parse($data['end_at']) : null;

        if ($endAt !== null && $endAt->lessThanOrEqualTo($startAt)) {
            LmsFault::invalid('Waktu selesai harus setelah waktu mulai.', 'end_at');
        }

        $provider = LiveSessionProvider::from((string) ($data['provider'] ?? config('lms.live.default_provider', LiveSessionProvider::EXTERNAL->value)));

        $session = LmsLiveSession::query()->create([
            'course_id' => $course->id,
            'section_id' => $data['section_id'] ?? null,
            'activity_id' => $data['activity_id'] ?? null,
            'type' => LiveSessionType::from((string) ($data['type'] ?? LiveSessionType::LIVE_CLASS->value)),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'provider' => $provider,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'timezone' => $data['timezone'] ?? config('app.timezone', 'Asia/Jakarta'),
            'meeting_url' => $data['meeting_url'] ?? null,
            'meeting_id' => $data['meeting_id'] ?? null,
            'passcode' => $data['passcode'] ?? null,
            'host_url' => $data['host_url'] ?? null,
            'status' => LiveSessionStatus::SCHEDULED,
            'capacity' => $data['capacity'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? $this->durationMinutes($startAt, $endAt),
            'recurring' => (bool) ($data['recurring'] ?? false),
            'recurrence_rule' => $data['recurrence_rule'] ?? null,
            'attendance_enabled' => (bool) ($data['attendance_enabled'] ?? true),
            'is_recorded' => (bool) ($data['is_recorded'] ?? false),
            'settings' => $data['settings'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->audit('LMS_LIVE_SESSION_CREATED', $session, ['course_id' => (int) $course->id]);

        return $this->refreshStatus($session);
    }

    /** @param array<string, mixed> $data */
    public function update(LmsLiveSession $session, array $data, LmsActor $actor): LmsLiveSession
    {
        $fill = [];

        foreach (['title', 'description', 'meeting_url', 'meeting_id', 'passcode', 'host_url', 'recurrence_rule', 'timezone'] as $key) {
            if (array_key_exists($key, $data)) {
                $fill[$key] = $data[$key];
            }
        }

        foreach (['section_id', 'activity_id', 'capacity', 'duration_minutes'] as $key) {
            if (array_key_exists($key, $data)) {
                $fill[$key] = $data[$key];
            }
        }

        foreach (['attendance_enabled', 'is_recorded', 'recurring'] as $key) {
            if (array_key_exists($key, $data)) {
                $fill[$key] = (bool) $data[$key];
            }
        }

        if (array_key_exists('settings', $data)) {
            $fill['settings'] = $data['settings'];
        }

        if (array_key_exists('type', $data)) {
            $fill['type'] = LiveSessionType::from((string) $data['type']);
        }

        if (array_key_exists('provider', $data)) {
            $fill['provider'] = LiveSessionProvider::from((string) $data['provider']);
        }

        if (array_key_exists('start_at', $data)) {
            $fill['start_at'] = Carbon::parse($data['start_at']);
        }

        if (array_key_exists('end_at', $data)) {
            $fill['end_at'] = $data['end_at'] === null ? null : Carbon::parse($data['end_at']);
        }

        $session->fill($fill);

        if ($session->end_at !== null && $session->end_at->lessThanOrEqualTo($session->start_at)) {
            LmsFault::invalid('Waktu selesai harus setelah waktu mulai.', 'end_at');
        }

        $session->save();

        $this->audit('LMS_LIVE_SESSION_UPDATED', $session, ['course_id' => (int) $session->course_id]);

        return $this->refreshStatus($session);
    }

    /** Terbitkan sesi ke siswa dan kirim pemberitahuan (§14). */
    public function publish(LmsLiveSession $session, LmsActor $actor): LmsLiveSession
    {
        if ($session->published_at !== null) {
            return $session;
        }

        $session->forceFill([
            'published_at' => now(),
            'status' => LiveSessionStatus::SCHEDULED,
        ])->save();

        $course = $session->course;

        if ($course !== null) {
            $recipients = LmsEnrollment::query()
                ->where('course_id', $course->id)
                ->whereIn('status', ['ACTIVE', 'COMPLETED'])
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $this->notifier->liveSessionScheduled($recipients, [
                'title' => 'Kelas langsung: '.$session->title,
                'message' => $session->type->label().' dijadwalkan '
                    .($session->start_at?->copy()->setTimezone($session->timezone)->translatedFormat('d M Y H:i') ?? '-')
                    .' ('.$session->timezone.').',
                'url' => '/lms/courses/'.$course->id.'/live',
                'course_id' => (int) $course->id,
                'course_name' => (string) $course->name,
                'session' => [
                    'id' => (int) $session->id,
                    'title' => $session->title,
                    'type' => $session->type->value,
                    'start_at' => $session->start_at?->toIso8601String(),
                    'end_at' => $session->end_at?->toIso8601String(),
                    'timezone' => $session->timezone,
                ],
            ]);
        }

        $this->audit('LMS_LIVE_SESSION_PUBLISHED', $session, ['course_id' => (int) $session->course_id]);

        return $session;
    }

    public function cancel(LmsLiveSession $session, string $reason, LmsActor $actor): LmsLiveSession
    {
        if ($session->status === LiveSessionStatus::CANCELLED) {
            return $session;
        }

        $session->fill([
            'status' => LiveSessionStatus::CANCELLED,
            'settings' => array_merge((array) $session->settings, ['cancel_reason' => $reason]),
        ])->save();

        $this->audit('LMS_LIVE_SESSION_CANCELLED', $session, [
            'course_id' => (int) $session->course_id,
            'reason' => $reason,
        ]);

        return $session;
    }

    public function delete(LmsLiveSession $session, LmsActor $actor): void
    {
        $this->audit('LMS_LIVE_SESSION_DELETED', $session, [
            'course_id' => (int) $session->course_id,
            'title' => $session->title,
        ]);

        $session->delete();
    }

    /**
     * Selaraskan status dengan waktu berjalan (§14).
     *
     * Hanya sesi yang sudah diterbitkan dan masih berstatus SCHEDULED/LIVE yang
     * bergerak; sesi yang dibatalkan tidak pernah dihidupkan kembali sistem.
     */
    public function refreshStatus(LmsLiveSession $session): LmsLiveSession
    {
        if ($session->published_at === null || $session->status === LiveSessionStatus::CANCELLED) {
            return $session;
        }

        if ($session->status === LiveSessionStatus::COMPLETED) {
            return $session;
        }

        $now = now();
        $end = $session->end_at ?? $session->start_at?->copy()->addMinutes((int) ($session->duration_minutes ?? 60));

        $status = match (true) {
            $session->start_at !== null && $now->greaterThanOrEqualTo($session->start_at)
                && ($end === null || $now->lessThan($end)) => LiveSessionStatus::LIVE,
            $end !== null && $now->greaterThanOrEqualTo($end) => LiveSessionStatus::COMPLETED,
            default => LiveSessionStatus::SCHEDULED,
        };

        if ($status !== $session->status) {
            $session->forceFill(['status' => $status])->save();
        }

        return $session;
    }

    // ------------------------------------------------------------------ gabung sesi

    /**
     * Apakah siswa/guru boleh bergabung sekarang, beserta alasannya.
     *
     * @return array<string, mixed>
     */
    public function joinInfo(LmsLiveSession $session, LmsActor $actor, bool $asHost = false): array
    {
        $session = $this->refreshStatus($session);

        $window = $this->joinWindow($session);
        $provider = $this->providers->for($session->provider);

        $url = $asHost
            ? $provider->joinUrl($session, true)
            : $provider->joinUrl($session, false);

        $enrolled = $session->course_id === null ? false : $this->isEnrolled($session, $actor);

        $reason = match (true) {
            $session->published_at === null => 'Sesi belum diterbitkan guru.',
            $session->status === LiveSessionStatus::CANCELLED => 'Sesi dibatalkan.',
            ! $enrolled && ! $asHost => 'Anda tidak terdaftar pada course ini.',
            $url === null || trim($url) === '' => 'Tautan pertemuan belum diisi.',
            now()->lessThan($window['opens_at']) => 'Tautan gabung aktif '.$window['opens_at']->diffForHumans().'.',
            now()->greaterThan($window['closes_at']) => 'Waktu gabung sesi ini sudah berakhir.',
            default => null,
        };

        return [
            'joinable' => $reason === null,
            'reason' => $reason,
            'as_host' => $asHost,
            'url' => $reason === null ? $url : null,
            'provider' => $session->provider->value,
            'provider_label' => $session->provider->label(),
            'opens_at' => $window['opens_at']->toIso8601String(),
            'closes_at' => $window['closes_at']->toIso8601String(),
            'status' => $session->status->value,
            'status_label' => $session->status->label(),
            'attendance_enabled' => (bool) $session->attendance_enabled,
        ];
    }

    /**
     * Catat kehadiran mandiri saat siswa bergabung (§15).
     *
     * Sumber kehadiran ditandai SELF supaya tidak pernah tertukar dengan
     * pencatatan guru.
     *
     * @return array<string, mixed>
     */
    public function join(LmsLiveSession $session, LmsActor $actor): array
    {
        $asHost = $actor->primaryRole !== ActorRole::STUDENT;
        $info = $this->joinInfo($session, $actor, $asHost);

        if (! $info['joinable']) {
            LmsFault::conflict((string) $info['reason']);
        }

        $attendance = null;

        if ($session->attendance_enabled && ! $asHost) {
            $attendance = $this->markSelfAttendance($session, $actor);
        }

        if ($session->course !== null && ! $asHost) {
            $this->events->record(
                LearningEventType::LIVE_SESSION_JOINED,
                $actor,
                $session->course,
                ['session_title' => $session->title, 'status' => $session->status->value],
                null,
                null,
                null,
                $session,
            );
        }

        return [
            'join' => $info,
            'attendance' => $attendance,
        ];
    }

    /** @return array<string, mixed> */
    private function markSelfAttendance(LmsLiveSession $session, LmsActor $actor): array
    {
        $enrollment = LmsEnrollment::query()
            ->where('course_id', $session->course_id)
            ->where('user_id', $actor->id)
            ->first();

        $late = $this->isLate($session);

        $attendance = LmsLiveSessionAttendance::query()->updateOrCreate(
            ['live_session_id' => $session->id, 'user_id' => $actor->id],
            [
                'enrollment_id' => $enrollment?->id,
                'status' => $late ? LiveAttendanceStatus::LATE : LiveAttendanceStatus::PRESENT,
                'joined_at' => now(),
                'source' => LmsLiveSessionAttendance::SOURCE_SELF,
                'marked_by' => $actor->id,
            ],
        );

        $this->awardAttendanceXp($session, $actor->id, $attendance->status);

        return [
            'status' => $attendance->status->value,
            'status_label' => $attendance->status->label(),
            'source' => $attendance->source,
            'joined_at' => $attendance->joined_at?->toIso8601String(),
        ];
    }

    /**
     * Catat/ubah kehadiran secara manual oleh guru (§15).
     *
     * @param  list<array<string, mixed>>  $records  user_id, status, note, minutes_attended
     * @return array<string, mixed>
     */
    public function markAttendance(LmsLiveSession $session, array $records, LmsActor $actor): array
    {
        $saved = [];
        $skipped = [];

        $enrollments = LmsEnrollment::query()
            ->where('course_id', $session->course_id)
            ->pluck('id', 'user_id');

        foreach ($records as $record) {
            $userId = (int) ($record['user_id'] ?? 0);
            $status = LiveAttendanceStatus::tryFrom((string) ($record['status'] ?? ''));

            if ($userId <= 0 || $status === null) {
                $skipped[] = ['user_id' => $userId, 'reason' => 'Data kehadiran tidak lengkap.'];

                continue;
            }

            $attendance = LmsLiveSessionAttendance::query()->updateOrCreate(
                ['live_session_id' => $session->id, 'user_id' => $userId],
                [
                    'enrollment_id' => $record['enrollment_id'] ?? $enrollments[$userId] ?? null,
                    'status' => $status,
                    'minutes_attended' => $record['minutes_attended'] ?? null,
                    'source' => LmsLiveSessionAttendance::SOURCE_MANUAL,
                    'marked_by' => $actor->id,
                    'note' => $record['note'] ?? null,
                ],
            );

            if ($status->countsAsPresent()) {
                $this->awardAttendanceXp($session, $userId, $status);
            }

            $saved[] = [
                'user_id' => $userId,
                'status' => $attendance->status->value,
                'status_label' => $attendance->status->label(),
            ];
        }

        $this->audit('LMS_LIVE_ATTENDANCE_MARKED', $session, [
            'saved' => count($saved),
            'skipped' => count($skipped),
        ]);

        return ['saved' => $saved, 'skipped' => $skipped, 'summary' => $this->attendanceSummary($session)];
    }

    /**
     * Tarik kehadiran dari provider bila provider benar-benar mengirimnya (§15).
     *
     * Bila provider tidak mendukung, hasilnya kosong — tidak ada klaim palsu.
     *
     * @return array<string, mixed>
     */
    public function syncProviderAttendance(LmsLiveSession $session, LmsActor $actor): array
    {
        $provider = $this->providers->for($session->provider);

        if (! $provider->supportsAttendance()) {
            return [
                'supported' => false,
                'message' => 'Provider ini hanya menyimpan tautan; kehadiran dicatat manual oleh guru.',
                'saved' => [],
            ];
        }

        $records = $provider->fetchAttendance($session);

        if ($records === []) {
            return ['supported' => true, 'message' => 'Provider belum mengirim data kehadiran.', 'saved' => []];
        }

        $saved = [];

        foreach ($records as $record) {
            $userId = (int) ($record['user_id'] ?? 0);
            $status = LiveAttendanceStatus::tryFrom((string) ($record['status'] ?? ''));

            if ($userId <= 0 || $status === null) {
                continue;
            }

            LmsLiveSessionAttendance::query()->updateOrCreate(
                ['live_session_id' => $session->id, 'user_id' => $userId],
                [
                    'status' => $status,
                    'joined_at' => $record['joined_at'] ?? null,
                    'left_at' => $record['left_at'] ?? null,
                    'minutes_attended' => $record['minutes_attended'] ?? null,
                    'source' => LmsLiveSessionAttendance::SOURCE_PROVIDER,
                    'marked_by' => $actor->id,
                ],
            );

            $saved[] = ['user_id' => $userId, 'status' => $status->value];
        }

        $this->audit('LMS_LIVE_ATTENDANCE_SYNCED', $session, ['saved' => count($saved)]);

        return ['supported' => true, 'message' => null, 'saved' => $saved];
    }

    // ------------------------------------------------------------------ rekap kehadiran

    /**
     * Daftar kehadiran satu sesi beserta nama peserta.
     *
     * @return list<array<string, mixed>>
     */
    public function attendanceRows(LmsLiveSession $session): array
    {
        $names = $this->identityNames(
            LmsLiveSessionAttendance::query()
                ->where('live_session_id', $session->id)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        );

        return LmsLiveSessionAttendance::query()
            ->where('live_session_id', $session->id)
            ->orderBy('user_id')
            ->get()
            ->map(fn (LmsLiveSessionAttendance $row) => [
                'id' => (int) $row->id,
                'user_id' => (int) $row->user_id,
                'name' => $names[(int) $row->user_id] ?? null,
                'status' => $row->status->value,
                'status_label' => $row->status->label(),
                'source' => $row->source,
                'joined_at' => $row->joined_at?->toIso8601String(),
                'left_at' => $row->left_at?->toIso8601String(),
                'minutes_attended' => $row->minutes_attended === null ? null : (int) $row->minutes_attended,
                'note' => $row->note,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    public function attendanceSummary(LmsLiveSession $session): array
    {
        return $this->summarize((int) $session->id, LmsLiveSessionAttendance::query()
            ->where('live_session_id', $session->id)
            ->get());
    }

    /**
     * Rekap beberapa sesi sekaligus (satu query) untuk daftar guru.
     *
     * @param  list<int>  $sessionIds
     * @return array<int, array<string, mixed>>
     */
    public function attendanceSummaries(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        return LmsLiveSessionAttendance::query()
            ->whereIn('live_session_id', $sessionIds)
            ->get()
            ->groupBy('live_session_id')
            ->map(fn ($rows, $sessionId) => $this->summarize((int) $sessionId, $rows))
            ->all();
    }

    /**
     * @param  Collection<int, LmsLiveSessionAttendance>  $rows
     * @return array<string, mixed>
     */
    private function summarize(int $sessionId, $rows): array
    {
        $counts = [];

        foreach (LiveAttendanceStatus::cases() as $status) {
            $counts[$status->value] = $rows->filter(fn (LmsLiveSessionAttendance $row) => $row->status === $status)->count();
        }

        $present = $rows->filter(fn (LmsLiveSessionAttendance $row) => $row->status->countsAsPresent())->count();
        $total = $rows->count();

        return [
            'session_id' => $sessionId,
            'total_recorded' => $total,
            'present' => $present,
            'counts' => $counts,
            'attendance_rate' => $total === 0 ? null : round($present / $total * 100, 2),
            'latest_marked_at' => $rows->max('updated_at')?->toIso8601String(),
        ];
    }

    /**
     * Riwayat kehadiran seorang siswa pada sebuah course (§15–§16).
     *
     * @return array<string, mixed>
     */
    public function attendanceForUser(int $userId, int $courseId): array
    {
        $sessions = LmsLiveSession::query()
            ->where('course_id', $courseId)
            ->whereNotNull('published_at')
            ->orderBy('start_at')
            ->get();

        $attendance = LmsLiveSessionAttendance::query()
            ->where('user_id', $userId)
            ->whereIn('live_session_id', $sessions->pluck('id')->all())
            ->get()
            ->keyBy('live_session_id');

        $rows = $sessions->map(function (LmsLiveSession $session) use ($attendance): array {
            $row = $attendance->get($session->id);

            return [
                'session_id' => (int) $session->id,
                'title' => $session->title,
                'type' => $session->type->value,
                'start_at' => $session->start_at?->toIso8601String(),
                'status' => $session->status->value,
                'attendance_status' => $row?->status->value,
                'attendance_label' => $row?->status->label(),
                'source' => $row?->source,
                'minutes_attended' => $row?->minutes_attended === null ? null : (int) $row->minutes_attended,
            ];
        });

        $present = $rows->filter(fn (array $row) => $row['attendance_status'] !== null
            && LiveAttendanceStatus::from($row['attendance_status'])->countsAsPresent())->count();

        return [
            'course_id' => $courseId,
            'user_id' => $userId,
            'total_sessions' => $rows->count(),
            'present' => $present,
            'attendance_rate' => $rows->count() === 0 ? null : round($present / $rows->count() * 100, 2),
            'sessions' => $rows->values()->all(),
        ];
    }

    /** Rekap kehadiran seluruh siswa pada sebuah course (dipakai guru). */
    public function attendanceOverview(LmsCourse $course): array
    {
        $sessionIds = LmsLiveSession::query()
            ->where('course_id', $course->id)
            ->whereNotNull('published_at')
            ->pluck('id')
            ->all();

        $rows = $sessionIds === []
            ? collect()
            : LmsLiveSessionAttendance::query()->whereIn('live_session_id', $sessionIds)->get();

        $byUser = $rows->groupBy('user_id');
        $names = $this->identityNames($byUser->keys()->map(fn ($id) => (int) $id)->all());

        return [
            'course_id' => (int) $course->id,
            'sessions_count' => count($sessionIds),
            'students' => $byUser->map(function ($userRows, $userId) use ($names): array {
                $present = $userRows->filter(fn (LmsLiveSessionAttendance $row) => $row->status->countsAsPresent())->count();

                return [
                    'user_id' => (int) $userId,
                    'name' => $names[(int) $userId] ?? null,
                    'recorded' => $userRows->count(),
                    'present' => $present,
                    'attendance_rate' => $userRows->count() === 0 ? null : round($present / $userRows->count() * 100, 2),
                ];
            })->values()->all(),
        ];
    }

    // ------------------------------------------------------------------ rekaman

    /**
     * Tambahkan rekaman pada sebuah sesi (§16).
     *
     * @param  array<string, mixed>  $data
     */
    public function addRecording(LmsLiveSession $session, array $data, LmsActor $actor): LmsLiveSessionRecording
    {
        if (trim((string) ($data['url'] ?? '')) === '') {
            LmsFault::invalid('Tautan rekaman wajib diisi.', 'url');
        }

        $recording = LmsLiveSessionRecording::query()->create([
            'live_session_id' => $session->id,
            'course_id' => $session->course_id,
            'item_id' => $data['item_id'] ?? null,
            'title' => $data['title'] ?? $session->title,
            'description' => $data['description'] ?? null,
            'url' => $data['url'],
            'provider' => LiveSessionProvider::from((string) ($data['provider'] ?? $session->provider->value)),
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'completion_threshold' => $data['completion_threshold'] ?? config('lms.live.recording_completion_threshold', 80),
            'is_published' => (bool) ($data['is_published'] ?? true),
            'created_by' => $actor->id,
            'published_at' => ($data['is_published'] ?? true) ? now() : null,
        ]);

        $this->audit('LMS_LIVE_RECORDING_ADDED', $session, ['recording_id' => (int) $recording->id]);

        return $recording;
    }

    /**
     * Rekaman yang tidak terikat sesi (mis. hasil unggahan guru) (§16).
     *
     * @param  array<string, mixed>  $data
     */
    public function addCourseRecording(LmsCourse $course, array $data, LmsActor $actor): LmsLiveSessionRecording
    {
        if (trim((string) ($data['url'] ?? '')) === '') {
            LmsFault::invalid('Tautan rekaman wajib diisi.', 'url');
        }

        $recording = LmsLiveSessionRecording::query()->create([
            'live_session_id' => $data['live_session_id'] ?? null,
            'course_id' => $course->id,
            'item_id' => $data['item_id'] ?? null,
            'title' => $data['title'] ?? 'Rekaman kelas',
            'description' => $data['description'] ?? null,
            'url' => $data['url'],
            'provider' => LiveSessionProvider::from((string) ($data['provider'] ?? config('lms.live.default_provider', LiveSessionProvider::EXTERNAL->value))),
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'completion_threshold' => $data['completion_threshold'] ?? config('lms.live.recording_completion_threshold', 80),
            'is_published' => (bool) ($data['is_published'] ?? true),
            'created_by' => $actor->id,
            'published_at' => ($data['is_published'] ?? true) ? now() : null,
        ]);

        $this->audit('LMS_LIVE_RECORDING_ADDED', $course, ['recording_id' => (int) $recording->id]);

        return $recording;
    }

    /** @param array<string, mixed> $data */
    public function updateRecording(LmsLiveSessionRecording $recording, array $data, LmsActor $actor): LmsLiveSessionRecording
    {
        foreach (['title', 'description', 'url', 'duration_seconds', 'completion_threshold', 'item_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $recording->{$key} = $data[$key];
            }
        }

        if (array_key_exists('is_published', $data)) {
            $recording->is_published = (bool) $data['is_published'];
            $recording->published_at = $recording->is_published ? ($recording->published_at ?? now()) : null;
        }

        $recording->save();

        $this->audit('LMS_LIVE_RECORDING_UPDATED', $recording, ['recording_id' => (int) $recording->id]);

        return $recording;
    }

    public function deleteRecording(LmsLiveSessionRecording $recording, LmsActor $actor): void
    {
        $this->audit('LMS_LIVE_RECORDING_DELETED', $recording, ['recording_id' => (int) $recording->id]);

        $recording->delete();
    }

    /**
     * Rekaman pada sebuah course.
     *
     * @return list<array<string, mixed>>
     */
    public function recordingsForCourse(LmsCourse $course, bool $forStaff = false): array
    {
        return LmsLiveSessionRecording::query()
            ->where('course_id', $course->id)
            ->when(! $forStaff, fn ($query) => $query->where('is_published', true))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LmsLiveSessionRecording $recording) => $this->recordingRow($recording, $forStaff))
            ->all();
    }

    /**
     * Catat tontonan rekaman dan tandai selesai saat melewati ambang (§16).
     *
     * Penyelesaian course tetap dihitung mesin course bila rekaman ditempelkan
     * pada learning item; di sini hanya peristiwa belajar yang dicatat.
     *
     * @return array<string, mixed>
     */
    public function recordWatch(LmsLiveSessionRecording $recording, LmsActor $actor, int $percentWatched): array
    {
        if (! $recording->is_published && $actor->isStudent()) {
            LmsFault::forbidden('Rekaman ini belum dipublikasikan.');
        }

        $percent = max(0, min(100, $percentWatched));
        $threshold = (int) ($recording->completion_threshold ?? config('lms.live.recording_completion_threshold', 80));
        $completed = $percent >= $threshold;

        $course = $recording->course;
        $item = $recording->item;

        if ($course !== null && $actor->isStudent()) {
            $this->events->record(
                $percent === 0 ? LearningEventType::VIDEO_STARTED : LearningEventType::ACTIVITY_VIEW,
                $actor,
                $course,
                ['recording_id' => (int) $recording->id, 'percent' => $percent, 'threshold' => $threshold],
                $item,
            );

            if ($completed) {
                $this->events->record(
                    LearningEventType::VIDEO_COMPLETED,
                    $actor,
                    $course,
                    ['recording_id' => (int) $recording->id, 'percent' => $percent],
                    $item,
                );
            }
        }

        return [
            'recording_id' => (int) $recording->id,
            'percent_watched' => $percent,
            'threshold' => $threshold,
            'completed' => $completed,
        ];
    }

    /** Statistik singkat untuk dasbor kelas langsung guru. */
    public function statistics(LmsCourse $course): array
    {
        $sessions = LmsLiveSession::query()->where('course_id', $course->id)->get();

        $sessionIds = $sessions->pluck('id')->all();

        $attendance = $sessionIds === []
            ? collect()
            : LmsLiveSessionAttendance::query()->whereIn('live_session_id', $sessionIds)->get();

        $present = $attendance->filter(fn (LmsLiveSessionAttendance $row) => $row->status->countsAsPresent())->count();

        return [
            'total_sessions' => $sessions->count(),
            'published_sessions' => $sessions->whereNotNull('published_at')->count(),
            'upcoming_sessions' => $sessions->filter(fn (LmsLiveSession $session) => $session->start_at !== null && $session->start_at->isFuture())->count(),
            'recordings' => LmsLiveSessionRecording::query()->where('course_id', $course->id)->count(),
            'attendance_rate' => $attendance->count() === 0 ? null : round($present / $attendance->count() * 100, 2),
        ];
    }

    // ------------------------------------------------------------------ internal

    /**
     * @param  array<string, mixed>|null  $summary  rekap yang sudah dihitung massal
     * @return array<string, mixed>
     */
    private function row(LmsLiveSession $session, bool $forStaff, ?array $summary = null): array
    {
        $end = $session->end_at ?? $session->start_at?->copy()->addMinutes((int) ($session->duration_minutes ?? 60));

        return [
            'id' => (int) $session->id,
            'course_id' => (int) $session->course_id,
            'section_id' => $session->section_id === null ? null : (int) $session->section_id,
            'activity_id' => $session->activity_id === null ? null : (int) $session->activity_id,
            'type' => $session->type->value,
            'type_label' => $session->type->label(),
            'title' => $session->title,
            'description' => $session->description,
            'provider' => $session->provider->value,
            'provider_label' => $session->provider->label(),
            'start_at' => $session->start_at?->toIso8601String(),
            'end_at' => $end?->toIso8601String(),
            'local_start_at' => $session->start_at?->copy()->setTimezone($session->timezone)->toIso8601String(),
            'timezone' => $session->timezone,
            'duration_minutes' => $session->duration_minutes === null ? null : (int) $session->duration_minutes,
            'status' => $session->status->value,
            'status_label' => $session->status->label(),
            'has_meeting_url' => trim((string) $session->meeting_url) !== '',
            'meeting_url' => $forStaff ? $session->meeting_url : null,
            'passcode' => $forStaff ? $session->passcode : null,
            'capacity' => $session->capacity === null ? null : (int) $session->capacity,
            'attendance_enabled' => (bool) $session->attendance_enabled,
            'is_recorded' => (bool) $session->is_recorded,
            'recurring' => (bool) $session->recurring,
            'recurrence_rule' => $session->recurrence_rule,
            'published_at' => $session->published_at?->toIso8601String(),
            'has_ended' => $session->hasEnded(),
            'joinable' => $session->isJoinable() && $session->published_at !== null,
            'attendance_summary' => $summary,
            'recordings_count' => $session->relationLoaded('recordings')
                ? $session->recordings->count()
                : $session->recordings()->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function recordingRow(LmsLiveSessionRecording $recording, bool $forStaff): array
    {
        return [
            'id' => (int) $recording->id,
            'live_session_id' => $recording->live_session_id === null ? null : (int) $recording->live_session_id,
            'course_id' => (int) $recording->course_id,
            'item_id' => $recording->item_id === null ? null : (int) $recording->item_id,
            'title' => $recording->title,
            'description' => $recording->description,
            'url' => $recording->is_published || $forStaff ? $recording->url : null,
            'provider' => $recording->provider->value,
            'provider_label' => $recording->provider->label(),
            'duration_seconds' => $recording->duration_seconds === null ? null : (int) $recording->duration_seconds,
            'completion_threshold' => $recording->completion_threshold === null ? null : (int) $recording->completion_threshold,
            'is_published' => (bool) $recording->is_published,
            'published_at' => $recording->published_at?->toIso8601String(),
            'created_at' => $recording->created_at?->toIso8601String(),
        ];
    }

    /** @return array{opens_at: Carbon, closes_at: Carbon} */
    private function joinWindow(LmsLiveSession $session): array
    {
        $start = $session->start_at ?? now();
        $end = $session->end_at ?? $start->copy()->addMinutes((int) ($session->duration_minutes ?? 60));

        return [
            'opens_at' => $start->copy()->subMinutes($this->joinWindowMinutes()),
            'closes_at' => $end->copy()->addMinutes($this->graceMinutes()),
        ];
    }

    private function isLate(LmsLiveSession $session): bool
    {
        if ($session->start_at === null) {
            return false;
        }

        return now()->greaterThan($session->start_at->copy()->addMinutes($this->graceMinutes()));
    }

    private function isEnrolled(LmsLiveSession $session, LmsActor $actor): bool
    {
        return LmsEnrollment::query()
            ->where('course_id', $session->course_id)
            ->where('user_id', $actor->id)
            ->whereIn('status', ['ACTIVE', 'COMPLETED'])
            ->exists();
    }

    /** Poin kelas langsung diberikan sekali per sesi (§28). */
    private function awardAttendanceXp(LmsLiveSession $session, int $userId, LiveAttendanceStatus $status): void
    {
        if (! $status->countsAsPresent() || $session->course === null) {
            return;
        }

        $this->gamification->award(
            GamificationEventType::LIVE_ATTENDED,
            $userId,
            $session->course,
            'live:'.$session->id.':'.$userId,
            null,
            ['session_title' => $session->title, 'status' => $status->value],
            $session,
        );
    }

    /** @param list<int> $userIds @return array<int, string> */
    private function identityNames(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $model = config('lms.standalone', false) ? LmsUser::class : User::class;

        return $model::query()->whereIn('id', $userIds)->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
    }

    private function joinWindowMinutes(): int
    {
        return max(0, (int) config('lms.live.join_window_minutes', 15));
    }

    private function graceMinutes(): int
    {
        return max(0, (int) config('lms.live.join_grace_minutes', 10));
    }

    private function durationMinutes(Carbon $start, ?Carbon $end): ?int
    {
        return $end === null ? null : (int) $start->diffInMinutes($end);
    }

    /** Sesi yang sedang ditutup (mis. tahun ajaran berganti) tidak dihapus. */
    public function closeCompletedSessions(LmsCourse $course): int
    {
        $count = 0;

        LmsLiveSession::query()
            ->where('course_id', $course->id)
            ->whereIn('status', [LiveSessionStatus::SCHEDULED->value, LiveSessionStatus::LIVE->value])
            ->whereNotNull('published_at')
            ->chunkById(100, function ($sessions) use (&$count): void {
                foreach ($sessions as $session) {
                    $before = $session->status;

                    if ($this->refreshStatus($session)->status !== $before) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /** Ringkasan singkat untuk dasbor kursus (tanpa query per sesi). */
    public function pendingAttendanceCount(LmsCourse $course): int
    {
        return LmsLiveSession::query()
            ->where('course_id', $course->id)
            ->where('attendance_enabled', true)
            ->where('status', LiveSessionStatus::COMPLETED->value)
            ->whereDoesntHave('attendance')
            ->count();
    }

    /** Hapus kehadiran satu siswa (koreksi guru) lalu catat pada audit. */
    public function clearAttendance(LmsLiveSession $session, int $userId, LmsActor $actor): bool
    {
        $deleted = DB::transaction(fn () => LmsLiveSessionAttendance::query()
            ->where('live_session_id', $session->id)
            ->where('user_id', $userId)
            ->delete() > 0);

        if ($deleted) {
            $this->audit('LMS_LIVE_ATTENDANCE_CLEARED', $session, ['user_id' => $userId]);
        }

        return $deleted;
    }
}
