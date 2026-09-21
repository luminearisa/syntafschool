<?php

namespace App\Http\Requests\Library;

use App\Services\Library\MasterData\LibraryMasterRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validasi master data Library (Step 2).
 *
 * Aturan bentuk dibangkitkan dari `LibraryMasterRegistry` sehingga menambah
 * master baru tidak perlu menyunting kelas ini. Keunikan kode diperiksa di
 * `after()` karena harus memperhitungkan scope (global vs per-perpustakaan)
 * — SQLite menganggap NULL berbeda pada indeks unik, sehingga aturan `unique`
 * biasa tidak cukup untuk baris global.
 */
class LibraryMasterRequest extends FormRequest
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
        $master = $this->master();

        $rules = [
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];

        if ($master['scoped']) {
            $rules['library_id'] = ['nullable', 'integer', 'exists:libraries,id'];
        }

        if ($master['hierarchical']) {
            $rules['parent_id'] = [
                'nullable', 'integer',
                'exists:'.(new $master['model'])->getTable().',id',
            ];
        }

        foreach ($master['relations'] ?? [] as $name => $meta) {
            $table = LibraryMasterRegistry::table($meta['relation']);

            if ($table !== null) {
                $rules[$name.'_id'] = ['nullable', 'integer', 'exists:'.$table.',id'];
            }
        }

        foreach ($master['rules'] as $column => $columnRules) {
            $rules[$column] = $columnRules;
        }

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $master = $this->master();
                $model = new $master['model'];

                $query = $model->newQuery();

                foreach (LibraryMasterRegistry::uniqueColumns($this->masterKey()) as $column) {
                    $value = $this->input($column);

                    // Kolom opsional (mis. `publishers.code`) hanya diperiksa
                    // bila diisi.
                    if ($value === null || $value === '') {
                        return;
                    }

                    $query->where($column, $value);
                }

                // Keunikan kode diperhitungkan per scope.
                if ($master['scoped'] && in_array('code', LibraryMasterRegistry::uniqueColumns($this->masterKey()), true)) {
                    $libraryId = $this->input('library_id');

                    if ($libraryId === null || $libraryId === '') {
                        $query->whereNull('library_id');
                    } else {
                        $query->where('library_id', $libraryId);
                    }
                }

                if ($id = $this->route('id')) {
                    $query->where('id', '!=', $id);
                }

                if ($query->exists()) {
                    $validator->errors()->add(
                        'code',
                        'Kode sudah dipakai pada scope yang sama.'
                    );
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
            'code' => 'kode',
            'name' => 'nama',
            'library_id' => 'perpustakaan',
            'parent_id' => 'induk',
            'sort_order' => 'urutan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Kode wajib diisi.',
            'name.required' => 'Nama wajib diisi.',
        ];
    }

    public function masterKey(): string
    {
        return (string) $this->route('master');
    }

    /**
     * @return array<string, mixed>
     */
    protected function master(): array
    {
        return app(\App\Services\Library\MasterData\LibraryMasterService::class)
            ->descriptor($this->masterKey());
    }
}