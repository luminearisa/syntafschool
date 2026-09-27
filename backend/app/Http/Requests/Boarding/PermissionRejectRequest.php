<?php

namespace App\Http\Requests\Boarding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Penolakan izin keluar (§27): satu-satunya kolom wajib di luar ID adalah alasan.
 *
 * Penolakan tanpa alasan tidak berguna bagi siapa pun — orang tua membaca kalimat
 * ini persis seperti yang diketik penyetuju, jadi minimal lima karakter dan
 * ikut terkirim pada notifikasi.
 */
class PermissionRejectRequest extends FormRequest
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
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['note' => 'alasan penolakan'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Alasan penolakan wajib diisi agar santri dan orang tua tahu apa yang perlu dilengkapi (§27).',
            'note.min' => 'Alasan penolakan minimal :min karakter — "ditolak" saja tidak menjelaskan apa pun.',
            'note.max' => 'Alasan penolakan maksimal :max karakter.',
        ];
    }
}
