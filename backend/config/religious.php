<?php

/**
 * Konfigurasi Religious & Mosque Management (School Operations Phase 3).
 *
 * Seluruh daftar master di sini dapat diubah tanpa menyentuh kode, karena
 * praktik keagamaan tiap institusi berbeda (jumlah rakaat tarawih, waktu iqamah,
 * jenis kegiatan, skala penilaian). Modul tidak menetapkan satu praktik fiqh
 * sebagai satu-satunya pilihan sistem (§48, §41).
 */
return [
    'module' => [
        'code' => 'school-operations.religious',
        'name' => 'Religious & Mosque Management',
        'version' => 3,
    ],

    // §10 jenis shalat (configurable: bukan hanya lima waktu wajib)
    'prayer_time' => [
        'timezone' => 'Asia/Jakarta',
        'latitude' => null,
        'longitude' => null,
        'calculation_method' => 'KEMENAG',
        'calculator' => null,
        'types' => [
            'FAJR' => ['name' => 'Subuh', 'arabic' => 'الفجر', 'category' => 'OBLIGATORY', 'rakaat' => 2, 'congregation' => true, 'order' => 1],
            'DHUHR' => ['name' => 'Dzuhur', 'arabic' => 'الظهر', 'category' => 'OBLIGATORY', 'rakaat' => 4, 'congregation' => true, 'order' => 2],
            'ASR' => ['name' => 'Ashar', 'arabic' => 'العصر', 'category' => 'OBLIGATORY', 'rakaat' => 4, 'congregation' => true, 'order' => 3],
            'MAGHRIB' => ['name' => 'Maghrib', 'arabic' => 'المغرب', 'category' => 'OBLIGATORY', 'rakaat' => 3, 'congregation' => true, 'order' => 4],
            'ISHA' => ['name' => 'Isya', 'arabic' => 'العشاء', 'category' => 'OBLIGATORY', 'rakaat' => 4, 'congregation' => true, 'order' => 5],
            'JUMUAH' => ['name' => 'Jumat', 'arabic' => 'الجمعة', 'category' => 'OBLIGATORY', 'rakaat' => 2, 'congregation' => true, 'order' => 6],
            'TARAWIH' => ['name' => 'Tarawih', 'category' => 'RAMADAN', 'rakaat' => 20, 'congregation' => true, 'ramadan_only' => true, 'order' => 7],
            'TAHAJJUD' => ['name' => 'Tahajjud', 'category' => 'SUNNAH', 'rakaat' => 8, 'congregation' => false, 'order' => 8],
            'EID_FITR' => ['name' => 'Idul Fitri', 'category' => 'EID', 'rakaat' => 2, 'congregation' => true, 'order' => 9],
            'EID_ADHA' => ['name' => 'Idul Adha', 'category' => 'EID', 'rakaat' => 2, 'congregation' => true, 'order' => 10],
            'OTHER' => ['name' => 'Lainnya', 'category' => 'OTHER', 'order' => 99],
        ],
        // §12 urutan penyedia jadwal; sumber pertama yang berhasil dipakai.
        'priority' => ['MANUAL', 'CONFIGURATION', 'CALCULATION', 'EXTERNAL_API'],
        // §13 jadwal statis dari konfigurasi institusi (dapat berbeda per musim/Ramadan).
        'static_schedule' => [
            'default' => [],
            'months' => [],
            'ramadan' => [],
        ],
        'api' => [
            'enabled' => false,
            'base_url' => '',
            'city' => 'Jakarta',
            'method' => '11',
            'timeout' => 8,
            'cache_minutes' => 720,
            'field_map' => [
                'FAJR' => 'Fajr', 'DHUHR' => 'Dhuhr', 'ASR' => 'Asr',
                'MAGHRIB' => 'Maghrib', 'ISHA' => 'Isha',
            ],
        ],
        // §14 pemisahan adhan/iqamah; jarak bawaan per shalat (menit).
        'iqamah_offset_minutes' => [
            'FAJR' => 10, 'DHUHR' => 10, 'ASR' => 10, 'MAGHRIB' => 5, 'ISHA' => 10,
        ],
    ],

    // §5 status masjid
    'mosque' => [
        'statuses' => ['ACTIVE', 'INACTIVE', 'RENOVATION', 'CLOSED'],
        // §9 kebijakan akses
        'access_policies' => [
            'PUBLIC' => ['label' => 'Umum', 'rank' => 1],
            'INSTITUTION_ONLY' => ['label' => 'Warga institusi', 'rank' => 2],
            'STUDENT_ONLY' => ['label' => 'Hanya siswa', 'rank' => 3],
            'STAFF_ONLY' => ['label' => 'Hanya pegawai', 'rank' => 4],
            'RESTRICTED' => ['label' => 'Terbatas', 'rank' => 5],
        ],
        // §6 jenis area
        'area_types' => [
            'MAIN_HALL', 'WOMEN_AREA', 'MEN_AREA', 'CLASSROOM', 'LECTURE_HALL',
            'LIBRARY', 'ABLUTION', 'COURTYARD', 'OFFICE', 'STORAGE', 'MULTIPURPOSE',
        ],
        // §8 jenis perlengkapan
        'equipment_types' => [
            'MICROPHONE', 'AMPLIFIER', 'SPEAKER', 'MIMBAR', 'LECTERN', 'PROJECTOR',
            'SCREEN', 'DIGITAL_CLOCK', 'PRAYER_DISPLAY', 'QURAN', 'BOOKS',
            'WHEELCHAIR', 'UMBRELLA', 'CLEANING', 'CARPET', 'SOUND_SYSTEM', 'AC', 'CCTV', 'OTHER',
        ],
        'equipment_conditions' => ['GOOD', 'NEEDS_REPAIR', 'DAMAGED', 'RETIRED'],
    ],

    // §22 metode presensi (mengikuti Attendance Provider institusi)
    'attendance' => [
        'methods' => ['MANUAL', 'QR', 'RFID', 'NFC', 'SMART_CARD', 'MOBILE'],
        'statuses' => ['PRESENT', 'LATE', 'ABSENT', 'EXCUSED', 'AUTHORIZED_ABSENCE', 'UNKNOWN'],
        // §25 kebijakan bawaan; dapat ditimpa per kampus/asrama/tingkat lewat tabel.
        'default_policy' => [
            'grace_period_minutes' => 5,
            'late_threshold_minutes' => 15,
            'window_before_minutes' => 20,
            'window_after_minutes' => 45,
            'require_congregation' => true,
        ],
        // §26 penerusan ke kedisiplinan: nonaktif secara bawaan.
        'forward_to_discipline' => false,
    ],

    // §32 jenis kegiatan keagamaan
    'activity' => [
        'types' => [
            'KAJIAN' => ['name' => 'Kajian', 'learning' => true],
            'CERAMAH' => ['name' => 'Ceramah', 'learning' => true],
            'TAKLIM' => ['name' => 'Taklim', 'learning' => true],
            'DZIKIR' => ['name' => 'Dzikir', 'learning' => false],
            'TAHFIDZ' => ['name' => 'Tahfidz', 'learning' => true, 'external_source' => 'tahfidz'],
            'HALAQAH' => ['name' => 'Halaqah', 'learning' => true, 'external_source' => 'tahfidz'],
            'QURAN_READING' => ['name' => 'Tilawah', 'learning' => true],
            'QURAN_MEMORIZATION' => ['name' => 'Hafalan', 'learning' => true],
            'FIQH_CLASS' => ['name' => 'Kelas Fiqh', 'learning' => true],
            'AQIDAH_CLASS' => ['name' => 'Kelas Aqidah', 'learning' => true],
            'ARABIC_CLASS' => ['name' => 'Kelas Bahasa Arab', 'learning' => true],
            'ISLAMIC_HISTORY' => ['name' => 'Sejarah Islam', 'learning' => true],
            'CHARACTER_EDUCATION' => ['name' => 'Pendidikan Karakter', 'learning' => true],
            'RAMADAN' => ['name' => 'Kegiatan Ramadan', 'learning' => false],
            'ISLAMIC_EVENT' => ['name' => 'Acara Keislaman', 'learning' => false],
        ],
        'registration_modes' => ['OPEN', 'INVITATION', 'MANDATORY', 'OPTIONAL', 'APPROVAL'],
        'target_populations' => [
            'ALL_STUDENTS', 'GRADE', 'CLASS', 'DORMITORY', 'STUDENT_GROUP',
            'TEACHER', 'STAFF', 'PARENT', 'PUBLIC', 'ALL',
        ],
    ],

    // §30, §31 khutbah
    'khutbah' => [
        'default_language' => 'id',
        'transitions' => [
            'DRAFT' => ['REVIEW', 'ARCHIVED'],
            'REVIEW' => ['APPROVED', 'DRAFT'],
            'APPROVED' => ['SCHEDULED', 'DELIVERED', 'ARCHIVED'],
            'SCHEDULED' => ['DELIVERED', 'APPROVED'],
            'DELIVERED' => ['ARCHIVED'],
            'ARCHIVED' => [],
        ],
        'categories' => [
            'AKHLAK' => ['name' => 'Akhlak'],
            'AQIDAH' => ['name' => 'Aqidah'],
            'FIQH' => ['name' => 'Fiqh'],
            'EDUCATION' => ['name' => 'Pendidikan'],
            'FAMILY' => ['name' => 'Keluarga'],
            'RAMADAN' => ['name' => 'Ramadan'],
            'YOUTH' => ['name' => 'Pemuda'],
            'LEADERSHIP' => ['name' => 'Kepemimpinan'],
            'SOCIAL' => ['name' => 'Sosial'],
            'CHARACTER' => ['name' => 'Karakter'],
        ],
    ],

    // §27 Jumat
    'friday' => [
        'default_start_time' => '12:00',
        'default_end_time' => '13:00',
    ],

    // §44-§49 Ramadan
    'ramadan' => [
        'activity_types' => [
            'TARAWIH', 'TAHAJJUD', 'TADARUS', 'IFTAR', 'SUHOOR',
            'KAJIAN', 'QURAN_PROGRAM', 'CHARITY', 'ITIKAF', 'NIGHT_ACTIVITY',
        ],
        // §48 konfigurasi rakaat configurable \u2014 bukan paksaan satu mazhab.
        'tarawih' => [
            'default_rakaat' => 20,
            'witr' => 3,
            'default_start' => '19:30',
            'config' => ['style' => 'CONFIGURABLE', 'note' => 'Sesuaikan dengan kebijakan institusi.'],
        ],
        'tahajjud' => ['default_start' => '03:30'],
        'default_suhoor_time' => '04:20',
        'default_iftar_time' => '18:05',
        'default_days' => 30,
    ],

    // §51, §52 hari raya & qurban
    'eid' => [
        'default_prayer_time' => '06:30',
        'qurban_animals' => [
            'COW' => ['name' => 'Sapi', 'share' => 7],
            'GOAT' => ['name' => 'Kambing', 'share' => 1],
            'SHEEP' => ['name' => 'Domba', 'share' => 1],
            'CAMEL' => ['name' => 'Unta', 'share' => 7],
        ],
    ],

    // §41 jenis penilaian (configurable, tanpa standar tunggal)
    'assessment' => [
        'types' => [
            'MEMORIZATION' => ['name' => 'Hafalan', 'category' => 'MEMORIZATION', 'scale' => 'SCORE', 'max_score' => 100, 'requires_supervisor' => true],
            'READING' => ['name' => 'Bacaan', 'category' => 'READING', 'scale' => 'SCORE', 'max_score' => 100],
            'KNOWLEDGE' => ['name' => 'Pengetahuan', 'category' => 'KNOWLEDGE', 'scale' => 'SCORE', 'max_score' => 100],
            'PRACTICE' => ['name' => 'Praktik', 'category' => 'PRACTICE', 'scale' => 'SCORE', 'max_score' => 100],
            'PARTICIPATION' => ['name' => 'Partisipasi', 'category' => 'PARTICIPATION', 'scale' => 'SCORE', 'max_score' => 100],
            'CHARACTER' => ['name' => 'Akhlak', 'category' => 'CHARACTER', 'scale' => 'RUBRIC', 'max_score' => 4],
        ],
        'default_grade_bands' => [
            ['min' => 90, 'label' => 'MUMTAZ'],
            ['min' => 80, 'label' => 'JAYYID_JIDDAN'],
            ['min' => 70, 'label' => 'JAYYID'],
            ['min' => 60, 'label' => 'MAQBUL'],
            ['min' => 0, 'label' => 'PERLU_BIMBINGAN'],
        ],
    ],

    // §64, §86 notifikasi
    'notifications' => [
        'types' => [
            'PRAYER_REMINDER' => ['title' => 'Pengingat Shalat', 'template' => 'Waktu :prayer telah masuk pukul :time.', 'roles' => [], 'priority' => 'NORMAL'],
            'IQAMAH_REMINDER' => ['title' => 'Pengingat Iqamah', 'template' => 'Iqamah :prayer pukul :time.', 'roles' => [], 'priority' => 'NORMAL'],
            'KAJIAN_REMINDER' => ['title' => 'Pengingat Kajian', 'template' => 'Kajian ":title" pukul :time.', 'roles' => ['STUDENT', 'TEACHER'], 'priority' => 'NORMAL'],
            'FRIDAY_REMINDER' => ['title' => 'Pengingat Jumat', 'template' => 'Shalat Jumat :date pukul :time.', 'roles' => ['STUDENT', 'TEACHER'], 'priority' => 'HIGH'],
            'DUTY_ASSIGNMENT' => ['title' => 'Penugasan Keagamaan', 'template' => 'Anda bertugas :title pada :date pukul :time.', 'roles' => ['IMAM', 'MUADZIN', 'KHATIB'], 'priority' => 'HIGH'],
            'RAMADAN_REMINDER' => ['title' => 'Pengingat Ramadan', 'template' => ':title pukul :time pada :date.', 'roles' => [], 'priority' => 'NORMAL'],
            'SCHEDULE_CHANGE' => ['title' => 'Perubahan Jadwal', 'template' => 'Jadwal :title pukul :time mengalami perubahan.', 'roles' => [], 'priority' => 'HIGH'],
            'CANCELLATION' => ['title' => 'Pembatalan', 'template' => 'Kegiatan :title pada :date dibatalkan.', 'roles' => [], 'priority' => 'HIGH'],
        ],
    ],

    // §87 impor/ekspor
    'import' => [
        'types' => [
            'PRAYER_SCHEDULE' => [
                'required' => ['prayer_type', 'adhan_time'],
                'rules' => [
                    'schedule_date' => ['type' => 'date'],
                    'adhan_time' => ['type' => 'time'],
                    'iqamah_time' => ['type' => 'time'],
                    'prayer_type' => ['type' => 'in', 'values' => ['FAJR', 'DHUHR', 'ASR', 'MAGHRIB', 'ISHA', 'JUMUAH', 'TARAWIH', 'TAHAJJUD', 'EID_FITR', 'EID_ADHA', 'OTHER']],
                ],
            ],
            'IMAM' => ['required' => ['full_name'], 'rules' => []],
            'MUADZIN' => ['required' => ['full_name'], 'rules' => []],
            'KHATIB' => ['required' => ['full_name'], 'rules' => []],
            'RELIGIOUS_ACTIVITY' => [
                'required' => ['title', 'activity_type'],
                'rules' => ['starts_on' => ['type' => 'date'], 'ends_on' => ['type' => 'date']],
            ],
            'DUTY_ROSTER' => [
                'required' => ['roster_id', 'duty_date', 'duty_role'],
                'rules' => ['duty_date' => ['type' => 'date']],
            ],
            'PARTICIPANT' => ['required' => ['activity_id'], 'rules' => []],
        ],
        'exports' => [
            'prayer_schedule' => ['table' => 'prayer_schedules', 'columns' => ['schedule_date', 'mosque_id', 'prayer_type', 'adhan_time', 'iqamah_time', 'status', 'source']],
            'attendance' => ['table' => 'prayer_attendance_records', 'columns' => ['attendance_date', 'prayer_type', 'student_id', 'status', 'method']],
            'activities' => ['table' => 'religious_activities', 'columns' => ['code', 'title', 'activity_type', 'status', 'starts_on', 'ends_on']],
        ],
    ],

    // §93 kalender keagamaan (tanggal Hijriah dari sumber terkonfigurasi)
    'calendar' => [
        'weekend_days' => ['Saturday', 'Sunday'],
        'hijri_offset_days' => -1,
        'current_hijri_year' => '1448',
        'auto_ramadan_from_program' => true,
    ],

    // §68 portal & privasi
    'portal' => [
        'parent_dashboard_enabled' => true,
        'min_respondents' => 5,
        'show_student_names_to_parent' => true,
    ],

    // §94 keamanan
    'security' => [
        'global_roles' => ['SUPER_ADMIN', 'FOUNDATION_ADMIN', 'RELIGIOUS_ADMIN', 'HR_DIRECTOR'],
        'mosque_pivot_table' => 'mosque_staff',
        'audit_blocked_keys' => ['password', 'token', 'secret', 'nik', 'bank_account', 'nomor_rekening'],
    ],

    // §785 batas laju
    'rate_limits' => [
        'module' => 180,
        'write' => 90,
        'attendance' => 300,
    ],

    // Infrastruktur internal
    'infrastructure' => [
        'audit_table' => 'religious_audit_logs',
        'event_table' => 'religious_events',
        'idempotency_table' => 'religious_idempotency_keys',
    ],

    // §100 integrasi: implementasi gateway dapat ditimpa per lingkungan
    'integrations' => [
        'user_model' => 'App\\Models\\User',
        'catering_provider' => null,
        'workflow_engine' => null,
        'discipline_enabled' => false,
    ],

    'gateways' => [
        'student' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\EloquentStudentGateway::class,
        'parent' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\EloquentParentGateway::class,
        'employee' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\HrdEmployeeGateway::class,
        'teacher' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\AcademicTeacherGateway::class,
        'boarding' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\ModuleBoardingGateway::class,
        'attendance' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\AttendanceModuleGateway::class,
        'tahfidz' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\TahfidzModuleGateway::class,
        'student_activity' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\ActivityParticipationGateway::class,
        'calendar' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\ErpCalendarGateway::class,
        'event' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\EventModuleGateway::class,
        'facility' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\FacilityModuleGateway::class,
        'asset' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\AssetModuleGateway::class,
        'inventory' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\InventoryModuleGateway::class,
        'catering' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\ConfigurableCateringGateway::class,
        'transportation' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\TransportationModuleGateway::class,
        'finance' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\FinancePostingGateway::class,
        'notification' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\DatabaseNotificationGateway::class,
        'workflow' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\WorkflowModuleGateway::class,
        'document' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\LocalDocumentGateway::class,
        'certificate' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\ActivityCertificateGateway::class,
        'discipline' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\ConfigurableDisciplineGateway::class,
        'access' => \App\Modules\SchoolOperations\Religious\Infrastructure\Gateways\DatabaseAccessControlGateway::class,
    ],
];
