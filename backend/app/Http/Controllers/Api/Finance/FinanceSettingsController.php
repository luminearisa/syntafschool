<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Finance\FinanceSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Konfigurasi modul Finance (§73) — mata uang, tahun fiskal, ambang approval
 * pengeluaran, kebijakan penagihan (§6), sakelar cicilan (§7), denda
 * keterlambatan, dan sakelar verifikasi/auto-posting.
 * Nilai & batas validnya hidup di `FinanceSettingsService`; endpoint ini hanya
 * membungkusnya.
 */
class FinanceSettingsController extends BaseApiController
{
    public function __construct(
        private readonly FinanceSettingsService $settings,
    ) {}

    public function show(): JsonResponse
    {
        return $this->successResponse($this->settings->all(), 'Pengaturan keuangan berhasil diambil');
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

        return $this->successResponse($saved, 'Pengaturan keuangan berhasil disimpan');
    }
}
