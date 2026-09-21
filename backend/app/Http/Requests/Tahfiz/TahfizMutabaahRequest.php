<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Mutabaah harian peserta.
 *
 * Item diambil dari `tahfiz_mutabaah_items` (dikonfigurasi admin), bukan
 * ditulis keras di kode. Satu baris per peserta per tanggal — kirim ulang
 * pada tanggal yang sama akan memperbarui, bukan menduplikasi.
 */
class TahfizMutabaahRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'participant_id' => ['required', 'integer', 'exists:tahfiz_participants,id'],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.item_id' => ['nullable', 'integer', 'exists:tahfiz_mutabaah_items,id'],
            'entries.*.item_label' => ['nullable', 'string', 'max:100'],
            'entries.*.done' => ['nullable', 'boolean'],
            'entries.*.value' => ['nullable', 'numeric', 'min:0'],
            'entries.*.note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('entries', []) as $i => $entry) {
                    if (empty($entry['item_id']) && empty($entry['item_label'])) {
                        $validator->errors()->add(
                            "entries.{$i}.item_id",
                            'Item mutabaah atau labelnya harus diisi.'
                        );
                    }
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'participant_id' => 'peserta',
            'date' => 'tanggal',
            'notes' => 'catatan',
            'entries' => 'item mutabaah',
            'entries.*.item_id' => 'item',
            'entries.*.item_label' => 'label item',
            'entries.*.done' => 'selesai',
            'entries.*.value' => 'nilai',
            'entries.*.note' => 'catatan item',
        ];
    }

    public function messages(): array
    {
        return [
            'participant_id.required' => 'Peserta wajib dipilih.',
            'date.required' => 'Tanggal mutabaah wajib diisi.',
            'entries.required' => 'Minimal satu item mutabaah harus diisi.',
            'entries.min' => 'Minimal satu item mutabaah harus diisi.',
        ];
    }
}
