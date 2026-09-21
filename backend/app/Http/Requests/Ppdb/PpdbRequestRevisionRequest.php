<?php

namespace App\Http\Requests\Ppdb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Panitia meminta revisi berkas calon.
 *
 * `note` = ringkasan yang disimpan pada `ppdb_registrations.revision_note` dan
 * dikirim ke orang tua. `targets` opsional: daftar butir spesifik yang harus
 * diperbaiki (jawaban field, jawaban pertanyaan, atau dokumen) beserta alasannya.
 */
class PpdbRequestRevisionRequest extends FormRequest
{
    public const TARGET_TYPES = ['field', 'question', 'document'];

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
            'note' => ['required', 'string', 'min:5', 'max:2000'],
            'targets' => ['sometimes', 'array', 'max:50', function (string $attribute, mixed $value, callable $fail): void {
                foreach ((array) $value as $index => $target) {
                    if (! is_array($target)) {
                        $fail('Rincian revisi ke-'.((int) $index + 1).' harus berbentuk object.');

                        continue;
                    }

                    $type = (string) ($target['type'] ?? '');

                    if (! in_array($type, self::TARGET_TYPES, true)) {
                        $fail('Jenis rincian revisi harus field, question, atau document.');
                    }

                    if (empty($target['key']) && empty($target['id'])) {
                        $fail('Setiap rincian revisi wajib memiliki key (field) atau id (question/document).');
                    }

                    if (empty(trim((string) ($target['reason'] ?? '')))) {
                        $fail('Alasan tiap rincian revisi wajib diisi.');
                    }
                }
            }],
            'notify' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'note' => 'catatan revisi',
            'targets' => 'rincian yang harus diperbaiki',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Catatan revisi wajib diisi agar calon tahu apa yang harus diperbaiki.',
            'note.min' => 'Catatan revisi terlalu singkat (minimal :min karakter).',
        ];
    }
}
