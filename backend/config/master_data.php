<?php

/**
 * Pemetaan resource master data untuk CRUD generik.
 *
 * Setiap resource mendefinisikan:
 *  - model    : FQCN model Eloquent
 *  - search   : kolom yang dapat dicari
 *  - sort     : kolom yang dapat diurutkan
 *  - filter   : kolom yang dapat difilter
 *  - rules    : aturan validasi (store)
 *  - update_rules : aturan validasi (update), opsional
 *  - relations: relasi yang di-eager load
 *  - read_only: true jika data tidak boleh diubah via API
 */

return [

    // ================= KLASIFIKASI =================
    'religions' => [
        'model' => \App\Models\Religion::class,
        'search' => ['name'],
        'sort' => ['name', 'created_at'],
        'filter' => ['is_active'],
        'rules' => ['name' => ['required', 'string', 'max:50', 'unique:religions,name']],
    ],
    'genders' => [
        'model' => \App\Models\Gender::class,
        'search' => ['name', 'code'],
        'sort' => ['name', 'code'],
        'rules' => [
            'code' => ['required', 'string', 'max:1', 'unique:genders,code'],
            'name' => ['required', 'string', 'max:20', 'unique:genders,name'],
        ],
    ],
    'occupations' => [
        'model' => \App\Models\Occupation::class,
        'search' => ['name'],
        'sort' => ['name'],
        'rules' => ['name' => ['required', 'string', 'max:100', 'unique:occupations,name']],
    ],
    'education-backgrounds' => [
        'model' => \App\Models\EducationBackground::class,
        'search' => ['name'],
        'sort' => ['name', 'level_number'],
        'rules' => [
            'name' => ['required', 'string', 'max:50', 'unique:education_backgrounds,name'],
            'level_number' => ['nullable', 'integer'],
        ],
    ],
    'income-ranges' => [
        'model' => \App\Models\IncomeRange::class,
        'search' => ['label'],
        'sort' => ['label', 'min_value'],
        'rules' => [
            'label' => ['required', 'string', 'max:100'],
            'min_value' => ['nullable', 'numeric'],
            'max_value' => ['nullable', 'numeric', 'gte:min_value'],
        ],
    ],
    'guardian-relations' => [
        'model' => \App\Models\GuardianRelation::class,
        'search' => ['name'],
        'sort' => ['name'],
        'rules' => ['name' => ['required', 'string', 'max:50', 'unique:guardian_relations,name']],
    ],
    'residence-types' => [
        'model' => \App\Models\ResidenceType::class,
        'search' => ['name'],
        'sort' => ['name'],
        'rules' => ['name' => ['required', 'string', 'max:50', 'unique:residence_types,name']],
    ],
    'transportations' => [
        'model' => \App\Models\Transportation::class,
        'search' => ['name'],
        'sort' => ['name'],
        'rules' => ['name' => ['required', 'string', 'max:50', 'unique:transportations,name']],
    ],
    'student-statuses' => [
        'model' => \App\Models\StudentStatus::class,
        'search' => ['name'],
        'sort' => ['name'],
        'filter' => ['is_active'],
        'rules' => ['name' => ['required', 'string', 'max:50', 'unique:student_statuses,name']],
    ],

    // ================= WILAYAH =================
    'provinces' => [
        'model' => \App\Models\Province::class,
        'search' => ['name'],
        'sort' => ['name', 'id'],
        'read_only' => true,
        'primary_key' => 'id',
        'key_type' => 'string',
    ],
    'cities' => [
        'model' => \App\Models\City::class,
        'search' => ['name'],
        'sort' => ['name'],
        'filter' => ['province_id'],
        'relations' => ['province'],
        'read_only' => true,
        'key_type' => 'string',
    ],
    'districts' => [
        'model' => \App\Models\District::class,
        'search' => ['name'],
        'sort' => ['name'],
        'filter' => ['city_id'],
        'relations' => ['city'],
        'read_only' => true,
        'key_type' => 'string',
    ],
    'villages' => [
        'model' => \App\Models\Village::class,
        'search' => ['name', 'postal_code'],
        'sort' => ['name'],
        'filter' => ['district_id'],
        'relations' => ['district'],
        'read_only' => true,
        'key_type' => 'string',
    ],

    // ================= AKADEMIK =================
    'curricula' => [
        'model' => \App\Models\Curriculum::class,
        'search' => ['name', 'code'],
        'sort' => ['name', 'code'],
        'filter' => ['is_active'],
        'rules' => [
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20', 'unique:curricula,code'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ],
    ],
    'education-levels' => [
        'model' => \App\Models\EducationLevel::class,
        'search' => ['name'],
        'sort' => ['name', 'level_number'],
        'filter' => ['jenjang', 'is_active'],
        'rules' => [
            'name' => ['required', 'string', 'max:50'],
            'level_number' => ['required', 'integer'],
            'jenjang' => ['required', 'in:SD,SMP,SMA,SMK'],
            'is_active' => ['boolean'],
        ],
    ],
    'academic-years' => [
        'model' => \App\Models\AcademicYear::class,
        'search' => ['name'],
        'sort' => ['name', 'start_date'],
        'filter' => ['is_active'],
        'rules' => [
            'name' => ['required', 'string', 'max:20', 'unique:academic_years,name'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'is_active' => ['boolean'],
        ],
    ],
    'semesters' => [
        'model' => \App\Models\Semester::class,
        'search' => ['name'],
        'sort' => ['name', 'start_date'],
        'filter' => ['academic_year_id', 'is_active'],
        'relations' => ['academicYear'],
        'rules' => [
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'name' => ['required', 'in:Ganjil,Genap'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'is_active' => ['boolean'],
        ],
    ],
    'subjects' => [
        'model' => \App\Models\Subject::class,
        'search' => ['name', 'code'],
        'sort' => ['name', 'code'],
        'filter' => ['curriculum_id', 'education_level_id', 'category', 'is_active'],
        'relations' => ['curriculum', 'educationLevel'],
        'rules' => [
            'code' => ['required', 'string', 'max:20', 'unique:subjects,code'],
            'name' => ['required', 'string', 'max:100'],
            'curriculum_id' => ['nullable', 'exists:curricula,id'],
            'education_level_id' => ['nullable', 'exists:education_levels,id'],
            'category' => ['required', 'in:Wajib,Muatan Lokal,Pilihan'],
            'credit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ],
    ],
    'rooms' => [
        'model' => \App\Models\Room::class,
        'search' => ['name', 'code'],
        'sort' => ['name', 'code'],
        'filter' => ['type', 'is_active'],
        'rules' => [
            'code' => ['required', 'string', 'max:20', 'unique:rooms,code'],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:Kelas,Lab,Perpustakaan,Kantor,Lainnya'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'building' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ],
    ],

    // ================= KESISWAAN =================
    'violation-types' => [
        'model' => \App\Models\ViolationType::class,
        'search' => ['name'],
        'sort' => ['name', 'point'],
        'filter' => ['category', 'is_active'],
        'rules' => [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'in:Ringan,Sedang,Berat'],
            'point' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ],
    ],
    'achievement-types' => [
        'model' => \App\Models\AchievementType::class,
        'search' => ['name'],
        'sort' => ['name'],
        'filter' => ['level'],
        'rules' => [
            'name' => ['required', 'string', 'max:100'],
            'level' => ['required', 'in:Sekolah,Kecamatan,Kabupaten,Provinsi,Nasional,Internasional'],
        ],
    ],
    'extracurriculars' => [
        'model' => \App\Models\Extracurricular::class,
        'search' => ['name', 'instructor'],
        'sort' => ['name'],
        'filter' => ['is_active'],
        'rules' => [
            'name' => ['required', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:50'],
            'instructor' => ['nullable', 'string', 'max:150'],
            'is_active' => ['boolean'],
        ],
    ],

    // ================= KEUANGAN =================
    'fee-components' => [
        'model' => \App\Models\FeeComponent::class,
        'search' => ['name', 'code'],
        'sort' => ['name', 'code'],
        'filter' => ['type', 'is_active'],
        'rules' => [
            'code' => ['required', 'string', 'max:20', 'unique:fee_components,code'],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:Bulanan,Tahunan,Sekali'],
            'default_amount' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ],
    ],
    'payment-methods' => [
        'model' => \App\Models\PaymentMethod::class,
        'search' => ['name', 'code'],
        'sort' => ['name'],
        'filter' => ['is_active'],
        'rules' => [
            'name' => ['required', 'string', 'max:50'],
            'code' => ['required', 'string', 'max:20', 'unique:payment_methods,code'],
            'is_active' => ['boolean'],
        ],
    ],
    'banks' => [
        'model' => \App\Models\Bank::class,
        'search' => ['name', 'code'],
        'sort' => ['name'],
        'filter' => ['is_active'],
        'rules' => [
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:10', 'unique:banks,code'],
            'is_active' => ['boolean'],
        ],
    ],
    'discount-types' => [
        'model' => \App\Models\DiscountType::class,
        'search' => ['name'],
        'sort' => ['name'],
        'filter' => ['type', 'is_active'],
        'rules' => [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:Persen,Nominal'],
            'value' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ],
    ],
    'fiscal-years' => [
        'model' => \App\Models\FiscalYear::class,
        'search' => ['name'],
        'sort' => ['name'],
        'filter' => ['is_active'],
        'rules' => [
            'name' => ['required', 'string', 'max:20', 'unique:fiscal_years,name'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'is_active' => ['boolean'],
        ],
    ],

    // ================= PERPUSTAKAAN =================
    // Catatan: kategori, penerbit, dan kreator (authors) TIDAK lagi dikelola
    // di sini — ketiganya sekarang menjadi master modul Library
    // (lihat LibraryMasterRegistry) agar hanya ada satu jalur tulis dengan
    // permission library, bukan dua jalur dengan permission berbeda.
    'shelves' => [
        'model' => \App\Models\Shelf::class,
        'search' => ['name', 'code'],
        'sort' => ['name'],
        'rules' => [
            'code' => ['required', 'string', 'max:20', 'unique:shelves,code'],
            'name' => ['required', 'string', 'max:50'],
        ],
    ],

    // ================= INVENTARIS =================
    'item-categories' => [
        'model' => \App\Models\ItemCategory::class,
        'search' => ['name'],
        'sort' => ['name'],
        'filter' => ['type'],
        'rules' => [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:Aset,Habis Pakai'],
        ],
    ],
    'units' => [
        'model' => \App\Models\Unit::class,
        'search' => ['name', 'symbol'],
        'sort' => ['name'],
        'rules' => [
            'name' => ['required', 'string', 'max:50'],
            'symbol' => ['nullable', 'string', 'max:10'],
        ],
    ],
    'storage-locations' => [
        'model' => \App\Models\StorageLocation::class,
        'search' => ['name'],
        'sort' => ['name'],
        'relations' => ['room'],
        'rules' => [
            'name' => ['required', 'string', 'max:100'],
            'room_id' => ['nullable', 'exists:rooms,id'],
        ],
    ],

    // ================= ABSENSI =================
    'attendance-statuses' => [
        'model' => \App\Models\AttendanceStatus::class,
        'search' => ['name', 'code'],
        'sort' => ['name'],
        'rules' => [
            'code' => ['required', 'string', 'max:2', 'unique:attendance_statuses,code'],
            'name' => ['required', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:10'],
        ],
    ],
    'leave-types' => [
        'model' => \App\Models\LeaveType::class,
        'search' => ['name'],
        'sort' => ['name'],
        'rules' => [
            'name' => ['required', 'string', 'max:100'],
            'requires_attachment' => ['boolean'],
        ],
    ],

    // ================= SISTEM =================
    'days' => [
        'model' => \App\Models\Day::class,
        'search' => ['name'],
        'sort' => ['id'],
        'read_only' => true,
        'key_type' => 'int',
    ],
    'document-sequences' => [
        'model' => \App\Models\DocumentSequence::class,
        'search' => ['code'],
        'sort' => ['code'],
        'rules' => [
            'code' => ['required', 'string', 'max:50', 'unique:document_sequences,code'],
            'prefix' => ['nullable', 'string', 'max:20'],
            'format' => ['nullable', 'string', 'max:100'],
            'reset_period' => ['required', 'in:never,year,month'],
        ],
    ],

    // ================= INSTITUSI =================
    'schools' => [
        'model' => \App\Models\School::class,
        'search' => ['name', 'npsn'],
        'sort' => ['name'],
        'filter' => ['jenjang', 'status', 'is_active'],
        'relations' => ['foundation', 'province', 'city', 'district', 'village'],
        'rules' => [
            'npsn' => ['nullable', 'string', 'max:20', 'unique:schools,npsn'],
            'name' => ['required', 'string', 'max:150'],
            'jenjang' => ['required', 'in:SD,SMP,SMA,SMK'],
            'status' => ['required', 'in:Negeri,Swasta'],
            'accreditation' => ['nullable', 'string', 'max:5'],
            'foundation_id' => ['nullable', 'exists:foundations,id'],
            'address' => ['nullable', 'string'],
            'province_id' => ['nullable', 'string', 'max:2'],
            'city_id' => ['nullable', 'string', 'max:4'],
            'district_id' => ['nullable', 'string', 'max:7'],
            'village_id' => ['nullable', 'string', 'max:10'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'website' => ['nullable', 'string', 'max:150'],
            'headmaster_name' => ['nullable', 'string', 'max:150'],
            'is_active' => ['boolean'],
        ],
    ],
    'foundations' => [
        'model' => \App\Models\Foundation::class,
        'search' => ['name'],
        'sort' => ['name'],
        'filter' => ['is_active'],
        'rules' => [
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'is_active' => ['boolean'],
        ],
    ],
];
