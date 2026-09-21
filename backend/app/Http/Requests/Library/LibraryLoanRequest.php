<?php

namespace App\Http\Requests\Library;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Peminjaman (checkout).
 *
 * Anggota & eksemplar boleh dikirim sebagai id **atau** kode hasil pindai
 * (nomor anggota / barcode eksemplar) — layar cepat hanya mengirim kode.
 */
class LibraryLoanRequest extends FormRequest
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
            'member_code' => ['nullable', 'string', 'max:100'],
            'item_id' => ['nullable', 'integer', 'exists:library_items,id'],
            'item_code' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (blank($this->input('member_id')) && blank($this->input('member_code'))) {
                    $validator->errors()->add('member_id', 'Anggota wajib dipilih atau dipindai.');
                }

                if (blank($this->input('item_id')) && blank($this->input('item_code'))) {
                    $validator->errors()->add('item_id', 'Eksemplar wajib dipilih atau dipindai.');
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
            'member_id' => 'anggota',
            'member_code' => 'kode anggota',
            'item_id' => 'eksemplar',
            'item_code' => 'kode eksemplar',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'member_id.required_without' => 'Anggota wajib dipilih.',
            'item_id.required_without' => 'Eksemplar wajib dipilih.',
        ];
    }
}