<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Models\Setting;
use App\Modules\LMS\Application\Services\LmsIntegrationService;
use App\Modules\LMS\Application\Support\LiveMeetingRegistry;
use App\Modules\LMS\Http\LmsController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pengaturan LMS (§35 `lms.settings.manage`).
 *
 * Nilai disimpan pada tabel `settings` yang sudah ada (key `lms.settings`) dan
 * dibaca kembali oleh `LmsServiceProvider` saat boot — jadi perubahan berlaku
 * tanpa mengubah berkas `.env`.
 *
 * Phase 4 menambahkan sakelar modul yang memang opsional: gamifikasi, sertifikat,
 * ulasan, engagement, dan jendela gabung kelas langsung. Semua default aman
 * (gamifikasi & leaderboard NONAKTIF).
 */
class LmsSettingsController extends LmsController
{
    public const SETTINGS_KEY = 'lms.settings';

    public function __construct(
        private readonly LmsIntegrationService $integration,
        private readonly LiveMeetingRegistry $providers,
    ) {}

    public function show(): JsonResponse
    {
        return $this->successResponse([
            'settings' => [
                'self_enrollment' => (bool) config('lms.enrollment.self_enrollment', true),
                'suspend_on_academic_cancel' => (bool) config('lms.enrollment.suspend_on_academic_cancel', true),
                'guest_preview' => (bool) config('lms.guest.preview_items', true),
                'parent_view_grades' => (bool) config('lms.parent.can_view_grades', true),
                'parent_view_feedback' => (bool) config('lms.parent.can_view_feedback', true),
                'upload_max_kb' => (int) config('lms.uploads.max_size_kb', 20480),
                'upload_disk' => (string) config('lms.uploads.disk', 'local'),

                // Phase 4 — jalur belajar & drip (§1–§4).
                'learning_path_mode' => (string) config('lms.learning_path.default_mode', 'FLEXIBLE'),
                'enforce_sequential' => (bool) config('lms.learning_path.enforce_sequential', true),
                'drip_enabled' => (bool) config('lms.drip.enabled', true),

                // Phase 4 — konten interaktif (§7–§12).
                'interactive_versioning' => (bool) config('lms.interactive.versioning', true),

                // Phase 4 — kelas langsung (§13–§17).
                'live_default_provider' => (string) config('lms.live.default_provider', 'EXTERNAL'),
                'live_join_window_minutes' => (int) config('lms.live.join_window_minutes', 15),
                'live_join_grace_minutes' => (int) config('lms.live.join_grace_minutes', 10),
                'live_attendance_source' => (string) config('lms.live.attendance_source', 'MANUAL'),
                'live_recording_threshold' => (int) config('lms.live.recording_completion_threshold', 80),

                // Phase 4 — sertifikat (§21–§25).
                'certificate_enabled' => (bool) config('lms.certificate.enabled', true),
                'certificate_passing_grade' => (float) config('lms.certificate.passing_grade', 70),
                'certificate_prefix' => (string) config('lms.certificate.sequence_prefix', 'CERT'),

                // Phase 4 — gamifikasi (§28–§30).
                'gamification_enabled' => (bool) config('lms.gamification.enabled', false),
                'leaderboard_enabled' => (bool) config('lms.gamification.leaderboard_enabled', false),
                'leaderboard_display' => (string) config('lms.gamification.display', 'name'),
                'streak_enabled' => (bool) config('lms.gamification.streak.enabled', true),
                'streak_definition' => (string) config('lms.gamification.streak.definition', 'activity_completed'),

                // Phase 4 — ulasan (§31–§32).
                'review_enabled' => (bool) config('lms.reviews.enabled', true),
                'review_auto_approve' => (bool) config('lms.reviews.auto_approve', false),
                'review_display_name' => (string) config('lms.reviews.public_display_name', 'name'),

                // Phase 4 — engagement & pesan (§33–§35, §58–§61).
                'engagement_enabled' => (bool) config('lms.engagement.enabled', true),
                'health_inactive_days' => (int) config('lms.engagement.health.inactive_days', 14),
                'health_low_completion_percent' => (float) config('lms.engagement.health.low_completion_percent', 40),
                'messaging_driver' => (string) config('lms.messaging.driver', 'notification'),
            ],
            'live_providers' => $this->providers->describe(),
            'integration' => $this->integration->health(),
        ], 'Pengaturan LMS berhasil diambil');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'self_enrollment' => ['nullable', 'boolean'],
            'suspend_on_academic_cancel' => ['nullable', 'boolean'],
            'guest_preview' => ['nullable', 'boolean'],
            'parent_view_grades' => ['nullable', 'boolean'],
            'parent_view_feedback' => ['nullable', 'boolean'],
            'upload_max_kb' => ['nullable', 'integer', 'min:1', 'max:512000'],

            // Phase 4 — hanya sakelar & ambang yang aman diubah dari UI.
            'enforce_sequential' => ['nullable', 'boolean'],
            'drip_enabled' => ['nullable', 'boolean'],
            'live_default_provider' => ['nullable', 'string', 'in:EXTERNAL,ZOOM,GOOGLE_MEET,CUSTOM'],
            'live_join_window_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'live_join_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'live_recording_threshold' => ['nullable', 'integer', 'min:1', 'max:100'],
            'certificate_enabled' => ['nullable', 'boolean'],
            'certificate_passing_grade' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'gamification_enabled' => ['nullable', 'boolean'],
            'leaderboard_enabled' => ['nullable', 'boolean'],
            'leaderboard_display' => ['nullable', 'string', 'in:name,initials,anonymized'],
            'streak_enabled' => ['nullable', 'boolean'],
            'review_enabled' => ['nullable', 'boolean'],
            'review_auto_approve' => ['nullable', 'boolean'],
            'review_display_name' => ['nullable', 'string', 'in:name,initials'],
            'engagement_enabled' => ['nullable', 'boolean'],
            'health_inactive_days' => ['nullable', 'integer', 'min:1', 'max:180'],
            'health_low_completion_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $current = Setting::query()->where('key', self::SETTINGS_KEY)->first();
        $existing = $current !== null ? (json_decode((string) $current->value, true) ?: []) : [];

        $merged = array_filter([...$existing, ...$data], fn ($value) => $value !== null);

        Setting::updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            ['value' => json_encode($merged), 'group' => 'lms', 'type' => 'json'],
        );

        return $this->successResponse(['settings' => $merged], 'Pengaturan LMS berhasil disimpan');
    }
}
