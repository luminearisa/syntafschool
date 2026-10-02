<?php

namespace App\Modules\Hrd\Database\Seeders;

use App\Modules\Hrd\Application\Services\ReferenceService;
use App\Modules\Hrd\Models\HrCertification;
use App\Modules\Hrd\Models\HrContractType;
use App\Modules\Hrd\Models\HrDocumentType;
use App\Modules\Hrd\Models\HrJobFamily;
use App\Modules\Hrd\Models\HrJobGrade;
use App\Modules\Hrd\Models\HrJobLevel;
use App\Modules\Hrd\Models\HrLeaveType;
use App\Modules\Hrd\Models\HrOffboardingTemplate;
use App\Modules\Hrd\Models\HrOffboardingTemplateTask;
use App\Modules\Hrd\Models\HrOnboardingTemplate;
use App\Modules\Hrd\Models\HrOnboardingTemplateTask;
use App\Modules\Hrd\Models\HrRequestType;
use App\Modules\Hrd\Models\HrSkill;
use App\Modules\Hrd\Models\HrTransactionType;
use App\Modules\Hrd\Models\HrValidationRule;
use Illuminate\Database\Seeder;

/**
 * Master bawaan Modul HRD (§88 deliverable 33).
 *
 * Semua yang ada di sini adalah **nilai bawaan yang dapat diubah sekolah**:
 * jenis organisasi, worker type, status kepegawaian, jenis dokumen, jenis
 * transaksi, aturan validasi, dan template onboarding/offboarding. Tidak ada satu
 * pun yang dipaku di kode, jadi sekolah dapat menyesuaikannya tanpa deploy.
 */
class HrdMasterSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedReferences();
        $this->seedJobCatalog();
        $this->seedTransactionTypes();
        $this->seedRequestTypes();
        $this->seedContractTypes();
        $this->seedDocumentTypes();
        $this->seedSkillsAndCertifications();
        $this->seedLeaveTypes();
        $this->seedValidationRules();
        $this->seedLifecycleTemplates();
    }

    private function seedReferences(): void
    {
        $references = app(ReferenceService::class);

        $groups = [
            'organization_type' => [
                ['Foundation', 'Yayasan'], ['Company', 'Badan Usaha'], ['School', 'Sekolah'], ['Campus', 'Kampus'],
                ['Branch', 'Cabang'], ['Division', 'Divisi'], ['Department', 'Departemen'], ['Unit', 'Unit'],
                ['Section', 'Seksi'], ['Team', 'Tim'], ['Cost Center', 'Pusat Biaya'], ['Other', 'Lainnya'],
            ],
            'worker_type' => [
                ['Permanent', 'Pegawai Tetap'], ['Contract', 'Kontrak'], ['Probation', 'Percobaan'],
                ['Part Time', 'Paruh Waktu'], ['Intern', 'Magang'], ['Volunteer', 'Relawan'],
                ['Freelancer', 'Pekerja Lepas'], ['Consultant', 'Konsultan'], ['Outsourced', 'Alih Daya'],
                ['Temporary', 'Sementara'], ['Guest Worker', 'Pekerja Tamu'], ['Teacher', 'Guru'],
                ['Lecturer', 'Dosen'], ['Staff', 'Staf'],
            ],
            'employment_type' => [
                ['Permanent', 'Tetap'], ['Fixed Term', 'Berjangka'], ['Temporary', 'Sementara'],
                ['Part Time', 'Paruh Waktu'], ['Internship', 'Magang'], ['Project Based', 'Berbasis Proyek'],
                ['Daily Worker', 'Harian'], ['Other', 'Lainnya'],
            ],
            'employee_status' => [
                ['DRAFT', 'Draf'], ['APPLICANT', 'Pelamar'], ['PREBOARDING', 'Pra-Kerja'],
                ['ACTIVE', 'Aktif'], ['PROBATION', 'Percobaan'], ['PERMANENT', 'Diangkat Tetap'],
                ['LEAVE', 'Cuti'], ['SUSPENDED', 'Ditangguhkan'], ['INACTIVE', 'Tidak Aktif'],
                ['RESIGNED', 'Mengundurkan Diri'], ['TERMINATED', 'Diberhentikan'], ['RETIRED', 'Pensiun'],
                ['DECEASED', 'Meninggal'], ['CONTRACT_ENDED', 'Kontrak Berakhir'],
                ['PROBATION_FAILED', 'Tidak Lulus Percobaan'], ['ARCHIVED', 'Diarsipkan'],
            ],
            'exit_reason' => [
                ['RESIGNATION', 'Mengundurkan Diri'], ['TERMINATION', 'Pemberhentian'],
                ['CONTRACT_EXPIRED', 'Kontrak Berakhir'], ['RETIREMENT', 'Pensiun'],
                ['PERFORMANCE', 'Kinerja'], ['DISCIPLINARY', 'Kedisiplinan'],
                ['RESTRUCTURE', 'Penataan Organisasi'], ['DEATH', 'Meninggal'], ['OTHER', 'Lainnya'],
            ],
            'relationship_type' => [
                ['spouse', 'Suami/Istri'], ['child', 'Anak'], ['parent', 'Orang Tua'],
                ['sibling', 'Saudara'], ['guardian', 'Wali'], ['other', 'Lainnya'],
            ],
            'education_level' => [
                ['ELEMENTARY', 'SD'], ['JUNIOR_HIGH', 'SMP'], ['SENIOR_HIGH', 'SMA/SMK'],
                ['DIPLOMA', 'Diploma'], ['BACHELOR', 'Sarjana'], ['MASTER', 'Magister'],
                ['DOCTORATE', 'Doktor'], ['PROFESSIONAL', 'Profesi'], ['OTHER', 'Lainnya'],
            ],
            'skill_proficiency' => [
                ['BEGINNER', 'Pemula'], ['INTERMEDIATE', 'Menengah'], ['ADVANCED', 'Mahir'], ['EXPERT', 'Ahli'],
            ],
            'position_type' => [
                ['permanent', 'Tetap'], ['contract', 'Kontrak'], ['temporary', 'Sementara'],
                ['acting', 'Pejabat Sementara'], ['project', 'Proyek'], ['honorary', 'Honorer'],
                ['volunteer', 'Relawan'],
            ],
            'assignment_type' => [
                ['primary', 'Penugasan Utama'], ['secondary', 'Penugasan Tambahan'],
                ['temporary', 'Penugasan Sementara'], ['acting', 'Pejabat Sementara'], ['project', 'Penugasan Proyek'],
            ],
        ];

        foreach ($groups as $group => $items) {
            foreach ($items as $index => [$code, $label]) {
                $references->upsertItem($group, $code, [
                    'label' => $label,
                    'sort_order' => $index,
                    'is_active' => true,
                    'is_default' => $index === 0 && in_array($group, ['worker_type', 'employment_type', 'relationship_type', 'skill_proficiency'], true),
                ]);
            }
        }

        $references->forget();
    }

    private function seedJobCatalog(): void
    {
        foreach ([
            'ACADEMIC' => 'Akademik', 'ADMIN' => 'Administrasi', 'FINANCE' => 'Keuangan', 'HR' => 'SDM',
            'IT' => 'Teknologi Informasi', 'STUDENT_AFFAIRS' => 'Kesiswaan', 'BOARDING' => 'Asrama',
            'HEALTH' => 'Kesehatan', 'LIBRARY' => 'Perpustakaan', 'SECURITY' => 'Keamanan',
            'FACILITY' => 'Sarana Prasarana', 'OPERATIONS' => 'Operasional', 'MANAGEMENT' => 'Manajemen',
        ] as $code => $name) {
            HrJobFamily::query()->firstOrCreate(['code' => $code], ['name' => $name, 'status' => 'active']);
        }

        foreach ([
            ['JUNIOR', 'Junior', 1], ['MIDDLE', 'Menengah', 2], ['SENIOR', 'Senior', 3],
            ['SUPERVISOR', 'Supervisor', 4], ['MANAGER', 'Manajer', 5], ['DIRECTOR', 'Direktur', 6],
            ['EXECUTIVE', 'Eksekutif', 7],
        ] as [$code, $name, $rank]) {
            HrJobLevel::query()->firstOrCreate(['code' => $code], ['name' => $name, 'rank' => $rank, 'status' => 'active']);
        }

        foreach ([
            ['G1', 'Golongan 1', 'JUNIOR', 1], ['G2', 'Golongan 2', 'JUNIOR', 2],
            ['G3', 'Golongan 3', 'MIDDLE', 3], ['G4', 'Golongan 4', 'SENIOR', 4],
            ['G5', 'Golongan 5', 'SUPERVISOR', 5], ['G6', 'Golongan 6', 'MANAGER', 6],
            ['G7', 'Golongan 7', 'DIRECTOR', 7],
        ] as [$code, $name, $level, $rank]) {
            HrJobGrade::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'level_code' => $level,
                'rank' => $rank,
                // Hanya rujukan struktur; nominal kompensasi milik modul Payroll.
                'salary_band_reference' => $code.'-BAND',
                'status' => 'active',
            ]);
        }
    }

    private function seedTransactionTypes(): void
    {
        $types = [
            ['HIRE', 'Pengangkatan Pegawai', 'hire', 'hire', false, 'HRD_HIRE_APPROVAL'],
            ['REHIRE', 'Bekerja Kembali', 'hire', 'hire', true, 'HRD_HIRE_APPROVAL'],
            ['TRANSFER', 'Transfer Antar Unit', 'assignment', 'assignment_change', true, 'HRD_TRANSACTION_APPROVAL'],
            ['MUTATION', 'Mutasi Internal', 'assignment', 'assignment_change', true, 'HRD_TRANSACTION_APPROVAL'],
            ['PROMOTION', 'Promosi', 'assignment', 'assignment_change', true, 'HRD_PROMOTION_APPROVAL'],
            ['DEMOTION', 'Demosi', 'assignment', 'assignment_change', true, 'HRD_PROMOTION_APPROVAL'],
            ['POSITION_CHANGE', 'Perubahan Posisi', 'assignment', 'assignment_change', true, 'HRD_TRANSACTION_APPROVAL'],
            ['DEPARTMENT_CHANGE', 'Perubahan Departemen', 'assignment', 'assignment_change', true, 'HRD_TRANSACTION_APPROVAL'],
            ['LOCATION_CHANGE', 'Perubahan Lokasi Kerja', 'assignment', 'assignment_change', true, 'HRD_TRANSACTION_APPROVAL'],
            ['ASSIGNMENT_CHANGE', 'Perubahan Penugasan', 'assignment', 'assignment_change', true, 'HRD_TRANSACTION_APPROVAL'],
            ['MANAGER_CHANGE', 'Perubahan Atasan', 'assignment', 'manager_change', true, 'HRD_TRANSACTION_APPROVAL'],
            ['STATUS_CHANGE', 'Perubahan Status', 'status', 'status_change', true, 'HRD_TRANSACTION_APPROVAL'],
            ['LEAVE', 'Cuti', 'status', 'status_change', true, 'HRD_TRANSACTION_APPROVAL'],
            ['RETURN_FROM_LEAVE', 'Kembali dari Cuti', 'status', 'status_change', true, 'HRD_TRANSACTION_APPROVAL'],
            ['SUSPENSION', 'Penangguhan', 'status', 'status_change', true, 'HRD_TRANSACTION_APPROVAL'],
            ['CONFIRMATION', 'Pengangkatan Tetap', 'status', 'status_change', true, 'HRD_PROBATION_APPROVAL'],
            ['PROBATION_EXTENSION', 'Perpanjangan Percobaan', 'status', 'status_change', true, 'HRD_PROBATION_APPROVAL'],
            ['PROBATION_FAILED', 'Tidak Lulus Percobaan', 'status', 'status_change', true, 'HRD_PROBATION_APPROVAL'],
            ['CONTRACT_EXTENSION', 'Perpanjangan Kontrak', 'contract', 'contract_change', true, 'HRD_CONTRACT_APPROVAL'],
            ['CONTRACT_RENEWAL', 'Pembaruan Kontrak', 'contract', 'contract_change', true, 'HRD_CONTRACT_APPROVAL'],
            ['CONTRACT_TERMINATION', 'Pengakhiran Kontrak', 'contract', 'contract_change', true, 'HRD_CONTRACT_APPROVAL'],
            ['RESIGNATION', 'Pengunduran Diri', 'offboarding', 'separation', true, 'HRD_OFFBOARDING_APPROVAL'],
            ['TERMINATION', 'Pemberhentian', 'offboarding', 'separation', true, 'HRD_OFFBOARDING_APPROVAL'],
            ['RETIREMENT', 'Pensiun', 'offboarding', 'separation', true, 'HRD_OFFBOARDING_APPROVAL'],
            ['CONTRACT_EXPIRED', 'Kontrak Berakhir', 'offboarding', 'separation', false, null],
        ];

        foreach ($types as $index => [$code, $name, $category, $handler, $approval, $workflow]) {
            HrTransactionType::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'category' => $category,
                'effect_handler' => $handler,
                'requires_approval' => $approval,
                'workflow_definition_code' => $workflow,
                'requires_effective_date' => true,
                'requires_reason' => in_array($code, ['TRANSFER', 'PROMOTION', 'DEMOTION', 'MUTATION', 'TERMINATION', 'RESIGNATION'], true),
                'allowed_from_states' => in_array($code, ['HIRE', 'REHIRE'], true)
                    ? ['draft', 'inactive', 'archived', 'offboarding']
                    : null,
                'sort_order' => $index,
                'status' => 'active',
            ]);
        }
    }

    private function seedRequestTypes(): void
    {
        $types = [
            ['DATA_CHANGE_PERSONAL', 'Perubahan Data Pribadi', 'data_change', ['full_name', 'nik', 'gender', 'birth_date', 'marital_status', 'religion'], true, 72],
            ['DATA_CHANGE_CONTACT', 'Perubahan Kontak', 'data_change', ['phone', 'mobile', 'personal_email', 'address', 'city', 'province', 'postal_code'], true, 48],
            ['DATA_CHANGE_BANK', 'Perubahan Rekening Bank', 'data_change', [], true, 72],
            ['LETTER_EMPLOYMENT', 'Surat Keterangan Kerja', 'letter', [], true, 48],
            ['LETTER_SALARY', 'Surat Keterangan Penghasilan', 'letter', [], true, 72],
            ['DOCUMENT_REQUEST', 'Permintaan Dokumen', 'document', [], false, 48],
            ['EMPLOYMENT_VERIFICATION', 'Verifikasi Kepegawaian', 'verification', [], true, 48],
            ['TRANSFER_REQUEST', 'Permohonan Transfer', 'assignment_request', [], true, 120],
            ['CERTIFICATE_REQUEST', 'Permintaan Sertifikat', 'document', [], true, 120],
            ['OTHER', 'Permintaan Lain', 'other', [], true, 72],
        ];

        foreach ($types as $index => [$code, $name, $category, $fields, $approval, $sla]) {
            HrRequestType::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'category' => $category,
                'requires_approval' => $approval,
                'workflow_definition_code' => $approval ? 'HRD_REQUEST_APPROVAL' : null,
                'editable_fields' => $fields === [] ? null : $fields,
                'requires_attachment' => in_array($code, ['DATA_CHANGE_BANK', 'TRANSFER_REQUEST'], true),
                'auto_apply_on_approval' => $category === 'data_change',
                'sla_hours' => $sla,
                'sort_order' => $index,
                'is_active' => true,
            ]);
        }
    }

    private function seedContractTypes(): void
    {
        foreach ([
            ['PKWTT', 'Perjanjian Kerja Waktu Tidak Tertentu', false, null, 60],
            ['PKWT', 'Perjanjian Kerja Waktu Tertentu', true, 12, 30],
            ['PROBATION', 'Perjanjian Percobaan', true, 3, 14],
            ['INTERNSHIP', 'Perjanjian Magang', true, 6, 14],
            ['PART_TIME', 'Perjanjian Paruh Waktu', true, 12, 30],
            ['PROJECT', 'Perjanjian Proyek', true, 6, 14],
            ['VOLUNTEER', 'Perjanjian Relawan', true, 12, 30],
        ] as [$code, $name, $fixed, $months, $reminder]) {
            HrContractType::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'is_fixed_term' => $fixed,
                'default_duration_months' => $months,
                'reminder_days_before' => $reminder,
                'is_active' => true,
            ]);
        }
    }

    private function seedDocumentTypes(): void
    {
        $types = [
            ['KTP', 'Kartu Tanda Penduduk', 'identity', true, false, true, 'hr'],
            ['NPWP', 'Nomor Pokok Wajib Pajak', 'tax', true, false, false, 'hr'],
            ['BPJS_KES', 'Kartu BPJS Kesehatan', 'insurance', true, false, false, 'hr'],
            ['IJAZAH', 'Ijazah Pendidikan Terakhir', 'education', false, false, true, 'hr'],
            ['TRANSKRIP', 'Transkrip Nilai', 'education', false, false, false, 'hr'],
            ['SERTIFIKAT_PENDIDIK', 'Sertifikat Pendidik', 'certification', true, true, false, 'hr'],
            ['KONTRAK_KERJA', 'Kontrak Kerja', 'contract', true, true, true, 'hr'],
            ['SK_PENGANGKATAN', 'Surat Keputusan Pengangkatan', 'employment', true, false, false, 'hr'],
            ['CV', 'Daftar Riwayat Hidup', 'employment', false, false, false, 'hr'],
            ['SURAT_SEHAT', 'Surat Keterangan Sehat', 'health', false, true, false, 'private'],
            ['PAKTA_INTEGRITAS', 'Pakta Integritas', 'policy', false, false, false, 'hr'],
            ['SKCK', 'Surat Keterangan Catatan Kepolisian', 'identity', true, true, false, 'private'],
            ['SURAT_PERINGATAN', 'Surat Peringatan', 'disciplinary', true, false, false, 'private'],
            ['DOKUMEN_TERMINASI', 'Dokumen Pemberhentian', 'termination', true, false, false, 'private'],
        ];

        foreach ($types as $index => [$code, $name, $category, $number, $expiry, $mandatory, $visibility]) {
            HrDocumentType::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'category' => $category,
                'requires_number' => $number,
                'requires_expiry' => $expiry,
                'is_mandatory' => $mandatory,
                'default_visibility' => $visibility,
                'sort_order' => $index,
                'is_active' => true,
            ]);
        }
    }

    private function seedSkillsAndCertifications(): void
    {
        foreach ([
            ['PROGRAMMING', 'Pemrograman', 'technical'], ['LEADERSHIP', 'Kepemimpinan', 'leadership'],
            ['TEACHING', 'Mengajar', 'technical'], ['PUBLIC_SPEAKING', 'Public Speaking', 'behavioral'],
            ['ARABIC', 'Bahasa Arab', 'language'], ['ENGLISH', 'Bahasa Inggris', 'language'],
            ['QURAN_MEMORIZATION', 'Hafalan Al-Quran', 'religious'], ['ACCOUNTING', 'Akuntansi', 'technical'],
            ['NETWORKING', 'Jaringan Komputer', 'technical'], ['PROJECT_MANAGEMENT', 'Manajemen Proyek', 'behavioral'],
            ['COUNSELING', 'Bimbingan Konseling', 'behavioral'], ['FIRST_AID', 'Pertolongan Pertama', 'technical'],
            ['CURRICULUM_DESIGN', 'Penyusunan Kurikulum', 'technical'], ['DATA_ANALYSIS', 'Analisis Data', 'technical'],
        ] as [$code, $name, $category]) {
            HrSkill::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'category' => $category, 'is_active' => true,
            ]);
        }

        foreach ([
            ['SERTIFIKAT_PENDIDIK', 'Sertifikat Pendidik', 'teacher', true, null, true],
            ['IELTS', 'IELTS', 'professional', true, 24, false],
            ['TOEFL', 'TOEFL', 'professional', true, 24, false],
            ['TOT', 'Training of Trainers', 'professional', true, 36, false],
            ['K3', 'Sertifikasi Keselamatan Kerja (K3)', 'safety', true, 36, false],
            ['P3K', 'Pelatihan P3K', 'first_aid', true, 24, false],
            ['SIM_A', 'Surat Izin Mengemudi A', 'license', true, 60, false],
            ['TAHFIZ_SANAD', 'Sanad Tahfiz', 'religious', false, null, false],
        ] as [$code, $name, $category, $expiry, $months, $mandatoryTeacher]) {
            HrCertification::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'category' => $category,
                'requires_expiry' => $expiry,
                'validity_months' => $months,
                'is_mandatory_for_teachers' => $mandatoryTeacher,
                'is_active' => true,
            ]);
        }
    }

    private function seedLeaveTypes(): void
    {
        foreach ([
            ['ANNUAL', 'Cuti Tahunan', true, true, 12], ['SICK', 'Cuti Sakit', true, false, null],
            ['MATERNITY', 'Cuti Melahirkan', true, true, 3], ['PERMIT', 'Izin', false, true, null],
            ['UNPAID', 'Cuti Tanpa Upah', false, true, null], ['HAJJ', 'Cuti Ibadah Haji', true, true, null],
        ] as [$code, $name, $paid, $approval, $maxDays]) {
            HrLeaveType::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'is_paid' => $paid, 'requires_approval' => $approval,
                'max_days_per_year' => $maxDays, 'is_active' => true,
            ]);
        }
    }

    private function seedValidationRules(): void
    {
        $rules = [
            ['hr.employee.without_assignment', 'Pegawai aktif harus memiliki penugasan', 'critical', 'employee', 'employee.without_assignment', 'Pegawai aktif tanpa penugasan aktif.', 'Tetapkan posisi pada tab Penugasan.'],
            ['hr.employee.without_organization', 'Pegawai harus berada pada unit organisasi', 'critical', 'employee', 'employee.without_organization', 'Pegawai belum terhubung ke unit organisasi.', 'Isi unit organisasi pegawai.'],
            ['hr.employee.without_department', 'Pegawai sebaiknya memiliki departemen', 'warning', 'employee', 'employee.without_department', 'Departemen pegawai belum diisi.', 'Lengkapi departemen pada data kepegawaian.'],
            ['hr.employee.without_position', 'Pegawai sebaiknya memiliki posisi', 'warning', 'employee', 'employee.without_position', 'Posisi pegawai belum diisi.', 'Tetapkan posisi pada penugasan utama.'],
            ['hr.employee.without_emergency_contact', 'Kontak darurat wajib diisi', 'warning', 'employee', 'employee.without_emergency_contact', 'Kontak darurat belum diisi.', 'Minta pegawai melengkapi kontak darurat.'],
            ['hr.employee.without_education', 'Riwayat pendidikan wajib diisi', 'warning', 'employee', 'employee.without_education', 'Riwayat pendidikan belum diisi.', 'Lengkapi riwayat pendidikan.'],
            ['hr.employee.missing_document', 'Dokumen wajib harus terunggah', 'critical', 'employee', 'employee.missing_mandatory_document', 'Ada dokumen wajib yang belum diunggah.', 'Unggah dokumen wajib pada tab Dokumen.'],
            ['hr.employee.invalid_dates', 'Tanggal kepegawaian harus konsisten', 'critical', 'employee', 'employee.invalid_dates', 'Tanggal berhenti mendahului tanggal masuk.', 'Perbaiki tanggal pada data kepegawaian.'],
            ['hr.assignment.multiple_primary', 'Hanya satu penugasan utama aktif', 'critical', 'assignment', 'assignment.multiple_primary', 'Pegawai memiliki lebih dari satu penugasan utama.', 'Tutup penugasan utama yang tidak berlaku.'],
            ['hr.assignment.orphan', 'Penugasan harus memiliki unit', 'warning', 'assignment', 'assignment.orphan', 'Penugasan aktif tanpa unit organisasi.', 'Lengkapi unit pada penugasan.'],
            ['hr.manager.circular', 'Hierarki atasan tidak boleh melingkar', 'critical', 'employee', 'manager.circular', 'Rantai atasan membentuk lingkaran.', 'Perbaiki hubungan atasan pegawai.'],
            ['hr.manager.orphan', 'Atasan yang dirujuk harus ada', 'critical', 'employee', 'manager.orphan', 'Atasan yang dirujuk tidak ditemukan.', 'Perbarui atasan pegawai.'],
            ['hr.position.orphan', 'Posisi harus berada dalam unit', 'warning', 'position', 'position.orphan', 'Posisi tidak terhubung ke unit organisasi.', 'Tetapkan unit pada posisi.'],
            ['hr.position.without_job', 'Posisi sebaiknya merujuk jabatan', 'info', 'position', 'position.without_job', 'Posisi belum dihubungkan ke jabatan (job).', 'Pilih jabatan pada posisi.'],
            ['hr.position.headcount_exceeded', 'Formasi tidak boleh terlampaui', 'critical', 'position', 'position.headcount_exceeded', 'Jumlah terisi melebihi formasi yang diizinkan.', 'Naikkan formasi atau pindahkan penugasan.'],
            ['hr.contract.fixed_term_without_end_date', 'Kontrak berjangka wajib berakhir', 'critical', 'contract', 'contract.fixed_term_without_end_date', 'Kontrak berjangka tanpa tanggal berakhir.', 'Isi tanggal berakhir kontrak.'],
            ['hr.contract.expired_still_active', 'Kontrak berakhir harus diperbarui statusnya', 'warning', 'contract', 'contract.expired_still_active', 'Kontrak sudah berakhir tetapi masih aktif.', 'Perpanjang atau akhiri kontrak.'],
            ['hr.document.expiring', 'Dokumen yang akan berakhir perlu diperbarui', 'warning', 'document', 'document.expiring', 'Ada dokumen yang akan berakhir.', 'Ingatkan pegawai untuk memperbarui dokumen.', ['window_days' => 30]],
            ['hr.certification.expiring', 'Sertifikasi yang akan berakhir perlu diperbarui', 'warning', 'certification', 'certification.expiring', 'Ada sertifikasi yang akan berakhir.', 'Jadwalkan perpanjangan sertifikasi.', ['window_days' => 30]],
            ['hr.certification.missing_mandatory', 'Guru wajib memiliki sertifikasi wajib', 'critical', 'certification', 'certification.missing_mandatory', 'Guru belum memiliki sertifikasi wajib yang berlaku.', 'Daftarkan guru pada program sertifikasi.', ['window_days' => 30]],
            ['hr.person.duplicate_nik', 'NIK tidak boleh ganda', 'critical', 'person', 'person.duplicate_nik', 'NIK yang sama terdaftar pada beberapa orang.', 'Gabungkan data pegawai duplikat.'],
            ['hr.person.duplicate_contact', 'Kontak tidak seharusnya dipakai bersama', 'warning', 'person', 'person.duplicate_contact', 'Nomor/surel yang sama dipakai beberapa orang.', 'Periksa kemungkinan duplikasi data.'],
        ];

        foreach ($rules as $index => $rule) {
            [$code, $name, $severity, $entity, $handler, $message, $suggestion] = $rule;
            $config = $rule[7] ?? null;

            HrValidationRule::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'category' => match (true) {
                    str_contains($code, 'expir') => 'expiry',
                    str_contains($code, 'manager'), str_contains($code, 'headcount') => 'hierarchy',
                    str_contains($code, 'mandatory'), str_contains($code, 'mandatory') => 'compliance',
                    str_contains($code, 'orphan'), str_contains($code, 'without') => 'completeness',
                    default => 'integrity',
                },
                'severity' => $severity,
                'entity_type' => $entity,
                'handler' => $handler,
                'config' => $config,
                'message' => $message,
                'suggestion' => $suggestion,
                'is_active' => true,
                'auto_detect' => true,
                'sort_order' => $index,
            ]);
        }
    }

    private function seedLifecycleTemplates(): void
    {
        $onboarding = HrOnboardingTemplate::query()->updateOrCreate(
            ['code' => 'DEFAULT_ONBOARDING'],
            [
                'name' => 'Onboarding Pegawai Baru (Bawaan)',
                'priority' => 0,
                'status' => 'active',
                'description' => 'Daftar tugas bawaan; sesuaikan per worker type atau unit sesuai kebutuhan sekolah.',
            ],
        );

        $onboardingTasks = [
            ['data', 'Lengkapi data pribadi', 'data', 'employee', -7, true, false],
            ['docs', 'Unggah dokumen wajib (KTP, ijazah, NPWP)', 'document', 'employee', -5, true, false],
            ['contract', 'Tandatangani kontrak kerja', 'contract', 'hr', -3, true, true],
            ['verify', 'Verifikasi dokumen oleh HR', 'verification', 'hr', -2, true, false],
            ['account', 'Pembuatan akun & surel kerja', 'account', 'it', -2, false, false],
            ['equipment', 'Penetapan perangkat kerja', 'equipment', 'it', 0, false, false],
            ['orientation', 'Orientasi & pengenalan unit', 'orientation', 'manager', 1, true, false],
            ['policy', 'Pernyataan tunduk pada kebijakan', 'policy', 'employee', 3, true, false],
            ['training', 'Penugasan pelatihan awal', 'training', 'hr', 14, false, false],
        ];

        foreach ($onboardingTasks as $index => [$code, $title, $category, $owner, $offset, $mandatory, $blocks]) {
            HrOnboardingTemplateTask::query()->updateOrCreate(
                ['template_id' => $onboarding->getKey(), 'code' => $code],
                [
                    'title' => $title,
                    'category' => $category,
                    'owner_type' => $owner,
                    'due_offset_days' => $offset,
                    'is_mandatory' => $mandatory,
                    'blocks_activation' => $blocks,
                    'requires_attachment' => $category === 'document',
                    'sort_order' => $index,
                ],
            );
        }

        $offboarding = HrOffboardingTemplate::query()->updateOrCreate(
            ['code' => 'DEFAULT_OFFBOARDING'],
            [
                'name' => 'Offboarding Pegawai (Bawaan)',
                'priority' => 0,
                'status' => 'active',
                'description' => 'Termasuk pembebasan area (clearance) yang menahan status tidak aktif sampai selesai.',
            ],
        );

        $offboardingTasks = [
            ['handover', 'Serah terima pekerjaan (knowledge transfer)', 'knowledge_transfer', 'employee', 'none', -7, true, false],
            ['asset', 'Pengembalian aset & inventaris', 'asset_return', 'other', 'asset', 0, true, true],
            ['finance', 'Clearance keuangan (piutang, uang muka)', 'financial', 'finance', 'finance', 0, true, true],
            ['documents', 'Penyerahan dokumen & berkas unit', 'document', 'employee', 'document', -1, true, false],
            ['library', 'Pengembalian pinjaman perpustakaan', 'clearance', 'other', 'library', 0, false, false],
            ['it', 'Pencabutan akses sistem & akun', 'account_revocation', 'it', 'it', 1, true, true],
            ['exit_interview', 'Wawancara keluar', 'interview', 'hr', 'none', 1, true, false],
        ];

        foreach ($offboardingTasks as $index => [$code, $title, $category, $owner, $area, $offset, $mandatory, $blocks]) {
            HrOffboardingTemplateTask::query()->updateOrCreate(
                ['template_id' => $offboarding->getKey(), 'code' => $code],
                [
                    'title' => $title,
                    'category' => $category,
                    'owner_type' => $owner,
                    'clearance_area' => $area === 'none' ? null : $area,
                    'requires_clearance' => $area !== 'none',
                    'due_offset_days' => $offset,
                    'is_mandatory' => $mandatory,
                    'blocks_exit' => $blocks,
                    'sort_order' => $index,
                ],
            );
        }
    }
}
