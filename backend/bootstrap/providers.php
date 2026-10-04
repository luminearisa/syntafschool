<?php

use App\Modules\CBT\Providers\CbtServiceProvider;
use App\Modules\Bk\Providers\BkServiceProvider;
use App\Modules\Clinic\Providers\ClinicServiceProvider;
use App\Modules\LMS\Providers\LmsServiceProvider;
use App\Modules\Supervisi\Providers\SupServiceProvider;
use App\Modules\UKS\Providers\UksServiceProvider;
use App\Modules\Activity\Providers\ActivityServiceProvider;
use App\Modules\Asset\Providers\AssetServiceProvider;
use App\Modules\Workflow\Providers\WorkflowServiceProvider;
use App\Modules\Hrd\Providers\HrdPhase2ServiceProvider;
use App\Modules\Hrd\Providers\HrdPhase3ServiceProvider;
use App\Modules\SchoolOperations\Transportation\Providers\TransportationServiceProvider;
use App\Modules\SchoolOperations\Laundry\Providers\LaundryServiceProvider;
use App\Modules\SchoolOperations\Religious\Providers\ReligiousServiceProvider;
use App\Modules\SchoolOperations\Competitions\Providers\CompetitionServiceProvider;
use App\Modules\Hrd\Providers\HrdServiceProvider;
use App\Modules\Mutation\Providers\MutationServiceProvider;
use App\Modules\Procurement\Providers\ProcurementServiceProvider;
use App\Modules\Promotion\Providers\PromotionServiceProvider;
use App\Modules\StudentProgress\Providers\StudentProgressServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // Modul LMS mendaftarkan konfigurasi, migrasi, route, middleware, dan
    // binding kontrak domainnya sendiri (Integrated/Standalone Mode).
    LmsServiceProvider::class,
    // Modul Supervisi mendaftarkan migrasi `sup_*` dan route /api/v1/supervisi/*.
    SupServiceProvider::class,
    // Modul CBT mendaftarkan migrasi `cbt_*` dan route /api/v1/cbt/*.
    CbtServiceProvider::class,
    // Modul UKS mendaftarkan migrasi `uks_*` dan route /api/v1/uks/*.
    UksServiceProvider::class,
    // Modul Clinic/UKS mendaftarkan migrasi `clinic_*`, binding kontrak
    // integrasi (Student/Inventory/Finance/Notifikasi), dan route
    // /api/v1/clinic/*. Berdiri di samping modul UKS: UKS tetap untuk
    // alur ringan, Clinic untuk alur klinis penuh (triase → resep →
    // dispensing → pemulangan) dengan Inventory sebagai sumber stok.
    ClinicServiceProvider::class,

    // Modul Bimbingan & Konseling (BK) mendaftarkan migrasi `bk_*`, binding
    // kontrak integrasi (siswa, wali, akademik, presensi, kedisiplinan, asrama,
    // klinik, aktivitas, notifikasi, dokumen, workflow, audit), Policy, batas
    // laju, perintah artisan, dan route `/api/v1/bk/*`.
    BkServiceProvider::class,
    // Modul Workflow & Approval Engine: infrastruktur bersama seluruh modul.
    // Mendaftarkan migrasi `workflow_*`, route /api/v1/workflows/*, registry
    // resolver approver & aksi otomatis, kalender kerja, dan direktori
    // organisasi. Modul ERP lain menambah titik ekstensi lewat
    // WorkflowRegistrar tanpa menyentuh berkas modul ini.
    // Modul Student Activity & Extracurricular: katalog, keanggotaan, sesi,
    // penilaian perkembangan, kompetisi, prestasi, sertifikat, dan portofolio.
    // Seluruh integrasi (siswa, wali, pegawai, fasilitas, inventaris, LMS,
    // keuangan, asrama) lewat kontrak — modul lain tetap sumber kebenarannya.
    ActivityServiceProvider::class,
    AssetServiceProvider::class,

    WorkflowServiceProvider::class,

    // Modul HRD / Kepegawaian: sumber kebenaran data SDM (pegawai, organisasi,
    // posisi, penugasan, kontrak, dokumen, transaksi siklus hidup). Modul lain
    // membacanya lewat kontrak di `Domain/Contracts`, dan seluruh perubahan
    // organisasi melewati mesin transaksi + Workflow Engine yang sudah ada.
    HrdServiceProvider::class,

    // Modul HRD Phase 2: workforce (jadwal, presensi, lembur, timesheet),
    // cuti, payroll, benefit, kinerja, pembelajaran, karier, dan talenta.
    // Seluruh kontrak domain dan penyedia luar didaftarkan di sini.
    HrdPhase2ServiceProvider::class,

    // Modul HRD Phase 3 (“System of Intelligence”): perencanaan tenaga kerja
    // strategis, skills/talent/succession intelligence, people analytics,
    // forecasting, dan orkestrasi AI + agent HR. Membaca data Phase 1/2;
    // tidak menduplikasi employee, payroll, attendance, atau LMS.
    HrdPhase3ServiceProvider::class,

    // Modul School Operations — Transportation Management (Phase 2): armada,
    // pengemudi, rute, permintaan & penugasan siswa, jadwal, trip, manifes,
    // boarding/drop-off, insiden, inspeksi, BBM, biaya, analitik, dan aplikasi
    // pengemudi. Membaca Student/Parent/Employee/Asset/Maintenance/Attendance/
    // Boarding/Finance dari modul yang sudah ada — tidak menduplikasi master.
    TransportationServiceProvider::class,

    // Modul School Operations — Religious & Mosque Management (Phase 3):
    // masjid, area, jadwal shalat (multi-sumber), imam/muadzin/khatib, shalat
    // Jumat & khutbah, kegiatan keagamaan, kajian, presensi shalat, roster
    // tugas, Ramadan, hari raya & qurban, prestasi keagamaan, analitik, dan
    // mode layar masjid. Membaca Student/Parent/HRD/Boarding/Attendance/Tahfidz
    // /Activity/LMS/Facility/Asset/Inventory/Finance/Workflow dari modul yang
    // sudah ada — tidak menduplikasi master mana pun.
    ReligiousServiceProvider::class,
    // Competition Management domain engine (School Operations Phase 5).
    CompetitionServiceProvider::class,

    // Modul School Operations — Laundry Management: layanan & harga laundry,
    // policy jatah gratis berversi, entitlement per periode + carry-over +
    // ledger, order dengan split free/paid tersnapshot, pembayaran Wallet/RFID
    // idempoten + refund, batch processing, QC, insiden & kompensasi,
    // pickup/delivery terverifikasi, portal santri & wali, notifikasi,
    // laporan, dan audit. Wallet/Student/Parent/Boarding/Finance/Notification
    // tetap milik modulnya masing-masing — laundry hanya memakai provider.
    LaundryServiceProvider::class,

    // Modul Promotion (A10): kenaikan kelas & kelulusan (tabel promotion_*,
    // graduations) + route /api/v1/promotion/* & /api/v1/graduations/*.
    PromotionServiceProvider::class,
    // Modul StudentProgress: prestasi & pelanggaran siswa + route
    // /api/v1/student-achievements/* & /api/v1/student-violations/*.
    StudentProgressServiceProvider::class,
    // Modul Mutation: mutasi siswa (masuk/keluar/pindah) + tabel student_transfers
    // + route /api/v1/student-transfers/*.
    MutationServiceProvider::class,

    // Modul Procurement & Vendor: PR → approval → RFQ → quotation → evaluasi →
    // pemilihan vendor → PO → penerimaan → invoice → 3-way match → handoff
    // Accounting. Tabel `proc_*` + route /api/v1/procurement/*. Menyediakan
    // VendorProvider/ProcurementProvider untuk modul Asset lewat kontrak.
    ProcurementServiceProvider::class,
];
