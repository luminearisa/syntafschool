<?php

namespace App\Http\Requests\Library;

use App\Models\LibraryBibliography;
use App\Models\LibraryBibliographyIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Bibliographic record beserta relasinya.
 *
 * Semua relasi (kreator, subjek, genre, tag, identifier, berkas) dikirim
 * dalam satu permintaan dan disinkronkan menyeluruh oleh service, sehingga
 * tidak ada baris yatim.
 */
class LibraryBibliographyRequest extends FormRequest
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
            'library_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', 'exists:libraries,id'],
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'uniform_title' => ['nullable', 'string', 'max:255'],

            'edition_statement' => ['nullable', 'string', 'max:100'],
            'edition_number' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'edition_year' => ['nullable', 'integer', 'min:1000', 'max:2200'],

            'publisher_id' => ['nullable', 'integer', 'exists:publishers,id'],
            'publication_place' => ['nullable', 'string', 'max:100'],
            'publication_year' => ['nullable', 'integer', 'min:1000', 'max:2200'],
            'publication_statement' => ['nullable', 'string', 'max:200'],

            'language_id' => ['nullable', 'integer', 'exists:library_languages,id'],
            'collection_type_id' => ['nullable', 'integer', 'exists:library_collection_types,id'],
            'category_id' => ['nullable', 'integer', 'exists:book_categories,id'],
            'material_type_id' => ['nullable', 'integer', 'exists:library_material_types,id'],
            'series_id' => ['nullable', 'integer', 'exists:library_series,id'],
            'classification_id' => ['nullable', 'integer', 'exists:library_classifications,id'],

            'call_number' => ['nullable', 'string', 'max:100'],

            'description' => ['nullable', 'string'],
            'abstract' => ['nullable', 'string'],
            'table_of_contents' => ['nullable', 'string'],
            'physical_description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],

            'cover_image' => ['nullable', 'string', 'max:255'],
            'thumbnail' => ['nullable', 'string', 'max:255'],

            'digital_available' => ['boolean'],
            'physical_available' => ['boolean'],
            'status' => ['nullable', 'string', 'in:'.implode(',', LibraryBibliography::STATUSES)],
            'regenerate_slug' => ['boolean'],

            'creators' => ['nullable', 'array'],
            'creators.*.creator_id' => ['required', 'integer', 'exists:authors,id'],
            'creators.*.creator_role_id' => ['nullable', 'integer', 'exists:library_creator_roles,id'],
            'creators.*.sequence' => ['nullable', 'integer', 'min:1'],
            'creators.*.is_primary' => ['boolean'],
            'creators.*.display_name' => ['nullable', 'string', 'max:150'],

            'subjects' => ['nullable', 'array'],
            'subjects.*' => ['integer', 'exists:library_subjects,id'],

            'genres' => ['nullable', 'array'],
            'genres.*' => ['integer', 'exists:library_genres,id'],

            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', 'exists:library_tags,id'],

            'identifiers' => ['nullable', 'array'],
            'identifiers.*.identifier_type' => ['required', 'string', 'in:'.implode(',', LibraryBibliography::IDENTIFIER_TYPES)],
            'identifiers.*.value' => ['required', 'string', 'max:100'],
            'identifiers.*.is_primary' => ['boolean'],

            'files' => ['nullable', 'array'],
            'files.*.file_type' => ['required', 'string', 'in:'.implode(',', LibraryBibliography::FILE_TYPES)],
            'files.*.file_path' => ['required', 'string', 'max:255'],
            'files.*.caption' => ['nullable', 'string', 'max:150'],
            'files.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateIdentifiers($validator);
                $this->validatePrimaryCreator($validator);
            },
        ];
    }

    /**
     * Identifier baku (ISBN/ISSN) tidak boleh dipakai record lain pada
     * perpustakaan yang sama — inilah duplikasi yang benar-benar ditolak
     * (§18), sedangkan kemiripan judul hanya memunculkan peringatan.
     */
    protected function validateIdentifiers(Validator $validator): void
    {
        $identifiers = (array) $this->input('identifiers', []);
        $libraryId = $this->input('library_id') ?? $this->route('bibliography')?->library_id;
        $ignoreId = $this->route('bibliography')?->id;

        $seen = [];

        foreach ($identifiers as $index => $identifier) {
            if (! in_array($identifier['identifier_type'] ?? null, ['isbn10', 'isbn13', 'issn'], true)) {
                continue;
            }

            $normalized = LibraryBibliographyIdentifier::normalize($identifier['value'] ?? null);

            if ($normalized === null) {
                continue;
            }

            if (in_array($normalized, $seen, true)) {
                $validator->errors()->add("identifiers.{$index}.value", 'Identifier ini dikirim lebih dari sekali.');

                continue;
            }

            $seen[] = $normalized;

            $exists = LibraryBibliographyIdentifier::query()
                ->where('normalized_value', $normalized)
                ->when($ignoreId !== null, fn ($q) => $q->where('bibliography_id', '!=', $ignoreId))
                ->when($libraryId !== null, fn ($q) => $q->whereHas(
                    'bibliography',
                    fn ($b) => $b->where('library_id', $libraryId)
                ))
                ->exists();

            if ($exists) {
                $validator->errors()->add(
                    "identifiers.{$index}.value",
                    'Identifier ini sudah dipakai record lain pada perpustakaan yang sama.'
                );
            }
        }
    }

    /**
     * Hanya boleh ada satu kreator utama.
     */
    protected function validatePrimaryCreator(Validator $validator): void
    {
        $primaries = collect((array) $this->input('creators', []))
            ->filter(fn ($creator) => (bool) ($creator['is_primary'] ?? false))
            ->count();

        if ($primaries > 1) {
            $validator->errors()->add('creators', 'Hanya satu kreator yang boleh ditandai utama.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'library_id' => 'perpustakaan',
            'title' => 'judul',
            'subtitle' => 'anak judul',
            'publisher_id' => 'penerbit',
            'publication_year' => 'tahun terbit',
            'language_id' => 'bahasa',
            'collection_type_id' => 'jenis koleksi',
            'category_id' => 'kategori',
            'material_type_id' => 'bentuk media',
            'classification_id' => 'klasifikasi',
            'call_number' => 'call number',
            'creators' => 'kreator',
            'identifiers' => 'identifier',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Judul wajib diisi.',
            'library_id.required' => 'Perpustakaan wajib dipilih.',
            'creators.*.creator_id.required' => 'Kreator wajib dipilih.',
            'identifiers.*.value.required' => 'Nilai identifier wajib diisi.',
        ];
    }
}