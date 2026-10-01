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
];
