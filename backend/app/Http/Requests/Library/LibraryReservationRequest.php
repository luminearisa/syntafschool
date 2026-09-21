<?php

namespace App\Http\Requests\Library;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pengajuan reservasi judul (dapat dilakukan pustakawan atau anggota).
 */
class LibraryReservationRequest extends FormRequest
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
            'member_id' => ['nullable', 'integer', 'exists:library_members,id'],
            'bibliography_id' => ['required', 'integer', 'exists:library_bibliographies,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'member_id' => 'anggota',
            'bibliography_id' => 'judul',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bibliography_id.required' => 'Judul wajib dipilih.',
        ];
    }
}