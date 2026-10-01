# Syntaf School ERP

Sistem ERP (School Management System) untuk **Syntaf School** dengan arsitektur decoupled:

- **Backend:** Laravel 11 (REST API)
- **Frontend:** React JS + TypeScript (Vite)

## Struktur Repository

```
schoolerp/
├── backend/     # Laravel API
├── frontend/    # React + TypeScript
└── docs/        # Dokumentasi & perencanaan
```

## Dokumentasi Perencanaan

| Dokumen | Deskripsi |
|---------|-----------|
| [`docs/01-PLAN-UTAMA.md`](./docs/01-PLAN-UTAMA.md) | Rencana utama, arsitektur, tech stack, modul, roadmap |
| [`docs/02-PLAN-MASTER-DATA.md`](./docs/02-PLAN-MASTER-DATA.md) | Rencana detail 41 tabel master data |
| [`docs/03-RANCANGAN-DATABASE.md`](./docs/03-RANCANGAN-DATABASE.md) | Skema database, relasi, ERD, migration |
| [`docs/04-SPESIFIKASI-API-FRONTEND.md`](./docs/04-SPESIFIKASI-API-FRONTEND.md) | Spesifikasi REST API & struktur frontend |
| [`docs/05-PLAN-FASE-2.md`](./docs/05-PLAN-FASE-2.md) | Breakdown Fase 2: menu Akademik, Kesiswaan & Kepegawaian |
| [`docs/17-MODUL-WORKFLOW-ENGINE.md`](./docs/17-MODUL-WORKFLOW-ENGINE.md) | **Workflow & Approval Engine** — infrastruktur persetujuan bersama seluruh modul |
| [`docs/workflow/01-PANDUAN-INTEGRASI.md`](./docs/workflow/01-PANDUAN-INTEGRASI.md) | Panduan modul ERP memakai engine (provider konteks, trigger, aksi, resolver) |
| [`docs/18-MODUL-AKTIVITAS-SISWA.md`](./docs/18-MODUL-AKTIVITAS-SISWA.md) | **Student Activity & Extracurricular** — ekstrakurikuler, keanggotaan, sesi, penilaian perkembangan, kompetisi, prestasi, sertifikat, portofolio |
| [`docs/activity/01-PANDUAN-INTEGRASI.md`](./docs/activity/01-PANDUAN-INTEGRASI.md) | Panduan integrasi modul aktivitas (kebijakan, kontrak, praktik yang dihindari) |
| [`docs/PROGRESS.md`](./docs/PROGRESS.md) | Progres implementasi per fase |

## Status

Implementasi berjalan mengikuti roadmap; progres per fase dicatat di
[`docs/PROGRESS.md`](./docs/PROGRESS.md).

Infrastruktur lintas modul yang sudah tersedia:

- **Workflow & Approval Engine** — mesin persetujuan generik, versioned, ber-audit
  ([blueprint](./docs/17-MODUL-WORKFLOW-ENGINE.md) · [panduan integrasi](./docs/workflow/01-PANDUAN-INTEGRASI.md))
- **Student Activity & Extracurricular** — platform pengembangan diri siswa: katalog,
  keanggotaan, sesi & kehadiran, penilaian perkembangan, kompetisi, prestasi,
  sertifikat, dan portofolio
  ([blueprint](./docs/18-MODUL-AKTIVITAS-SISWA.md) · [panduan integrasi](./docs/activity/01-PANDUAN-INTEGRASI.md))
