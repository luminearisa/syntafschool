<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Boarding\BoardingSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Konfigurasi modul Boarding (§13, §34, §43) — label poin, ambang batas,
 * alur izin, dan sakelar RFID. Nilai & batas validnya hidup di
 * `BoardingSettingsService`; endpoint ini hanya memvaporisasi.
 */
class BoardingSettingsController extends BaseApiController
{
    public function __construct(
        private readonly BoardingSettingsService $settings,
    ) {
    }

    public function show(): JsonResponse
    {
        return $this->successResponse($this->settings->all(), 'Pengaturan asrama berhasil diambil');
    }

    public function update(Request $request): JsonResponse
    {
        $values = $request->input('settings', $request->except(['_token']));

        if (! is_array($values) || $values === []) {
            throw ValidationException::withMessages([
                'settings' => 'Kirim minimal satu pengaturan untuk disimpan.',
            ]);
        }

        try {
            $saved = $this->settings->update($values);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['settings' => $e->getMessage()]);
        }

        return $this->successResponse($saved, 'Pengaturan asrama berhasil disimpan');
    }
}
