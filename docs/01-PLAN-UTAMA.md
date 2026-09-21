# Syntaf School ERP — Rencana Utama Proyek

## 1. Ringkasan Proyek

**Syntaf School** adalah sistem ERP sekolah (School Management System) berbasis web dengan arsitektur **decoupled** (frontend & backend terpisah).

| Aspek | Keputusan |
|-------|-----------|
| **Nama Produk** | Syntaf School |
| **Jenis** | ERP / School Management System |
| **Backend** | Laravel (REST API) |
| **Frontend** | React JS + TypeScript |
| **Arsitektur** | Decoupled / Headless (SPA + API) |
| **Autentikasi** | Laravel Sanctum (Token-based) |
| **Bahasa** | Bahasa Indonesia (UI), English (code) |

---

## 2. Arsitektur Sistem

```
┌─────────────────────┐        HTTPS/JSON        ┌─────────────────────┐
│   FRONTEND (SPA)    │  ───────────────────────▶ │   BACKEND (API)     │
│  React + TS + Vite  │  ◀─────────────────────── │   Laravel + Sanctum │
│  (Port 5173 dev)    │      REST API v1          │   (Port 8000 dev)   │
└─────────────────────┘                           └──────────┬──────────┘
                                                             │
                                                             ▼
                                                  ┌─────────────────────┐
                                                  │  Database (MySQL)   │
                                                  │  Cache/Queue (Redis)│
                                                  └─────────────────────┘
```

### 2.1 Struktur Repository

Monorepo dengan dua aplikasi terpisah:

```
schoolerp/
├── backend/                 # Laravel API
│   ├── app/
│   ├── database/
│   ├── routes/
│   └── ...
├── frontend/                # React + TypeScript
│   ├── src/
│   └── ...
└── docs/                    # Dokumentasi & perencanaan
```

---

## 3. Teknologi (Tech Stack)

### 3.1 Backend — Laravel

| Komponen | Teknologi |
|----------|-----------|
| Framework | Laravel 11.x |
| Bahasa | PHP 8.2+ |
| Auth | Laravel Sanctum |
| Permission/Role | spatie/laravel-permission |
| Database | MySQL 8 |
| Activity Log | spatie/laravel-activitylog |
| Media | spatie/laravel-medialibrary (opsional) |
| PDF Export | barryvdh/laravel-dompdf |
| Excel Export | maatwebsite/excel |
| Queue | Laravel Queue + Redis |
| API Docs | Scribe / OpenAPI |
| Testing | PHPUnit / Pest |

### 3.2 Frontend — React + TypeScript

| Komponen | Teknologi |
|----------|-----------|
| Build Tool | Vite |
| Bahasa | TypeScript |
| UI Library | Ant Design / shadcn-ui (pilih salah satu) |
| Routing | React Router v6 |
| State Management | Zustand / Redux Toolkit |
| Data Fetching | TanStack Query (React Query) |
| HTTP Client | Axios |
| Form | React Hook Form + Zod |
| Charts | Recharts / ApexCharts |
| Tabel | TanStack Table |
| Testing | Vitest + React Testing Library |

---

## 4. Modul Utama Sistem

Sistem dibagi menjadi beberapa modul besar:

| # | Modul | Deskripsi |
|---|-------|-----------|
| 1 | **Master Data** | Data referensi dasar (lihat dokumen terpisah) |
| 2 | **Manajemen Pengguna** | User, Role, Permission, Hak Akses |
| 3 | **Akademik** | Tahun Ajaran, Semester, Kelas, Mata Pelajaran, Jadwal |
| 4 | **Kesiswaan** | Siswa, PPDB, Mutasi, Pelanggaran, Prestasi |
| 5 | **Kepegawaian** | Guru, Staf, Absensi Pegawai, Payroll |
| 6 | **Keuangan** | SPP, Tagihan, Pembayaran, Tabungan Siswa, Akuntansi |
| 7 | **Perpustakaan** | Buku, Peminjaman, Pengembalian, Denda |
| 8 | **Penilaian** | Nilai, Rapor, Ujian, Bank Soal |
| 9 | **Absensi** | Absensi Siswa, Rekap Kehadiran |
| 10 | **Inventaris** | Aset, Barang, Stok |
| 11 | **PPDB** | Penerimaan Peserta Didik Baru (online) |
| 12 | **Laporan** | Berbagai laporan & ekspor |
| 13 | **Pengaturan** | Konfigurasi sistem, profil sekolah |

### 4.1 Detail Modul Inti

#### Modul Akademik
- Tahun Ajaran (aktif/nonaktif)
- Semester
- Tingkat / Jenjang (SD/SMP/SMA/SMK)
- Kelas & Rombel (Rombongan Belajar)
- Mata Pelajaran (Mapel)
- Kurikulum
- Jadwal Pelajaran
- Guru Pengajar Mapel

#### Modul Kesiswaan
- Data Siswa (biodata lengkap)
- Wali Murid / Orang Tua
- Riwayat Kelas
- Mutasi (masuk/keluar/pindah)
- Pelanggaran & Poin
- Prestasi
- Ekstrakurikuler

#### Modul Keuangan
- Komponen Biaya
- Tagihan per siswa
- Pembayaran (tunai/transfer/VA)
- Tabungan siswa
- Beasiswa & Diskon
- Laporan keuangan

---

## 5. Role & Hak Akses (RBAC)

### 5.1 Role Default

| Role | Deskripsi |
|------|-----------|
| Super Admin | Akses penuh seluruh sistem |
| Admin Sekolah | Mengelola data operasional sekolah |
| Kepala Sekolah | Akses laporan & monitoring |
| Bendahara | Modul keuangan |
| Guru | Nilai, absensi, jadwal kelas yang diajar |
| Wali Kelas | Data kelas yang dibina |
| Staf | Akses terbatas sesuai tugas |
| Siswa | Melihat data pribadi (portal siswa) |
| Wali Murid | Melihat data anak (portal orang tua) |
| Pustakawan | Modul perpustakaan |

### 5.2 Matriks Hak Akses (Ringkas)

`CRUD = Create, Read, Update, Delete`

| Modul | Super Admin | Admin | Guru | Bendahara | Siswa | Wali |
|-------|:-----------:|:-----:|:----:|:---------:|:-----:|:----:|
| Master Data | CRUD | CRUD | R | R | - | - |
| Akademik | CRUD | CRUD | R | - | R | R |
| Kesiswaan | CRUD | CRUD | R | - | R (self) | R (anak) |
| Keuangan | CRUD | R | - | CRUD | R (self) | R (anak) |
| Penilaian | CRUD | R | CRUD | - | R (self) | R (anak) |
| Perpustakaan | CRUD | CRUD | CR | - | R | - |

---

## 6. Standar Konvensi

### 6.1 API
- Base URL: `/api/v1`
- Format respons konsisten:

```json
{
  "success": true,
  "message": "Data berhasil diambil",
  "data": { },
  "meta": { "page": 1, "per_page": 15, "total": 100 }
}
```

- Method: `GET`, `POST`, `PUT/PATCH`, `DELETE`
- Status code standar: 200, 201, 400, 401, 403, 404, 422, 500

### 6.2 Penamaan
- Database: `snake_case`, tabel plural (contoh: `students`, `classes`)
- Model: `PascalCase` singular (contoh: `Student`, `SchoolClass`)
- API Resource: `PascalCase` + `Resource`
- React Component: `PascalCase`
- File/folder React: `kebab-case` atau `PascalCase`

### 6.3 Git Branching
- `main` — produksi
- `develop` — integrasi
- `feature/*` — fitur baru
- `bugfix/*` — perbaikan

---

## 7. Environment & Konfigurasi

### Backend `.env` (kunci utama)
```
APP_NAME="Syntaf School"
APP_URL=http://localhost:8000
DB_CONNECTION=mysql
DB_DATABASE=syntaf_school
SANCTUM_STATEFUL_DOMAINS=localhost:5173
FRONTEND_URL=http://localhost:5173
```

### Frontend `.env`
```
VITE_API_BASE_URL=http://localhost:8000/api/v1
VITE_APP_NAME=Syntaf School
```

---

## 8. Roadmap Pengembangan

### Fase 0 — Fondasi (Minggu 1)
- [ ] Setup repo & struktur monorepo
- [ ] Inisialisasi Laravel + konfigurasi
- [ ] Inisialisasi React + TS + Vite + Tailwind
- [ ] Konfigurasi Sanctum, CORS
- [ ] Setup autentikasi (login/logout/me)
- [ ] Layout dashboard dasar

### Fase 1 — Master Data (Minggu 2–3)
- [ ] Implementasi seluruh master data (lihat `02-PLAN-MASTER-DATA.md`)
- [ ] CRUD + validasi + seeder
- [ ] Halaman manajemen di frontend

### Fase 2 — Akademik & Kesiswaan (Minggu 4–5)
- [ ] Tahun ajaran & semester
- [ ] Kelas & rombel
- [ ] Data siswa & wali murid
- [ ] Manajemen pengguna & role

### Fase 3 — Keuangan & Penilaian (Minggu 6–7)
- [ ] Komponen biaya & tagihan
- [ ] Pembayaran & tabungan
- [ ] Input nilai & rapor

### Fase 4 — Pendukung (Minggu 8–9)
- [ ] Perpustakaan
- [ ] Inventaris
- [ ] Laporan & ekspor

### Fase 5 — Portal & PPDB (Minggu 10–11)
- [ ] Portal siswa & wali murid
- [ ] PPDB online
- [ ] Notifikasi (email/WA)

### Fase 6 — Hardening & Deploy (Minggu 12)
- [ ] Testing menyeluruh
- [ ] Optimasi performa
- [ ] Dokumentasi API
- [ ] Deployment produksi

---

## 9. Kriteria Keberhasilan (Definition of Done)

- Setiap endpoint API memiliki validasi & otorisasi
- Setiap fitur memiliki unit/feature test
- UI responsif (mobile-friendly)
- Dokumentasi API tersedia
- Audit log untuk aksi penting
- Backup database terjadwal

---

## 10. Dokumen Terkait

- [`02-PLAN-MASTER-DATA.md`](./02-PLAN-MASTER-DATA.md) — Rencana detail Master Data
- [`03-RANCANGAN-DATABASE.md`](./03-RANCANGAN-DATABASE.md) — Skema & relasi database
- [`04-SPESIFIKASI-API-FRONTEND.md`](./04-SPESIFIKASI-API-FRONTEND.md) — API & struktur frontend
