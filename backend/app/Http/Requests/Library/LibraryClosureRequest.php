<?php

namespace App\Http\Requests\Library;

use App\Models\LibraryClosure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Data penutupan perpustakaan.
 *
 * Jam boleh dikosongkan (tutup sehari penuh) atau diisi untuk menutup
 * sebagian hari saja.
 */
class LibraryClosureRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time'],
            'reason' => ['required', 'string', 'max:200'],
            'type' => ['required', 'string', 'in:'.implode(',', LibraryClosure::TYPES)],
            'status' => ['nullable', 'string', 'in:'.implode(',', LibraryClosure::STATUSES)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $start = $this->input('start_time');
                $end = $this->input('end_time');

                if ($start && $end && $end <= $start) {
                    $validator->errors()->add('end_time', 'Jam selesai harus setelah jam mulai.');
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
            'date' => 'tanggal',
            'start_time' => 'jam mulai',
            'end_time' => 'jam selesai',
            'reason' => 'alasan',
            'type' => 'jenis penutupan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan penutupan wajib diisi.',
            'type.required' => 'Jenis penutupan wajib dipilih.',
        ];
    }
}