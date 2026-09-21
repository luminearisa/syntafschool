<?php

namespace App\Http\Requests\Library;

use App\Models\LibraryNumberingConfig;
use App\Services\Library\NumberingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Konfigurasi penomoran (bulk per kunci).
 *
 * Format tidak boleh ditulis keras; wajib memuat token {SEQ} dan hanya boleh
 * memakai token yang dikenal NumberingService.
 */
class LibraryNumberingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'configs' => ['required', 'array', 'min:1'],
            'configs.*.key' => ['required', 'string', 'in:'.implode(',', array_keys(LibraryNumberingConfig::KEYS))],
            'configs.*.format' => ['required', 'string', 'max:100'],
            'configs.*.prefix' => ['nullable', 'string', 'max:20'],
            'configs.*.padding' => ['required', 'integer', 'min:1', 'max:10'],
            'configs.*.reset_period' => ['required', 'string', 'in:'.implode(',', LibraryNumberingConfig::RESET_PERIODS)],
            'configs.*.start_number' => ['required', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $service = app(NumberingService::class);
                $configs = (array) $this->input('configs', []);
                $seen = [];

                foreach ($configs as $index => $config) {
                    $key = $config['key'] ?? null;

                    if ($key !== null && in_array($key, $seen, true)) {
                        $validator->errors()->add("configs.{$index}.key", 'Kunci penomoran tidak boleh duplikat.');
                    }

                    $seen[] = $key;

                    $format = (string) ($config['format'] ?? '');

                    if ($format !== '' && ! str_contains($format, '{SEQ}')) {
                        $validator->errors()->add("configs.{$index}.format", 'Format penomoran wajib memuat token {SEQ}.');
                    }

                    $unknown = $format !== '' ? $service->unknownTokens($format) : [];

                    if ($unknown !== []) {
                        $validator->errors()->add(
                            "configs.{$index}.format",
                            'Token tidak dikenal: '.implode(', ', $unknown).'. Token yang didukung: '.implode(', ', NumberingService::TOKENS).'.'
                        );
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'configs' => 'konfigurasi penomoran',
            'configs.*.key' => 'kunci penomoran',
            'configs.*.format' => 'format penomoran',
            'configs.*.prefix' => 'awalan',
            'configs.*.padding' => 'jumlah digit',
            'configs.*.reset_period' => 'periode reset',
            'configs.*.start_number' => 'nomor awal',
        ];
    }
}