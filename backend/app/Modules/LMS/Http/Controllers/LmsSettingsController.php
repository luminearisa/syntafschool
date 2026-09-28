<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Models\Setting;
use App\Modules\LMS\Application\Services\LmsIntegrationService;
use App\Modules\LMS\Http\LmsController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pengaturan LMS (§35 `lms.settings.manage`).
 *
 * Nilai disimpan pada tabel `settings` yang sudah ada (key `lms.settings`) dan
 * dibaca kembali oleh `LmsServiceProvider` saat boot — jadi perubahan berlaku
 * tanpa mengubah berkas `.env`.
 */
class LmsSettingsController extends LmsController
{
    public const SETTINGS_KEY = 'lms.settings';

    public function __construct(private readonly LmsIntegrationService $integration) {}

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
            ],
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
