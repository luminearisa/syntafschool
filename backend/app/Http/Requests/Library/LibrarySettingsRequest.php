<?php

namespace App\Http\Requests\Library;

use App\Models\LibrarySetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

/**
 * Konfigurasi perpustakaan (key-value).
 *
 * Kunci konfigurasi memuat titik (mis. `loan.max_items`). Titik adalah
 * notasi bersarang bagi validator Laravel, sehingga aturan bertitik seperti
 * `settings.loan.max_items` TIDAK dapat dipakai: `data_get()` akan mencari
 * struktur bersarang dan melewatkannya tanpa memvalidasi apa pun.
 *
 * Karena itu `settings` divalidasi sebagai array, lalu setiap entri diperiksa
 * terhadap definisi tipe di `LibrarySetting::DEFAULTS` — sekaligus menolak
 * kunci yang tidak dikenal.
 */
class LibrarySettingsRequest extends FormRequest
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
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('settings', []) as $key => $value) {
                    if (! array_key_exists($key, LibrarySetting::DEFAULTS)) {
                        $validator->errors()->add(
                            "settings.{$key}",
                            "Kunci konfigurasi \"{$key}\" tidak dikenal."
                        );

                        continue;
                    }

                    [$type] = LibrarySetting::DEFAULTS[$key];

                    $rules = match ($type) {
                        LibrarySetting::TYPE_INTEGER => ['integer', 'min:0'],
                        LibrarySetting::TYPE_BOOLEAN => ['boolean'],
                        LibrarySetting::TYPE_DECIMAL => ['numeric', 'min:0'],
                        LibrarySetting::TYPE_JSON => ['array'],
                        default => ['string', 'max:255'],
                    };

                    $check = ValidatorFacade::make(
                        ['value' => $value],
                        ['value' => $rules],
                        ["value.{$type}" => "Nilai harus berupa {$type}."]
                    );

                    foreach ($check->errors()->get('value') as $message) {
                        $validator->errors()->add("settings.{$key}", $message);
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
        return ['settings' => 'konfigurasi'];
    }
}