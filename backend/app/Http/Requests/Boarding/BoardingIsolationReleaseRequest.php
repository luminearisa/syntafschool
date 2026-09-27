<?php

namespace App\Http\Requests\Boarding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lepas santri dari kamar isolasi (§39). Tanpa `room_id` service mengembalikan
 * santri ke kamar asal yang tercatat di placement isolasi.
 */
class BoardingIsolationReleaseRequest extends FormRequest
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
            'room_id' => ['nullable', 'integer', 'min:1', 'exists:boarding_rooms,id'],
            'bed_id' => ['nullable', 'integer', 'min:1', 'exists:boarding_beds,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_id.exists' => 'Kamar tujuan tidak ditemukan.',
            'bed_id.exists' => 'Tempat tidur tersebut tidak ditemukan.',
        ];
    }
}
