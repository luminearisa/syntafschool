<?php

namespace App\Http\Requests\Boarding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pindahkan santri ke kamar isolasi (§39). Yang diisolasi adalah PENEMPATAN,
 * bukan flag catatan kesehatan — service menutup placement lama dan membuka
 * placement baru dalam satu transaksi.
 */
class BoardingIsolateRequest extends FormRequest
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
            'student_id' => ['required', 'integer', 'min:1', 'exists:students,id'],
            'room_id' => ['required', 'integer', 'min:1', 'exists:boarding_rooms,id'],
            'bed_id' => ['nullable', 'integer', 'min:1', 'exists:boarding_beds,id'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'health_record_id' => ['nullable', 'integer', 'min:1', 'exists:boarding_health_records,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_id.required' => 'Santri yang akan diisolasi wajib dipilih.',
            'room_id.required' => 'Kamar isolasi tujuan wajib dipilih.',
            'reason.required' => 'Alasan isolasi wajib diisi — ia muncul di laporan dan daftar penghuni kamar (§39).',
            'reason.min' => 'Alasan isolasi terlalu singkat; minimal :min karakter.',
            'health_record_id.exists' => 'Catatan kesehatan rujukan tidak ditemukan.',
        ];
    }
}
