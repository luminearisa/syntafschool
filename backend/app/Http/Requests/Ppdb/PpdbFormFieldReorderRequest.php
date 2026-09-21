<?php

namespace App\Http\Requests\Ppdb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Urutkan ulang field form pendaftaran (drag & drop di form builder).
 *
 * Dua bentuk permintaan diterima supaya UI lama tetap berfungsi:
 *   `items: [{id, order}, ...]`  atau  `field_ids: [id, ...]` (urutan array = urutan field).
 */
class PpdbFormFieldReorderRequest extends FormRequest
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
            'period_id' => ['nullable', 'integer', 'exists:ppdb_periods,id'],
            'items' => ['required_without:field_ids', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'distinct', 'exists:ppdb_form_fields,id'],
            'items.*.order' => ['required', 'integer', 'min:0', 'max:65535'],
            'field_ids' => ['required_without:items', 'array', 'min:1'],
            'field_ids.*' => ['required', 'integer', 'distinct', 'exists:ppdb_form_fields,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'period_id' => 'periode',
            'items' => 'daftar field',
            'field_ids' => 'daftar field',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required_without' => 'Kirim daftar field yang akan diurutkan.',
            'field_ids.required_without' => 'Kirim daftar field yang akan diurutkan.',
            'items.*.id.distinct' => 'Tiap field hanya boleh disebut satu kali.',
            'field_ids.*.distinct' => 'Tiap field hanya boleh disebut satu kali.',
            'items.*.id.exists' => 'Ada field yang tidak ditemukan.',
            'field_ids.*.exists' => 'Ada field yang tidak ditemukan.',
        ];
    }

    /**
     * Pasang urutan menjadi `[id => order]`, menerima kedua bentuk permintaan.
     *
     * @return array<int, int>
     */
    public function orders(): array
    {
        $items = $this->input('items');

        if (is_array($items)) {
            $orders = [];

            foreach ($items as $item) {
                if (is_array($item) && isset($item['id'], $item['order'])) {
                    $orders[(int) $item['id']] = (int) $item['order'];
                }
            }

            return $orders;
        }

        $orders = [];

        foreach ((array) $this->input('field_ids') as $index => $id) {
            $orders[(int) $id] = $index;
        }

        return $orders;
    }
}
