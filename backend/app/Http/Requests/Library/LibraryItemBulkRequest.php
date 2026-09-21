<?php

namespace App\Http\Requests\Library;

use App\Models\LibraryItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pembuatan banyak eksemplar sekaligus (§28).
 *
 * Atribut lain dibagi rata ke seluruh eksemplar baru; item code, nomor
 * inventaris, dan barcode dibangkitkan per eksemplar.
 */
class LibraryItemBulkRequest extends FormRequest
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
            'quantity' => ['required', 'integer', 'min:1', 'max:200'],

            'location_id' => ['nullable', 'integer', 'exists:library_locations,id'],
            'condition_id' => ['nullable', 'integer', 'exists:library_collection_conditions,id'],
            'status_id' => ['nullable', 'integer', 'exists:library_collection_statuses,id'],
            'status' => ['nullable', 'string', 'in:'.implode(',', LibraryItem::STATUSES)],

            'acquisition_date' => ['nullable', 'date'],
            'acquisition_source_id' => ['nullable', 'integer', 'exists:library_acquisition_sources,id'],
            'acquisition_price' => ['nullable', 'numeric', 'min:0'],
            'funding_source' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],

            // Bila barcode diisi manual, jumlahnya harus sesuai kuantitas.
            'barcodes' => ['nullable', 'array'],
            'barcodes.*' => ['string', 'max:50'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                $barcodes = (array) $this->input('barcodes', []);

                if ($barcodes !== [] && count($barcodes) !== (int) $this->input('quantity')) {
                    $validator->errors()->add(
                        'barcodes',
                        'Jumlah barcode harus sama dengan kuantitas eksemplar.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.required' => 'Jumlah eksemplar wajib diisi.',
            'quantity.max' => 'Maksimum 200 eksemplar per pembuatan.',
        ];
    }
}