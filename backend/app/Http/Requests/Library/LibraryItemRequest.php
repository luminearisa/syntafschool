<?php

namespace App\Http\Requests\Library;

use App\Models\LibraryItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Eksemplar koleksi.
 *
 * Item code & nomor inventaris dibangkitkan server bila tidak dikirim;
 * barcode/RFID boleh kosong tetapi harus unik bila diisi (Rule 3 & 4).
 */
class LibraryItemRequest extends FormRequest
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
        $itemId = $this->route('item')?->id;

        return [
            'item_code' => ['nullable', 'string', 'max:50'],
            'accession_number' => ['nullable', 'string', 'max:50'],
            'barcode' => ['nullable', 'string', 'max:50', Rule::unique('library_items', 'barcode')->ignore($itemId)],
            'rfid_tag' => ['nullable', 'string', 'max:100', Rule::unique('library_items', 'rfid_tag')->ignore($itemId)],
            'rfid_status' => ['nullable', 'string', 'in:'.implode(',', LibraryItem::RFID_STATUSES)],

            'location_id' => ['nullable', 'integer', 'exists:library_locations,id'],
            'condition_id' => ['nullable', 'integer', 'exists:library_collection_conditions,id'],
            'status_id' => ['nullable', 'integer', 'exists:library_collection_statuses,id'],
            'status' => ['nullable', 'string', 'in:'.implode(',', LibraryItem::STATUSES)],

            'acquisition_date' => ['nullable', 'date'],
            'acquisition_source_id' => ['nullable', 'integer', 'exists:library_acquisition_sources,id'],
            'acquisition_price' => ['nullable', 'numeric', 'min:0'],
            'funding_source' => ['nullable', 'string', 'max:100'],

            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'item_code' => 'kode eksemplar',
            'accession_number' => 'nomor inventaris',
            'barcode' => 'barcode',
            'rfid_tag' => 'tag RFID',
            'location_id' => 'lokasi',
            'condition_id' => 'kondisi',
            'status' => 'status',
            'acquisition_price' => 'harga perolehan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'barcode.unique' => 'Barcode sudah dipakai eksemplar lain.',
            'rfid_tag.unique' => 'Tag RFID sudah dipakai eksemplar lain.',
        ];
    }
}