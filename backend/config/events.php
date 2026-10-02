<?php

declare(strict_types=1);

/**
 * Modul Event (School Operations Fase 4).
 *
 * Seluruh kebijakan yang mungkin berbeda antar institusi diletakkan di sini —
 * jenis event, alur persetujuan, kategori anggaran, butir penutupan — sehingga
 * perilaku modul dapat disesuaikan tanpa mengubah kode.
 */
return [

    // ── Peta tabel (memudahkan penggantian nama di masa depan) ──────────────
    'tables' => [
        'events' => 'events',
        'audit' => 'event_audit_logs',
        'domain_events' => 'event_domain_events',
    ],

    /*
    |--------------------------------------------------------------------------
    | Diagnostik
    |--------------------------------------------------------------------------
    | Bila true, kegagalan operasi sampingan (audit, event bus) dilaporkan ke
    | penangan kesalahan. Jangan dimatikan di produksi: audit trail yang gagal
    | tanpa jejak adalah cacat kepatuhan.
    */
    'report_side_effect_failures' => env('EVENTS_REPORT_SIDE_EFFECT_FAILURES', true),

    /*
    |--------------------------------------------------------------------------
    | Siklus hidup (§9) & aturan perpindahan
    |--------------------------------------------------------------------------
    */
    'transitions' => [
        'DRAFT' => ['PROPOSED', 'CANCELLED', 'ARCHIVED'],
        'PROPOSED' => ['UNDER_REVIEW', 'DRAFT', 'CANCELLED'],
        'UNDER_REVIEW' => ['APPROVED', 'PROPOSED', 'DRAFT', 'CANCELLED'],
        'APPROVED' => ['PLANNING', 'REGISTRATION_OPEN', 'CANCELLED'],
        'PLANNING' => ['REGISTRATION_OPEN', 'REGISTRATION_CLOSED', 'READY', 'CANCELLED', 'APPROVED'],
        'REGISTRATION_OPEN' => ['REGISTRATION_CLOSED', 'READY', 'CANCELLED', 'PLANNING'],
        'REGISTRATION_CLOSED' => ['READY', 'REGISTRATION_OPEN', 'CANCELLED'],
        'READY' => ['ONGOING', 'CANCELLED', 'REGISTRATION_CLOSED'],
        'ONGOING' => ['COMPLETED', 'CANCELLED'],
        'COMPLETED' => ['EVALUATION', 'CLOSED'],
        'EVALUATION' => ['CLOSED', 'COMPLETED'],
        'CLOSED' => ['ARCHIVED'],
        'CANCELLED' => ['ARCHIVED', 'DRAFT'],
        'ARCHIVED' => [],
    ],

    'status_labels' => [
        'DRAFT' => 'Draf',
        'PROPOSED' => 'Diajukan',
        'UNDER_REVIEW' => 'Dalam Peninjauan',
        'APPROVED' => 'Disetujui',
        'PLANNING' => 'Perencanaan',
        'REGISTRATION_OPEN' => 'Pendaftaran Dibuka',
        'REGISTRATION_CLOSED' => 'Pendaftaran Ditutup',
        'READY' => 'Siap',
        'ONGOING' => 'Berlangsung',
        'COMPLETED' => 'Selesai',
        'EVALUATION' => 'Evaluasi',
        'CLOSED' => 'Ditutup',
        'CANCELLED' => 'Dibatalkan',
        'ARCHIVED' => 'Diarsipkan',
    ],

    /*
    |--------------------------------------------------------------------------
    | Jenis event (§6) — ditanam sebagai data acuan
    |--------------------------------------------------------------------------
    | `requires_*` menentukan kelengkapan yang wajib ada sebelum event dapat
    | disetujui atau dinyatakan siap (Rule 1, Rule 2).
    */
    'event_types' => [
        ['code' => 'ACADEMIC', 'name' => 'Akademik', 'group' => 'ACADEMIC', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true],
        ['code' => 'RELIGIOUS', 'name' => 'Keagamaan', 'group' => 'RELIGIOUS', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true],
        ['code' => 'SPORT', 'name' => 'Olahraga', 'group' => 'STUDENT', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true, 'requires_consent' => true],
        ['code' => 'ART', 'name' => 'Seni', 'group' => 'STUDENT', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true],
        ['code' => 'COMPETITION', 'name' => 'Kompetisi', 'group' => 'STUDENT', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true, 'requires_consent' => true],
        ['code' => 'CEREMONY', 'name' => 'Upacara', 'group' => 'INSTITUTIONAL', 'requires_proposal' => false, 'requires_budget' => false, 'requires_approval' => true, 'requires_committee' => true],
        ['code' => 'GRADUATION', 'name' => 'Wisuda', 'group' => 'INSTITUTIONAL', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true, 'requires_committee' => true, 'requires_consent' => true],
        ['code' => 'ORIENTATION', 'name' => 'Masa Pengenalan', 'group' => 'ACADEMIC', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true, 'requires_committee' => true, 'requires_consent' => true],
        ['code' => 'PARENT', 'name' => 'Pertemuan Orang Tua', 'group' => 'PARENT', 'requires_proposal' => false, 'requires_budget' => true, 'requires_approval' => true],
        ['code' => 'STUDENT', 'name' => 'Kegiatan Siswa', 'group' => 'STUDENT', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true],
        ['code' => 'BOARDING', 'name' => 'Kegiatan Asrama', 'group' => 'BOARDING', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true, 'requires_committee' => true],
        ['code' => 'FIELD_TRIP', 'name' => 'Kunjungan Lapangan', 'group' => 'ACADEMIC', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true, 'requires_consent' => true, 'requires_committee' => true],
        ['code' => 'STUDY_TOUR', 'name' => 'Studi Wisata', 'group' => 'ACADEMIC', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true, 'requires_consent' => true, 'requires_committee' => true],
        ['code' => 'SEMINAR', 'name' => 'Seminar', 'group' => 'ACADEMIC', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true, 'requires_committee' => true],
        ['code' => 'WORKSHOP', 'name' => 'Lokakarya', 'group' => 'ACADEMIC', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true],
        ['code' => 'TRAINING', 'name' => 'Pelatihan', 'group' => 'STAFF', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true],
        ['code' => 'EXHIBITION', 'name' => 'Pameran', 'group' => 'STUDENT', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true, 'requires_committee' => true],
        ['code' => 'FESTIVAL', 'name' => 'Festival Sekolah', 'group' => 'STUDENT', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true, 'requires_committee' => true, 'allow_public_registration' => true],
        ['code' => 'MEETING', 'name' => 'Rapat Internal', 'group' => 'INSTITUTIONAL', 'requires_proposal' => false, 'requires_budget' => false, 'requires_approval' => false],
        ['code' => 'OPEN_HOUSE', 'name' => 'Open House', 'group' => 'INSTITUTIONAL', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true, 'requires_committee' => true, 'allow_public_registration' => true],
        ['code' => 'COMMUNITY', 'name' => 'Kegiatan Masyarakat', 'group' => 'COMMUNITY', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true, 'allow_public_registration' => true],
        ['code' => 'INSTITUTIONAL', 'name' => 'Kegiatan Institusi', 'group' => 'INSTITUTIONAL', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true],
        ['code' => 'OTHER', 'name' => 'Lainnya', 'group' => 'OTHER', 'requires_proposal' => true, 'requires_budget' => true, 'requires_approval' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | Klasifikasi (§7)
    |--------------------------------------------------------------------------
    */
    'categories' => [
        ['code' => 'INTERNAL', 'name' => 'Internal', 'is_public' => false, 'requires_invitation' => false],
        ['code' => 'EXTERNAL', 'name' => 'Eksternal', 'is_public' => false, 'requires_invitation' => false],
        ['code' => 'PUBLIC', 'name' => 'Publik', 'is_public' => true, 'requires_invitation' => false],
        ['code' => 'INVITATION_ONLY', 'name' => 'Undangan', 'is_public' => false, 'requires_invitation' => true],
        ['code' => 'STUDENT_ONLY', 'name' => 'Hanya Siswa', 'is_public' => false, 'requires_invitation' => false],
        ['code' => 'STAFF_ONLY', 'name' => 'Hanya Pegawai', 'is_public' => false, 'requires_invitation' => false],
        ['code' => 'PARENT_ONLY', 'name' => 'Hanya Orang Tua', 'is_public' => false, 'requires_invitation' => false],
        ['code' => 'MIXED', 'name' => 'Campuran', 'is_public' => false, 'requires_invitation' => false],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tujuan event (§12)
    |--------------------------------------------------------------------------
    */
    'objectives' => [
        'ACADEMIC', 'CHARACTER', 'RELIGIOUS', 'LEADERSHIP', 'COMPETITION',
        'COMMUNITY', 'PARENT_ENGAGEMENT', 'INSTITUTIONAL', 'FUNDRAISING',
        'STUDENT_DEVELOPMENT',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sasaran peserta (§13) — audiens dinamis, bukan daftar kaku
    |--------------------------------------------------------------------------
    */
    'target_types' => [
        'ALL_STUDENTS' => 'Semua Siswa',
        'GRADE' => 'Tingkat',
        'CLASS' => 'Kelas',
        'PROGRAM' => 'Program',
        'DORMITORY' => 'Asrama',
        'STUDENT_GROUP' => 'Kelompok Siswa',
        'TEACHERS' => 'Guru',
        'EMPLOYEES' => 'Pegawai',
        'PARENTS' => 'Orang Tua',
        'ALUMNI' => 'Alumni',
        'EXTERNAL_GUESTS' => 'Tamu Eksternal',
        'PUBLIC' => 'Umum',
        'CUSTOM' => 'Khusus',
    ],

    /*
    |--------------------------------------------------------------------------
    | Peserta (§27) & badge (§68)
    |--------------------------------------------------------------------------
    */
    'participant_types' => [
        'STUDENT', 'PARENT', 'TEACHER', 'EMPLOYEE', 'GUEST', 'ALUMNI', 'VOLUNTEER', 'EXTERNAL',
    ],

    'badge_types' => ['STUDENT', 'STAFF', 'GUEST', 'SPEAKER', 'COMMITTEE', 'VIP', 'VOLUNTEER'],

    /*
    |--------------------------------------------------------------------------
    | Pendaftaran (§28–§33)
    |--------------------------------------------------------------------------
    */
    'registration' => [
        'modes' => ['OPEN', 'INVITATION', 'AUTO_ENROLL', 'MANUAL', 'NOMINATION', 'SELECTION'],
        'field_types' => ['TEXT', 'NUMBER', 'SELECT', 'MULTISELECT', 'RADIO', 'CHECKBOX', 'DATE', 'FILE', 'SIGNATURE', 'EMAIL', 'PHONE', 'TEXTAREA'],
        'system_fields' => [
            ['field_code' => 'full_name', 'label' => 'Nama Lengkap', 'field_type' => 'TEXT', 'is_required' => true, 'is_system' => true],
            ['field_code' => 'phone', 'label' => 'Nomor Telepon', 'field_type' => 'PHONE', 'is_required' => true, 'is_system' => true],
            ['field_code' => 'email', 'label' => 'Surel', 'field_type' => 'EMAIL', 'is_required' => false, 'is_system' => true],
            ['field_code' => 'emergency_contact', 'label' => 'Kontak Darurat', 'field_type' => 'TEXT', 'is_required' => false, 'is_system' => true],
            ['field_code' => 'dietary_requirement', 'label' => 'Kebutuhan Makanan', 'field_type' => 'TEXT', 'is_required' => false, 'is_system' => true],
            ['field_code' => 'needs_transportation', 'label' => 'Butuh Transportasi', 'field_type' => 'CHECKBOX', 'is_required' => false, 'is_system' => true],
            ['field_code' => 'needs_accommodation', 'label' => 'Butuh Akomodasi', 'field_type' => 'CHECKBOX', 'is_required' => false, 'is_system' => true],
            ['field_code' => 'tshirt_size', 'label' => 'Ukuran Kaos', 'field_type' => 'SELECT', 'is_required' => false, 'is_system' => true,
             'options' => ['S', 'M', 'L', 'XL', 'XXL']],
            ['field_code' => 'consent', 'label' => 'Persetujuan', 'field_type' => 'CHECKBOX', 'is_required' => false, 'is_system' => true],
        ],
        // Rule 4: penuh → daftar tunggu bila diaktifkan, selain itu ditolak.
        'allow_waitlist_when_full' => true,
        'promote_waitlist_automatically' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Persetujuan (§34) — berversi
    |--------------------------------------------------------------------------
    */
    'consent_types' => [
        'PARENT' => 'Persetujuan Orang Tua',
        'STUDENT' => 'Persetujuan Siswa',
        'MEDICAL' => 'Persetujuan Medis',
        'PHOTO' => 'Persetujuan Foto',
        'TRANSPORTATION' => 'Persetujuan Transportasi',
        'TRAVEL' => 'Persetujuan Perjalanan',
    ],

    /*
    |--------------------------------------------------------------------------
    | Kepanitiaan (§41) & relawan (§40)
    |--------------------------------------------------------------------------
    */
    'committee_positions' => [
        'CHAIR' => 'Ketua', 'VICE_CHAIR' => 'Wakil Ketua', 'SECRETARY' => 'Sekretaris',
        'TREASURER' => 'Bendahara', 'PROGRAM' => 'Koordinator Acara', 'LOGISTICS' => 'Logistik',
        'CONSUMPTION' => 'Konsumsi', 'TRANSPORTATION' => 'Transportasi', 'SECURITY' => 'Keamanan',
        'DOCUMENTATION' => 'Dokumentasi', 'PUBLIC_RELATIONS' => 'Humas',
        'REGISTRATION' => 'Pendaftaran', 'MEMBER' => 'Anggota',
    ],

    'volunteer_assignments' => [
        'REGISTRATION', 'SECURITY', 'LOGISTICS', 'DOCUMENTATION', 'HOSPITALITY',
        'TECHNICAL', 'CLEANING', 'TRANSPORTATION', 'CATERING',
    ],

    /*
    |--------------------------------------------------------------------------
    | Checklist bawaan (§44) & penutupan (§108)
    |--------------------------------------------------------------------------
    */
    'default_checklists' => [
        'VENUE' => ['Venue siap & bersih', 'Tata letak kursi', 'Panggung & dekorasi', 'Papan nama ruangan'],
        'AUDIO_VISUAL' => ['Sound system diuji', 'Mikrofon cadangan', 'Proyektor & layar', 'Laptop presenter'],
        'REGISTRATION' => ['Meja pendaftaran', 'Daftar hadir', 'Badge peserta', 'Alat tulis'],
        'CATERING' => ['Konfirmasi jumlah porsi', 'Menu & waktu penyajian', 'Perlengkapan makan'],
        'TRANSPORT' => ['Kendaraan dikonfirmasi', 'Daftar penumpang', 'Titik jemput'],
        'SAFETY' => ['Petugas keamanan', 'Jalur evakuasi', 'P3K & petugas medis'],
        'DOCUMENTATION' => ['Fotografer', 'Kamera & baterai', 'Formulir dokumentasi'],
        'CERTIFICATE' => ['Data penerima', 'Template sertifikat', 'Verifikasi eligibility'],
    ],

    'closing_checklist' => [
        ['code' => 'attendance_finalized', 'title' => 'Kehadiran difinalkan', 'is_mandatory' => true],
        ['code' => 'participants_finalized', 'title' => 'Daftar peserta difinalkan', 'is_mandatory' => true],
        ['code' => 'finance_reconciled', 'title' => 'Keuangan direkonsiliasi', 'is_mandatory' => true],
        ['code' => 'assets_returned', 'title' => 'Aset dikembalikan', 'is_mandatory' => true],
        ['code' => 'inventory_reconciled', 'title' => 'Inventaris direkonsiliasi', 'is_mandatory' => true],
        ['code' => 'catering_reconciled', 'title' => 'Katering direkonsiliasi', 'is_mandatory' => true],
        ['code' => 'transportation_closed', 'title' => 'Transportasi ditutup', 'is_mandatory' => false],
        ['code' => 'incidents_reviewed', 'title' => 'Insiden ditinjau', 'is_mandatory' => true],
        ['code' => 'documents_archived', 'title' => 'Dokumen diarsipkan', 'is_mandatory' => false],
        ['code' => 'media_uploaded', 'title' => 'Media diunggah', 'is_mandatory' => false],
        ['code' => 'certificates_processed', 'title' => 'Sertifikat diproses', 'is_mandatory' => false],
        ['code' => 'survey_completed', 'title' => 'Survei selesai', 'is_mandatory' => false],
        ['code' => 'report_generated', 'title' => 'Laporan dibuat', 'is_mandatory' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | Anggaran (§51) & realisasi (§53)
    |--------------------------------------------------------------------------
    */
    'budget_categories' => [
        'VENUE' => 'Venue', 'CATERING' => 'Konsumsi', 'TRANSPORTATION' => 'Transportasi',
        'EQUIPMENT' => 'Peralatan', 'DECORATION' => 'Dekorasi', 'HONORARIUM' => 'Honorarium',
        'ACCOMMODATION' => 'Akomodasi', 'PRIZE' => 'Hadiah', 'DOCUMENTATION' => 'Dokumentasi',
        'PRINTING' => 'Percetakan', 'SECURITY' => 'Keamanan', 'MEDICAL' => 'Medis',
        'PUBLICATION' => 'Publikasi', 'OTHER' => 'Lain-lain',
    ],

    /*
    |--------------------------------------------------------------------------
    | Keamanan, medis, risiko, insiden (§57–§61)
    |--------------------------------------------------------------------------
    */
    'venue_requirements' => [
        'CAPACITY', 'SEATING', 'STAGE', 'SOUND_SYSTEM', 'PROJECTOR', 'AC',
        'PARKING', 'TOILET', 'ACCESSIBILITY', 'SECURITY', 'ELECTRICITY', 'INTERNET',
    ],

    'risk_categories' => ['OPERATIONAL', 'SAFETY', 'SECURITY', 'FINANCIAL', 'WEATHER', 'REPUTATIONAL', 'HEALTH', 'TRANSPORTATION'],

    'incident_types' => [
        'MEDICAL' => 'Medis', 'SAFETY' => 'Keselamatan', 'SECURITY' => 'Keamanan',
        'LOST_CHILD' => 'Anak Hilang', 'MISSING_ITEM' => 'Barang Hilang',
        'EQUIPMENT' => 'Peralatan', 'TRANSPORTATION' => 'Transportasi',
        'CROWD' => 'Kerumunan', 'WEATHER' => 'Cuaca', 'OTHER' => 'Lainnya',
    ],

    'contingency_types' => ['VENUE', 'SCHEDULE', 'VEHICLE', 'SPEAKER', 'CATERING', 'POWER', 'WEATHER'],

    /*
    |--------------------------------------------------------------------------
    | Komunikasi (§64) & undangan (§26)
    |--------------------------------------------------------------------------
    */
    'notification_events' => [
        'EventCreated', 'EventApproved', 'RegistrationOpened', 'RegistrationClosed',
        'RegistrationConfirmed', 'EventScheduleChanged', 'EventVenueChanged',
        'EventCancelled', 'EventReminder', 'EventStarting', 'ParticipantCheckedIn',
        'ParticipantNoShow', 'SessionStarting', 'EventIncidentCreated',
        'EventIncidentResolved', 'EventCompleted', 'EventClosed', 'CertificateAvailable',
    ],

    'channels' => ['EMAIL', 'WHATSAPP', 'SMS', 'IN_APP', 'PRINTED'],

    /*
    |--------------------------------------------------------------------------
    | Sertifikat (§75) — aturan kelayakan configurable
    |--------------------------------------------------------------------------
    | Setiap aturan menyatakan syarat minimum agar sertifikat dapat diterbitkan.
    */
    'certificate_eligibility' => [
        'PARTICIPANT' => ['require_registered' => true, 'min_attendance_percent' => 75, 'require_check_in' => true],
        'SPEAKER' => ['require_registered' => false, 'min_attendance_percent' => 0, 'require_check_in' => false],
        'VOLUNTEER' => ['require_registered' => true, 'min_attendance_percent' => 0, 'require_check_in' => true, 'require_hours' => 0],
        'COMMITTEE' => ['require_registered' => false, 'min_attendance_percent' => 0, 'require_check_in' => false],
        'ACHIEVEMENT' => ['require_registered' => true, 'min_attendance_percent' => 0, 'require_check_in' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | KPI bawaan (§77) — configurable, bukan satu skor kaku
    |--------------------------------------------------------------------------
    */
    'default_kpis' => [
        ['kpi_code' => 'REGISTRATION', 'name' => 'Jumlah Pendaftar', 'unit' => 'orang', 'direction' => 'HIGHER_BETTER'],
        ['kpi_code' => 'ATTENDANCE', 'name' => 'Tingkat Kehadiran', 'unit' => '%', 'direction' => 'HIGHER_BETTER'],
        ['kpi_code' => 'NO_SHOW', 'name' => 'Tidak Hadir', 'unit' => '%', 'direction' => 'LOWER_BETTER'],
        ['kpi_code' => 'BUDGET_VARIANCE', 'name' => 'Selisih Anggaran', 'unit' => '%', 'direction' => 'LOWER_BETTER'],
        ['kpi_code' => 'SATISFACTION', 'name' => 'Kepuasan Peserta', 'unit' => 'skor', 'direction' => 'HIGHER_BETTER'],
        ['kpi_code' => 'SESSION_ATTENDANCE', 'name' => 'Kehadiran Sesi', 'unit' => '%', 'direction' => 'HIGHER_BETTER'],
        ['kpi_code' => 'INCIDENT_COUNT', 'name' => 'Jumlah Insiden', 'unit' => 'kejadian', 'direction' => 'LOWER_BETTER'],
        ['kpi_code' => 'TASK_COMPLETION', 'name' => 'Penyelesaian Tugas', 'unit' => '%', 'direction' => 'HIGHER_BETTER'],
        ['kpi_code' => 'CATERING_USAGE', 'name' => 'Pemakaian Katering', 'unit' => 'porsi', 'direction' => 'LOWER_BETTER'],
        ['kpi_code' => 'TRANSPORT_UTILIZATION', 'name' => 'Pemanfaatan Transportasi', 'unit' => '%', 'direction' => 'HIGHER_BETTER'],
    ],

    /*
    |--------------------------------------------------------------------------
    | QR & token (§37, §69)
    |--------------------------------------------------------------------------
    */
    'qr' => [
        'token_ttl_minutes' => env('EVENTS_QR_TTL', 720),
        'max_uses' => 1,
        'allow_expired_check_in_with_override' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Aturan bisnis (Rule 1–15)
    |--------------------------------------------------------------------------
    */
    'rules' => [
        // Rule 1: event tanpa proposal bila jenisnya mewajibkan.
        'enforce_proposal_requirement' => true,
        // Rule 2: READY wajib punya lokasi/venue sah.
        'enforce_venue_for_ready' => true,
        // Rule 3: kapasitas wajib ada bila pendaftaran dibuka.
        'enforce_capacity_when_registration' => true,
        // Rule 4: pendaftaran tidak melampaui kapasitas kecuali daftar tunggu aktif.
        'enforce_capacity_limit' => true,
        // Rule 5 & 6: venue dan sumber daya tidak boleh tumpang tindih.
        'enforce_venue_conflict' => true,
        'enforce_resource_conflict' => true,
        // Rule 7 & 8: pembicara/guru tidak boleh jadwal bertumpang.
        'enforce_speaker_conflict' => true,
        'enforce_staff_conflict' => true,
        // Rule 9: permintaan katering/transportasi harus berasal dari kebutuhan event.
        'enforce_resource_request_lineage' => true,
        // Rule 10: event selesai tidak boleh diubah destruktif.
        'protect_completed_events' => true,
        // Rule 11: penutupan memerlukan rekonsiliasi.
        'enforce_closing_reconciliation' => true,
        // Rule 12: insiden kritis harus selesai/di-escalate sebelum ditutup.
        'enforce_critical_incident_before_close' => true,
        // Rule 13: sertifikat hanya dari aturan kelayakan.
        'enforce_certificate_eligibility' => true,
        // Rule 14: wali hanya melihat event anaknya.
        'enforce_parent_isolation' => true,
        // Rule 15: event publik hanya menampilkan data publik.
        'enforce_public_projection' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Penyedia integrasi
    |--------------------------------------------------------------------------
    | Ganti kelas di sini untuk menyesuaikan integrasi tanpa menyentuh kode
    | modul. Seluruh implementasi wajib menyatakan `available()` dengan jujur.
    */
    'providers' => [
        'student' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\ActivityStudentProvider::class,
        'parent' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\ActivityParentProvider::class,
        'employee' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\HrdEmployeeProvider::class,
        'teacher' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\ActivityTeacherProvider::class,
        'facility' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\ActivityFacilityProvider::class,
        'asset' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\AssetAssetProvider::class,
        'inventory' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\NullInventoryProvider::class,
        'finance' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\NullFinanceProvider::class,
        'transportation' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\ActivityTransportationProvider::class,
        'catering' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\NullCateringProvider::class,
        'attendance' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\ActivityAttendanceProvider::class,
        'boarding' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\ActivityBoardingProvider::class,
        'religious' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\ReligiousLifeProvider::class,
        'academic' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\ActivityAcademicProvider::class,
        'lms' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\ActivityLmsProvider::class,
        'student_activity' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\AssetStudentActivityProvider::class,
        'notification' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\ActivityNotificationProvider::class,
        'document' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\ActivityDocumentProvider::class,
        'calendar' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\ActivityCalendarProvider::class,
        'workflow' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\NullWorkflowProvider::class,
        'certificate' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\ActivityCertificateProvider::class,
        'survey' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\NullSurveyProvider::class,
        'vendor' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\NullVendorProvider::class,
        'procurement' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\NullProcurementProvider::class,
        'clinic' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\NullClinicProvider::class,
        'weather' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\NullWeatherProvider::class,
        'fundraising' => \App\Modules\SchoolOperations\Events\Infrastructure\Providers\NullFundraisingProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Peran & izin
    |--------------------------------------------------------------------------
    */
    'roles' => [
        'management' => ['EVENT_ADMIN', 'EVENT_MANAGER', 'EVENT_COORDINATOR', 'EVENT_APPROVER'],
        'committee' => [
            'EVENT_COMMITTEE', 'EVENT_SECRETARY', 'EVENT_TREASURER', 'EVENT_LOGISTICS',
            'EVENT_PROGRAM_COORDINATOR', 'EVENT_REGISTRATION', 'EVENT_DOCUMENTATION',
            'EVENT_PUBLIC_RELATIONS', 'EVENT_SECURITY', 'EVENT_VOLUNTEER_COORDINATOR',
        ],
        'participant' => ['GUEST', 'EXTERNAL_PARTICIPANT'],
        'supporting' => [
            'FACILITY_MANAGER', 'CATERING_MANAGER', 'TRANSPORTATION_MANAGER',
            'FINANCE', 'INVENTORY', 'SECURITY', 'MEDICAL',
        ],
    ],

    'permissions' => [
        'event.view', 'event.create', 'event.update', 'event.delete',
        'event.submit', 'event.approve', 'event.cancel', 'event.close', 'event.archive',
        'event.proposal.view', 'event.proposal.manage', 'event.proposal.approve',
        'event.plan.view', 'event.plan.manage',
        'event.venue.view', 'event.venue.request', 'event.venue.approve',
        'event.program.view', 'event.program.manage',
        'event.session.view', 'event.session.manage',
        'event.committee.view', 'event.committee.manage',
        'event.task.view', 'event.task.manage',
        'event.checklist.view', 'event.checklist.manage',
        'event.participant.view', 'event.participant.manage',
        'event.registration.view', 'event.registration.manage', 'event.registration.approve',
        'event.consent.view', 'event.consent.manage',
        'event.attendance.view', 'event.attendance.record', 'event.attendance.adjust',
        'event.checkin.perform', 'event.checkin.override',
        'event.resource.view', 'event.resource.request', 'event.resource.approve', 'event.resource.return',
        'event.catering.request', 'event.transportation.request',
        'event.budget.view', 'event.budget.manage', 'event.budget.approve',
        'event.cost.view', 'event.cost.record',
        'event.sponsor.view', 'event.sponsor.manage',
        'event.vendor.view', 'event.vendor.manage',
        'event.security.view', 'event.security.manage',
        'event.risk.view', 'event.risk.manage',
        'event.incident.view', 'event.incident.report', 'event.incident.manage',
        'event.document.view', 'event.document.manage',
        'event.media.view', 'event.media.manage',
        'event.certificate.view', 'event.certificate.issue',
        'event.evaluation.view', 'event.evaluation.submit',
        'event.report.view', 'event.report.generate',
        'event.change.view', 'event.change.request', 'event.change.approve',
        'event.closing.view', 'event.closing.perform',
        'event.dashboard.view', 'event.analytics.view',
        'event.control_center.view', 'event.mobile.use',
        'event.import', 'event.export', 'event.audit.view',
    ],
];
