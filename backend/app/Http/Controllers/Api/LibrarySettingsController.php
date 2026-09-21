<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Library\LibrarySettingsRequest;
use App\Models\Library;
use App\Services\Library\LibrarySettingsService;
use Illuminate\Http\JsonResponse;

/**
 * Konfigurasi perpustakaan (key-value, dikelompokkan).
 *
 * Termasuk fondasi konfigurasi sirkulasi (durasi pinjam, batas perpanjangan)
 * meski workflow-nya baru dibuat pada Step berikutnya.
 */
class LibrarySettingsController extends BaseApiController
{
    public function __construct(protected LibrarySettingsService $settings)
    {
    }

    public function show(Library $library): JsonResponse
    {
        $this->authorize('viewSettings', $library);

        $this->settings->ensureDefaults($library);

        return $this->successResponse(
            ['settings' => $this->settings->grouped($library)],
            'Konfigurasi perpustakaan berhasil diambil'
        );
    }

    public function update(LibrarySettingsRequest $request, Library $library): JsonResponse
    {
        $this->authorize('updateSettings', $library);

        // Kunci konfigurasi memuat titik, sehingga `validated()` (yang memakai
        // notasi bertitik) tidak memuat induk `settings` — nilainya dibaca
        // dari input setelah FormRequest memvalidasinya.
        $changes = $this->settings->update($library, (array) $request->input('settings', []));

        return $this->successResponse(
            [
                'settings' => $this->settings->grouped($library),
                'changed' => $changes,
            ],
            $changes === []
                ? 'Tidak ada konfigurasi yang berubah'
                : 'Konfigurasi perpustakaan berhasil disimpan'
        );
    }
}