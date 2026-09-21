<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Library\LibraryNumberingRequest;
use App\Http\Resources\LibraryNumberingConfigResource;
use App\Models\Library;
use App\Models\LibraryNumberingConfig;
use App\Services\Library\NumberingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Konfigurasi penomoran dokumen per perpustakaan.
 *
 * Format tidak ditulis keras: token {SEQ}, {YYYY}, {YY}, {MM},
 * {LIBRARY_CODE}. Nomor aktual diambil lewat NumberingService.
 */
class LibraryNumberingController extends BaseApiController
{
    public function __construct(protected NumberingService $numbering)
    {
    }

    public function index(Library $library): JsonResponse
    {
        $this->authorize('viewNumbering', $library);

        $this->numbering->ensureDefaults($library);

        $configs = LibraryNumberingConfig::query()
            ->where('library_id', $library->id)
            ->with('library')
            ->orderBy('key')
            ->get();

        return $this->successResponse(
            LibraryNumberingConfigResource::collection($configs),
            'Konfigurasi penomoran berhasil diambil'
        );
    }

    public function update(LibraryNumberingRequest $request, Library $library): JsonResponse
    {
        $this->authorize('updateNumbering', $library);

        $this->numbering->ensureDefaults($library);

        $configs = $request->validated()['configs'];
        $keys = array_column($configs, 'key');

        $known = LibraryNumberingConfig::query()
            ->where('library_id', $library->id)
            ->whereIn('key', $keys)
            ->pluck('key')
            ->all();

        $unknown = array_diff($keys, $known);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'configs' => 'Kunci penomoran tidak tersedia: '.implode(', ', $unknown).'.',
            ]);
        }

        DB::transaction(function () use ($library, $configs): void {
            foreach ($configs as $config) {
                LibraryNumberingConfig::query()
                    ->where('library_id', $library->id)
                    ->where('key', $config['key'])
                    ->update([
                        'format' => $config['format'],
                        'prefix' => $config['prefix'] ?? null,
                        'padding' => (int) $config['padding'],
                        'reset_period' => $config['reset_period'],
                        'start_number' => (int) $config['start_number'],
                        'updated_at' => now(),
                    ]);
            }
        });

        $updated = LibraryNumberingConfig::query()
            ->where('library_id', $library->id)
            ->with('library')
            ->orderBy('key')
            ->get();

        return $this->successResponse(
            LibraryNumberingConfigResource::collection($updated),
            'Konfigurasi penomoran berhasil diperbarui'
        );
    }
}