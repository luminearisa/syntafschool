<?php

namespace App\Http\Requests\Library;

use App\Models\LibraryLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Data lokasi perpustakaan (hierarkis lewat `parent_id`).
 */
class LibraryLocationRequest extends FormRequest
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
        $location = $this->route('location');

        return [
            'parent_id' => ['nullable', 'integer', 'exists:library_locations,id'],
            'code' => [
                'required', 'string', 'max:30',
                // Kode hanya perlu unik di dalam satu perpustakaan.
                Rule::unique('library_locations', 'code')
                    ->where('library_id', $library?->id)
                    ->ignore($location?->id),
            ],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:'.implode(',', LibraryLocation::TYPES)],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', LibraryLocation::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'parent_id' => 'induk lokasi',
            'code' => 'kode lokasi',
            'type' => 'jenis lokasi',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Kode lokasi sudah dipakai pada perpustakaan ini.',
        ];
    }
}