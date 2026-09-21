<?php

namespace App\Http\Requests\Library;

use App\Models\Library;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Data perpustakaan.
 *
 * Kode perpustakaan unik dan hanya boleh diubah selama masih berstatus draf —
 * setelah dipakai, kode menjadi rujukan historis.
 */
class LibraryRequest extends FormRequest
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
        $library = $this->route('library');
        $libraryId = $library?->id;

        return [
            'school_id' => ['nullable', 'integer', 'exists:schools,id'],
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('libraries', 'code')->ignore($libraryId),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'address' => ['nullable', 'string'],
            'timezone' => ['required', 'string', 'timezone'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:'.implode(',', Library::STATUSES)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $library = $this->route('library');

                if ($library === null) {
                    return;
                }

                $newCode = $this->input('code');

                if ($newCode !== $library->code && $library->status !== Library::STATUS_DRAFT) {
                    $validator->errors()->add(
                        'code',
                        'Kode perpustakaan hanya dapat diubah selama berstatus draf.'
                    );
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
            'school_id' => 'sekolah',
            'code' => 'kode perpustakaan',
            'timezone' => 'zona waktu',
            'logo_path' => 'logo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Kode perpustakaan wajib diisi.',
            'code.unique' => 'Kode perpustakaan sudah dipakai.',
        ];
    }
}