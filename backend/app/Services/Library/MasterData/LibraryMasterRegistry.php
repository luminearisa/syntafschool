<?php

namespace App\Services\Library\MasterData;

use App\Models\Author;
use App\Models\BookCategory;
use App\Models\LibraryAcquisitionSource;
use App\Models\LibraryAudienceType;
use App\Models\LibraryClassification;
use App\Models\LibraryClassificationSystem;
use App\Models\LibraryCollectionCondition;
use App\Models\LibraryCollectionStatus;
use App\Models\LibraryCollectionType;
use App\Models\LibraryCountry;
use App\Models\LibraryCreatorRole;
use App\Models\LibraryGenre;
use App\Models\LibraryLanguage;
use App\Models\LibraryMaterialType;
use App\Models\LibraryMembershipType;
use App\Models\LibrarySeries;
use App\Models\LibrarySubject;
use App\Models\LibraryTag;
use App\Models\Publisher;

/**
 * Registry master data modul Library (Step 2).
 *
 * Satu sumber kebenaran untuk: path API, model, scope, hierarki, aturan
 * validasi, kolom pencarian/filter, relasi, dan definisi "pemakaian"
 * (untuk usage count & proteksi hapus) — dipakai bersama oleh controller,
 * request, service, resource, seeder permission, dan endpoint `master-meta`
 * yang menyetir antarmuka.
 *
 * Entity existing (publisher, creator/person, category) dipakai ulang di sini
 * dan tidak diduplikasi (spesifikasi §4).
 */
class LibraryMasterRegistry
{
    /**
     * Seluruh master, dikunci dengan path API-nya.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            // ================= CORE =================
            'collection-types' => [
                'permission' => 'collection_type',
                'label' => 'Jenis Koleksi',
                'group' => 'core',
                'model' => LibraryCollectionType::class,
                'scoped' => true,
                'hierarchical' => false,
                'search' => ['code', 'name'],
                'filter' => ['is_digital', 'is_physical'],
                'rules' => [
                    'is_digital' => ['boolean'],
                    'is_physical' => ['boolean'],
                    'icon' => ['nullable', 'string', 'max:50'],
                    'color' => ['nullable', 'string', 'max:20'],
                ],
                'labels' => ['is_digital' => 'Koleksi Digital', 'is_physical' => 'Koleksi Fisik'],
                'usage' => [['table' => 'library_bibliographic_records', 'column' => 'collection_type_id', 'label' => 'record bibliografi']],
            ],
            'categories' => [
                'permission' => 'category',
                'label' => 'Kategori Koleksi',
                'group' => 'core',
                'model' => BookCategory::class,
                'scoped' => true,
                'hierarchical' => true,
                'search' => ['code', 'name'],
                'filter' => [],
                'rules' => [],
                'usage' => [['table' => 'library_bibliographic_records', 'column' => 'category_id', 'label' => 'record bibliografi']],
            ],
            'material-types' => [
                'permission' => 'material_type',
                'label' => 'Bentuk Media',
                'group' => 'core',
                'model' => LibraryMaterialType::class,
                'scoped' => false,
                'hierarchical' => false,
                'search' => ['code', 'name'],
                'filter' => [],
                'rules' => [],
                'usage' => [['table' => 'library_bibliographic_records', 'column' => 'material_type_id', 'label' => 'record bibliografi']],
            ],
            'languages' => [
                'permission' => 'language',
                'label' => 'Bahasa',
                'group' => 'core',
                'model' => LibraryLanguage::class,
                'scoped' => false,
                'hierarchical' => false,
                'search' => ['code', 'name', 'native_name'],
                'filter' => [],
                'rules' => [
                    'native_name' => ['nullable', 'string', 'max:100'],
                    'iso_code' => ['nullable', 'string', 'max:10'],
                ],
                'labels' => ['native_name' => 'Nama Asli', 'iso_code' => 'Kode ISO'],
                'usage' => [['table' => 'library_bibliographic_languages', 'column' => 'language_id', 'label' => 'record bibliografi']],
            ],
            'publishers' => [
                'permission' => 'publisher',
                'label' => 'Penerbit',
                'group' => 'core',
                'model' => Publisher::class,
                'scoped' => true,
                'hierarchical' => false,
                'search' => ['code', 'name', 'short_name'],
                'filter' => ['country_id'],
                'relations' => ['country' => ['relation' => 'countries', 'label' => 'Negara']],
                'rules' => [
                    'short_name' => ['nullable', 'string', 'max:100'],
                    'city' => ['nullable', 'string', 'max:100'],
                    'province' => ['nullable', 'string', 'max:100'],
                    'postal_code' => ['nullable', 'string', 'max:10'],
                    'phone' => ['nullable', 'string', 'max:20'],
                    'email' => ['nullable', 'email', 'max:100'],
                    'website' => ['nullable', 'string', 'max:150'],
                    'contact_person' => ['nullable', 'string', 'max:150'],
                    'address' => ['nullable', 'string'],
                    'notes' => ['nullable', 'string'],
                ],
                'labels' => ['short_name' => 'Nama Singkat', 'contact_person' => 'Kontak', 'address' => 'Alamat', 'notes' => 'Catatan'],
                'textareas' => ['address', 'notes'],
                'usage' => [['table' => 'library_bibliographic_records', 'column' => 'publisher_id', 'label' => 'record bibliografi']],
            ],
            'creators' => [
                'permission' => 'creator',
                'label' => 'Kreator',
                'group' => 'core',
                'model' => Author::class,
                'scoped' => true,
                'hierarchical' => false,
                'search' => ['code', 'name'],
                'filter' => ['country_id'],
                'relations' => ['country' => ['relation' => 'countries', 'label' => 'Negara']],
                'rules' => ['notes' => ['nullable', 'string']],
                'labels' => ['notes' => 'Catatan'],
                'textareas' => ['notes'],
                'usage' => [['table' => 'library_bibliographic_creators', 'column' => 'creator_id', 'label' => 'karya']],
            ],
            'creator-roles' => [
                'permission' => 'creator_role',
                'label' => 'Peran Kreator',
                'group' => 'core',
                'model' => LibraryCreatorRole::class,
                'scoped' => false,
                'hierarchical' => false,
                'search' => ['code', 'name'],
                'filter' => [],
                'rules' => [],
                'usage' => [['table' => 'library_bibliographic_creators', 'column' => 'creator_role_id', 'label' => 'karya']],
            ],
            'subjects' => [
                'permission' => 'subject',
                'label' => 'Subjek',
                'group' => 'core',
                'model' => LibrarySubject::class,
                'scoped' => true,
                'hierarchical' => true,
                'search' => ['code', 'name'],
                'filter' => [],
                'rules' => [],
                'usage' => [['table' => 'library_bibliographic_subjects', 'column' => 'subject_id', 'label' => 'record bibliografi']],
            ],
            'classification-systems' => [
                'permission' => 'classification_system',
                'label' => 'Sistem Klasifikasi',
                'group' => 'core',
                'model' => LibraryClassificationSystem::class,
                'scoped' => true,
                'hierarchical' => false,
                'search' => ['code', 'name'],
                'filter' => [],
                'rules' => [],
                'usage' => [['table' => 'library_classifications', 'column' => 'system_id', 'label' => 'nomor klasifikasi']],
            ],
            'classifications' => [
                'permission' => 'classification',
                'label' => 'Klasifikasi',
                'group' => 'core',
                'model' => LibraryClassification::class,
                'scoped' => true,
                'hierarchical' => true,
                // Kode klasifikasi unik per sistem (mis. DDC 297 hanya sekali).
                'unique' => ['system_id', 'code'],
                'search' => ['code', 'name'],
                'filter' => ['system_id'],
                'relations' => ['system' => ['relation' => 'classification-systems', 'label' => 'Sistem']],
                'rules' => ['level' => ['nullable', 'integer', 'min:1', 'max:20']],
                'labels' => ['level' => 'Tingkat'],
                'usage' => [['table' => 'library_bibliographic_records', 'column' => 'classification_id', 'label' => 'record bibliografi']],
            ],
            'genres' => [
                'permission' => 'genre',
                'label' => 'Genre',
                'group' => 'core',
                'model' => LibraryGenre::class,
                'scoped' => true,
                'hierarchical' => true,
                'search' => ['code', 'name'],
                'filter' => [],
                'rules' => [],
                'usage' => [['table' => 'library_bibliographic_records', 'column' => 'genre_id', 'label' => 'record bibliografi']],
            ],
            'audience-types' => [
                'permission' => 'audience',
                'label' => 'Target Pembaca',
                'group' => 'core',
                'model' => LibraryAudienceType::class,
                'scoped' => true,
                'hierarchical' => false,
                'search' => ['code', 'name'],
                'filter' => [],
                'rules' => [
                    'min_age' => ['nullable', 'integer', 'min:0', 'max:150'],
                    'max_age' => ['nullable', 'integer', 'min:0', 'max:150'],
                ],
                'labels' => ['min_age' => 'Usia Minimum', 'max_age' => 'Usia Maksimum'],
                'usage' => [['table' => 'library_bibliographic_records', 'column' => 'audience_type_id', 'label' => 'record bibliografi']],
            ],
            'acquisition-sources' => [
                'permission' => 'acquisition_source',
                'label' => 'Sumber Perolehan',
                'group' => 'core',
                'model' => LibraryAcquisitionSource::class,
                'scoped' => true,
                'hierarchical' => false,
                'search' => ['code', 'name'],
                'filter' => [],
                'rules' => [],
                'usage' => [['table' => 'library_acquisitions', 'column' => 'acquisition_source_id', 'label' => 'pengadaan']],
            ],
            'conditions' => [
                'permission' => 'condition',
                'label' => 'Kondisi Koleksi',
                'group' => 'core',
                'model' => LibraryCollectionCondition::class,
                'scoped' => true,
                'hierarchical' => false,
                'search' => ['code', 'name'],
                'filter' => ['is_borrowable'],
                'rules' => ['is_borrowable' => ['boolean']],
                'labels' => ['is_borrowable' => 'Dapat Dipinjam'],
                'usage' => [['table' => 'library_items', 'column' => 'condition_id', 'label' => 'eksemplar']],
            ],
            'collection-statuses' => [
                'permission' => 'collection_status',
                'label' => 'Status Sirkulasi',
                'group' => 'core',
                'model' => LibraryCollectionStatus::class,
                'scoped' => true,
                'hierarchical' => false,
                'search' => ['code', 'name'],
                'filter' => ['is_available'],
                'rules' => ['is_available' => ['boolean']],
                'labels' => ['is_available' => 'Tersedia untuk Dipinjam'],
                'usage' => [['table' => 'library_items', 'column' => 'status_id', 'label' => 'eksemplar']],
            ],

            // ================= SUPPORTING =================
            'countries' => [
                'permission' => 'country',
                'label' => 'Negara',
                'group' => 'supporting',
                'model' => LibraryCountry::class,
                'scoped' => false,
                'hierarchical' => false,
                'search' => ['code', 'name', 'iso_code'],
                'filter' => [],
                'rules' => [
                    'iso_code' => ['nullable', 'string', 'max:3'],
                    'phone_code' => ['nullable', 'string', 'max:6'],
                ],
                'labels' => ['iso_code' => 'Kode ISO', 'phone_code' => 'Kode Telepon'],
                'usage' => [
                    ['table' => 'publishers', 'column' => 'country_id', 'label' => 'penerbit'],
                    ['table' => 'authors', 'column' => 'country_id', 'label' => 'kreator'],
                ],
            ],
            'series' => [
                'permission' => 'series',
                'label' => 'Series',
                'group' => 'supporting',
                'model' => LibrarySeries::class,
                'scoped' => true,
                'hierarchical' => false,
                'search' => ['code', 'name'],
                'filter' => ['publisher_id'],
                'relations' => ['publisher' => ['relation' => 'publishers', 'label' => 'Penerbit']],
                'rules' => [],
                'usage' => [['table' => 'library_bibliographic_records', 'column' => 'series_id', 'label' => 'record bibliografi']],
            ],
            'tags' => [
                'permission' => 'tag',
                'label' => 'Tag',
                'group' => 'supporting',
                'model' => LibraryTag::class,
                'scoped' => true,
                'hierarchical' => false,
                'search' => ['code', 'name'],
                'filter' => [],
                'rules' => [],
                'usage' => [['table' => 'library_bibliographic_tags', 'column' => 'tag_id', 'label' => 'record bibliografi']],
            ],
            // Fase 3 — jenis keanggotaan; policy pinjamnya adalah referensi
            // konfigurasi (penerapan di modul Circulation/Fase 4).
            'membership-types' => [
                'permission' => 'membership_type',
                'label' => 'Jenis Keanggotaan',
                'group' => 'supporting',
                'model' => LibraryMembershipType::class,
                'scoped' => true,
                'hierarchical' => false,
                'search' => ['code', 'name'],
                'filter' => ['can_reserve'],
                'rules' => [
                    'max_loan' => ['nullable', 'integer', 'min:0', 'max:100'],
                    'loan_duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
                    'renewal_limit' => ['nullable', 'integer', 'min:0', 'max:20'],
                    'can_reserve' => ['boolean'],
                ],
                'labels' => [
                    'max_loan' => 'Maksimum Pinjam',
                    'loan_duration_days' => 'Durasi Pinjam (hari)',
                    'renewal_limit' => 'Batas Perpanjangan',
                    'can_reserve' => 'Boleh Reservasi',
                ],
                'usage' => [['table' => 'library_members', 'column' => 'membership_type_id', 'label' => 'anggota']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(?string $key): ?array
    {
        if ($key === null) {
            return null;
        }

        return self::all()[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Nama permission yang dibangkitkan dari registry (`library.master.view`
     * dan `library.{key}.{aksi}` per master).
     *
     * @return list<string>
     */
    public static function permissionNames(): array
    {
        $names = ['library.master.view'];

        foreach (self::permissions() as $permission) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $names[] = "library.{$permission}.{$action}";
            }
        }

        return $names;
    }

    /**
     * Kunci permission unik per master (mis. `collection_type`).
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        return array_values(array_unique(array_column(self::all(), 'permission')));
    }

    /**
     * Nama tabel untuk sebuah path master (dipakai aturan `exists` pada
     * relasi, mis. `country_id` → `library_countries`).
     */
    public static function table(string $key): ?string
    {
        if ($key === 'libraries') {
            return 'libraries';
        }

        $master = self::find($key);

        return $master === null ? null : (new $master['model'])->getTable();
    }

    /**
     * Kolom yang harus unik untuk sebuah master (default: `code`).
     *
     * @return list<string>
     */
    public static function uniqueColumns(string $key): array
    {
        return self::find($key)['unique'] ?? ['code'];
    }

    /**
     * Definisi field untuk `GET library/master-meta` — menyetir form di
     * antarmuka sehingga satu halaman dapat melayani semua master.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(string $key): array
    {
        $master = self::find($key);

        if ($master === null) {
            return [];
        }

        $fields = [];

        if ($master['scoped']) {
            $fields[] = [
                'name' => 'library_id',
                'label' => 'Perpustakaan',
                'type' => 'relation',
                'relation' => 'libraries',
                'required' => false,
                'help' => 'Kosongkan untuk master global (berlaku semua perpustakaan).',
            ];
        }

        $fields[] = ['name' => 'code', 'label' => 'Kode', 'type' => 'text', 'required' => true];
        $fields[] = ['name' => 'name', 'label' => 'Nama', 'type' => 'text', 'required' => true];
        $fields[] = ['name' => 'description', 'label' => 'Deskripsi', 'type' => 'textarea', 'required' => false];

        if ($master['hierarchical']) {
            $fields[] = [
                'name' => 'parent_id',
                'label' => 'Induk',
                'type' => 'relation',
                'relation' => $key,
                'required' => false,
            ];
        }

        foreach ($master['relations'] ?? [] as $name => $meta) {
            $fields[] = [
                'name' => $name.'_id',
                'label' => $meta['label'],
                'type' => 'relation',
                'relation' => $meta['relation'],
                'required' => false,
            ];
        }

        foreach ($master['rules'] as $column => $rules) {
            if (in_array($column, ['code', 'name'], true)) {
                continue;
            }

            $fields[] = [
                'name' => $column,
                'label' => $master['labels'][$column] ?? ucfirst(str_replace('_', ' ', $column)),
                'type' => in_array($column, $master['textareas'] ?? [], true)
                    ? 'textarea'
                    : self::fieldType($rules),
                'required' => in_array('required', $rules, true),
            ];
        }

        $fields[] = ['name' => 'status', 'label' => 'Status', 'type' => 'status', 'required' => false];
        $fields[] = ['name' => 'sort_order', 'label' => 'Urutan', 'type' => 'number', 'required' => false];

        return $fields;
    }

    /**
     * @param  list<string>  $rules
     */
    private static function fieldType(array $rules): string
    {
        if (in_array('boolean', $rules, true)) {
            return 'boolean';
        }

        if (in_array('integer', $rules, true) || in_array('numeric', $rules, true)) {
            return 'number';
        }

        return 'text';
    }
}