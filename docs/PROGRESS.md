# Syntaf School ERP — Progress Tracking

> File ini mencatat progres implementasi. Tandai `[x]` jika selesai, `[-]` jika sedang dikerjakan, `[ ]` jika belum.

## Environment Terverifikasi
- PHP 8.4.11
- Composer 2.8.10
- Node v25.9.0
- npm 11.12.1
- **Database dev: SQLite** (`backend/database/database.sqlite`) — sebelumnya MySQL 9.4.0
- Test: SQLite in-memory (lihat `phpunit.xml`)

---

## Fase 0 — Fondasi

### 0.1 Backend (Laravel) — SELESAI
- [x] `composer create-project laravel/laravel backend` (Laravel 13.31)
- [x] Konfigurasi `.env` (DB MySQL, APP_NAME, FRONTEND_URL)
- [x] Install Sanctum (4.3)
- [x] Install spatie/laravel-permission (8.3)
- [x] Install spatie/laravel-activitylog (5.1)
- [x] Konfigurasi CORS
- [x] Buat migrasi kustom users (kolom tambahan + soft delete + polymorphic)
- [x] Auth controller (login/logout/me) dengan token Sanctum
- [x] API Resource response trait ([`ApiResponse`](backend/app/Traits/ApiResponse.php:1))
- [x] Rate limiting (login: 5/menit)
- [x] Custom exception handler (format error konsisten)
- [x] Lokalisasi validasi Bahasa Indonesia
- [x] Uji login & CRUD via curl (berhasil)

### 0.2 Frontend (React + TS) — SELESAI
- [x] Setup manual Vite + React + TS (menghindari prompt interaktif `npm create vite`)
- [x] Install dependencies: react-router-dom, @tanstack/react-query, axios, zustand, react-hook-form, zod, sonner, lucide-react, clsx, tailwind-merge
- [x] Konfigurasi Tailwind CSS + PostCSS
- [x] Alias `@/` di [`vite.config.ts`](frontend/vite.config.ts:1) & [`tsconfig.app.json`](frontend/tsconfig.app.json:1)
- [x] Axios instance + interceptor ([`lib/axios.ts`](frontend/src/lib/axios.ts:1))
- [x] Auth store Zustand ([`stores/authStore.ts`](frontend/src/stores/authStore.ts:1))
- [x] Query client setup ([`lib/queryClient.ts`](frontend/src/lib/queryClient.ts:1))
- [x] Routing + ProtectedRoute ([`routes/index.tsx`](frontend/src/routes/index.tsx:1))
- [x] Layout Sidebar, Header, AppLayout
- [x] Halaman Login ([`LoginPage.tsx`](frontend/src/features/auth/pages/LoginPage.tsx:1))
- [x] Halaman Dashboard ([`DashboardPage.tsx`](frontend/src/features/dashboard/pages/DashboardPage.tsx:1))
- [x] UI kit dasar (Button, Input, Select, Textarea, Modal, Card, Badge, Spinner, EmptyState)
- [x] Verifikasi `tsc --noEmit` (0 error) & `npm run build` (sukses)

### 0.3 Autentikasi End-to-End — SELESAI
- [x] Login backend mengembalikan token + roles + permissions (HTTP 200)
- [x] `/auth/me` mengembalikan 401 JSON saat tanpa token
- [x] Interceptor menyisipkan Bearer token & logout otomatis saat 401
- [x] Base URL frontend diarahkan ke `http://localhost:8001/api/v1` (port 8000 terpakai proyek lain)

### 0.4 Frontend Master Data — SELESAI (arsitektur config-driven)
- [x] Registri resource ([`constants/masterData.ts`](frontend/src/constants/masterData.ts:1)) mirror `config/master_data.php`
- [x] Halaman CRUD generik ([`MasterDataPage.tsx`](frontend/src/features/master-data/pages/MasterDataPage.tsx:1))
- [x] Form dinamis + validasi klien ([`DynamicForm.tsx`](frontend/src/features/master-data/components/DynamicForm.tsx:1))
- [x] Dropdown opsi dari resource lain ([`hooks/useOptions.ts`](frontend/src/hooks/useOptions.ts:1))
- [x] Semua 41 resource tanpa duplikasi halaman

---

## Fase 1 — Master Data (BACKEND SELESAI)

### 1A Fondasi Referensi
- [x] Migrations: religions, genders, occupations, education_backgrounds, guardian_relations, residence_types, transportations, income_ranges
- [x] Migrations: provinces, cities, districts, villages
- [x] Models + Relations
- [x] Seeders
- [x] Controllers (config-driven generik)
- [x] Frontend pages (generik, config-driven)

### 1B Akademik
- [x] Migrations: curricula, education_levels, academic_years, semesters, subjects, rooms
- [x] Models + Relations
- [x] Seeders
- [x] Controllers (config-driven generik)
- [x] Frontend pages (generik, config-driven)

### 1C Operasional
- [x] Migrations: fee_components, payment_methods, banks, discount_types, fiscal_years
- [x] Migrations: book_categories, publishers, authors, shelves
- [x] Migrations: item_categories, units, storage_locations
- [x] Migrations: attendance_statuses, leave_types
- [x] Models, Seeders, Controllers
- [x] Frontend pages (generik, config-driven)

### 1D Sistem
- [x] Migrations: settings, document_sequences, days
- [x] Migrations: schools, foundations
- [x] Models, Seeders, Controllers
- [x] Frontend pages (generik, config-driven)

### 1E RBAC — SELESAI
- [x] Role & Permission seeder (10 role, 12 modul, 47 permission)
- [x] User seeder (super admin: admin@syntaf.sch.id / password)
- [x] Middleware permission (alias `role`, `permission`, `role_or_permission` terdaftar)
- [x] Handler 403 JSON untuk [`UnauthorizedException`](backend/bootstrap/app.php:10)
- [x] `User` memaksa guard `web` untuk RBAC ([`getDefaultGuardName()`](backend/app/Models/User.php:30)) agar tidak bentrok dengan guard Sanctum

#### Backend API RBAC (SELESAI & TERVERIFIKASI)
- [x] [`RoleController`](backend/app/Http/Controllers/Api/RoleController.php:1) — CRUD role + `GET /roles/permissions` (grouped)
- [x] [`PermissionController`](backend/app/Http/Controllers/Api/PermissionController.php:1) — `GET /permissions` (items + grouped)
- [x] [`UserController`](backend/app/Http/Controllers/Api/UserController.php:1) — CRUD pengguna + assign role + toggle aktif
- [x] Resources: [`RoleResource`](backend/app/Http/Resources/RoleResource.php:1), [`PermissionResource`](backend/app/Http/Resources/PermissionResource.php:1), [`UserResource`](backend/app/Http/Resources/UserResource.php:1)
- [x] FormRequest: [`RoleRequest`](backend/app/Http/Requests/Rbac/RoleRequest.php:1), [`UserRequest`](backend/app/Http/Requests/Rbac/UserRequest.php:1)
- [x] Routes: `/users`, `/roles`, `/permissions` + middleware `permission:` di seluruh route (termasuk master data)
- [x] Perlindungan: super-admin tidak bisa diubah/dihapus, tidak bisa hapus akun sendiri, minimal 1 super-admin
- [x] Feature test [`RbacTest`](backend/tests/Feature/RbacTest.php:1) — **6 test, 14 assertion PASS**

#### Frontend RBAC (SELESAI)
- [x] Types RBAC ([`types/index.ts`](frontend/src/types/index.ts:74)) — `Permission`, `Role`, `PermissionCollection`, `RolePayload`, `UserPayload`
- [x] API module ([`rbacApi.ts`](frontend/src/features/rbac/api/rbacApi.ts:1)) — users, roles, permissions
- [x] Hook permission ([`usePermission.ts`](frontend/src/hooks/usePermission.ts:1)) — `can()`, `hasRole()`, `isSuperAdmin`
- [x] Komponen [`Can`](frontend/src/components/common/Can.tsx:1) & [`PermissionGuard`](frontend/src/routes/PermissionGuard.tsx:1)
- [x] Sidebar difilter sesuai permission ([`Sidebar.tsx`](frontend/src/components/layout/Sidebar.tsx:1)) + metadata permission di [`menu.ts`](frontend/src/constants/menu.ts:1)
- [x] Halaman Pengguna ([`UsersPage.tsx`](frontend/src/features/rbac/pages/UsersPage.tsx:1)) — tabel + paginasi + filter + search
- [x] Halaman Role & Hak Akses ([`RolesPage.tsx`](frontend/src/features/rbac/pages/RolesPage.tsx:1)) — matriks permission per modul
- [x] Route `/settings/users` & `/settings/roles` dengan guard permission
- [x] Verifikasi `tsc -b && vite build` sukses (1729 modul)

---

## Fase 2 — Akademik & Kesiswaan

> Breakdown detail, urutan pengerjaan, dan checklist per menu ada di
> [`05-PLAN-FASE-2.md`](./05-PLAN-FASE-2.md). Fase ini dipecah menjadi sub-fase **2A–2E**.

- [x] Tahun ajaran & semester aktif ([`AcademicController`](backend/app/Http/Controllers/Api/AcademicController.php:1), [`AcademicPeriodsPage.tsx`](frontend/src/features/academics/pages/AcademicPeriodsPage.tsx:1))
- [x] Kelas & rombel ([`SchoolClassController`](backend/app/Http/Controllers/Api/SchoolClassController.php:1), [`ClassesPage.tsx`](frontend/src/features/academics/pages/ClassesPage.tsx:1))
- [x] Manajemen pengguna & role (RBAC)
- [x] **2A** Data Pegawai (CRUD + seeder) — SELESAI
  - [x] [`EmployeeRequest`](backend/app/Http/Requests/Employee/EmployeeRequest.php:1) + [`EmployeeResource`](backend/app/Http/Resources/EmployeeResource.php:1)
  - [x] [`EmployeeController`](backend/app/Http/Controllers/Api/EmployeeController.php:1) — CRUD + toggle aktif + buat akun login
  - [x] Relasi `Employee::user()` (morphOne ke `User`)
  - [x] Route `/employees` dengan middleware `permission:employee.*` (7 route)
  - [x] [`EmployeeSeeder`](backend/database/seeders/EmployeeSeeder.php:1) — 10 pegawai (8 guru, 2 staf)
  - [x] Feature test [`EmployeeTest`](backend/tests/Feature/EmployeeTest.php:1) — **12 test, 30 assertion PASS**
  - [x] Frontend: [`EmployeesPage.tsx`](frontend/src/features/employees/pages/EmployeesPage.tsx:1), [`EmployeeFormModal.tsx`](frontend/src/features/employees/components/EmployeeFormModal.tsx:1), [`employeesApi.ts`](frontend/src/features/employees/api/employeesApi.ts:1)
- [x] **2B** Data siswa & wali murid — SELESAI
  - [x] CRUD siswa, akun portal, wilayah berjenjang, wali (satu wali utama), riwayat kelas
  - [x] Feature test [`StudentTest`](backend/tests/Feature/StudentTest.php:1) — **37 test, 116 assertion PASS**
  - [x] Frontend: [`StudentsPage.tsx`](frontend/src/features/students/pages/StudentsPage.tsx:1), [`GuardiansPage.tsx`](frontend/src/features/students/pages/GuardiansPage.tsx:1)
  - [x] **Detail lengkap ada di bagian A3 di bawah**
- [x] **2C** Mapel per Kelas & Jadwal Pelajaran — SELESAI
  - [x] [`ClassSubjectController`](backend/app/Http/Controllers/Api/ClassSubjectController.php:1) — list / tambah massal / ubah guru / hapus / **salin dari kelas lain**
  - [x] [`ClassSubjectResource`](backend/app/Http/Resources/ClassSubjectResource.php:1) + relasi `SchoolClass::classSubjects()`
  - [x] 5 route `/classes/{class}/subjects*` dengan `permission:academic.*`
  - [x] Validasi: guru wajib `is_teacher`, mapel duplikat dilewati (bukan gagal), mapel kelas lain → 404
  - [x] Frontend: [`ClassSubjectsModal.tsx`](frontend/src/features/academics/components/ClassSubjectsModal.tsx:1) + [`classSubjectsApi.ts`](frontend/src/features/academics/api/classSubjectsApi.ts:1) + kolom & tombol Mapel di [`ClassesPage.tsx`](frontend/src/features/academics/pages/ClassesPage.tsx:1)
  - [x] Feature test [`ClassSubjectTest`](backend/tests/Feature/ClassSubjectTest.php:1) — **12 test, 35 assertion PASS**
  - [x] **Jadwal Pelajaran** — lihat bagian A2 di bawah
- [ ] **2D** Kenaikan kelas, pelanggaran, prestasi, ekstrakurikuler
- [ ] **2E** Opsional: absensi pegawai, beban mengajar, riwayat jabatan

### A2 — Jadwal Pelajaran (SELESAI)

Tahap **A2** dari [`06-BLUEPRINT-AKADEMIK.md`](./06-BLUEPRINT-AKADEMIK.md) §8. Menjawab:
"mapel apa, di kelas mana, oleh guru siapa, pada jam ke berapa" — tanpa bentrok.

**Backend**
- [x] Migrasi [`create_time_slots_and_schedule_changes_tables`](backend/database/migrations/2026_09_15_110001_create_time_slots_and_schedule_changes_tables.php:1)
  - `time_slots` — jam pelajaran per hari (jam ke-1, istirahat, upacara); unik `(day_id, slot_number)`
  - `schedule_changes` — guru pengganti / pindah jam / pembatalan; unik `(schedule_id, date)`
  - `schedules` diperluas: `time_slot_id` (nullOnDelete), `note`, 3 index konflik
- [x] Model [`TimeSlot`](backend/app/Models/TimeSlot.php:1) (termasuk `defaultTemplate()`), [`ScheduleChange`](backend/app/Models/ScheduleChange.php:1), perluasan [`Schedule`](backend/app/Models/Schedule.php:1) (relasi + scope `forSemester` / `overlapping`)
- [x] Resource [`TimeSlotResource`](backend/app/Http/Resources/TimeSlotResource.php:1), [`ScheduleResource`](backend/app/Http/Resources/ScheduleResource.php:1), [`ScheduleChangeResource`](backend/app/Http/Resources/ScheduleChangeResource.php:1)
- [x] [`TimeSlotController`](backend/app/Http/Controllers/Api/TimeSlotController.php:1) — CRUD + `generate` template + validasi tumpang tindih jam
- [x] [`ScheduleController`](backend/app/Http/Controllers/Api/ScheduleController.php:1) — CRUD + `check-conflict` + `grid` + perubahan jadwal
- [x] **Empat deteksi bentrok** (masing-masing punya test tersendiri): kelas · guru · ruang · jam terbalik
- [x] Aturan tambahan: mapel wajib terdaftar di kelas (`class_subjects`), guru wajib `is_teacher`, jam pelajaran harus sesuai hari, guru pengganti tidak boleh sedang mengajar
- [x] 12 route baru (`/time-slots*`, `/schedules*`, `/schedule-changes*`) dengan `permission:academic.*`
- [x] [`TimeSlotSeeder`](backend/database/seeders/TimeSlotSeeder.php:1) — 47 jam pelajaran Senin–Jumat (8 JP/hari, Jumat 6 JP)
- [x] Feature test [`ScheduleTest`](backend/tests/Feature/ScheduleTest.php:1) — **33 test, 113 assertion PASS**

**Frontend**
- [x] [`schedulesApi.ts`](frontend/src/features/academics/api/schedulesApi.ts:1) — jadwal, jam pelajaran, perubahan jadwal
- [x] [`SchedulesPage.tsx`](frontend/src/features/academics/pages/SchedulesPage.tsx:1) — **grid mingguan** dengan sudut pandang per kelas / guru / ruang, penanda perubahan per tanggal
- [x] [`ScheduleFormModal.tsx`](frontend/src/features/academics/components/ScheduleFormModal.tsx:1) — peringatan bentrok langsung sebelum simpan
- [x] [`TimeSlotsModal.tsx`](frontend/src/features/academics/components/TimeSlotsModal.tsx:1) — kelola jam pelajaran + isi dari template
- [x] [`ScheduleChangeModal.tsx`](frontend/src/features/academics/components/ScheduleChangeModal.tsx:1) — guru pengganti / pindah / batal
- [x] Tipe TS jadwal di [`types/index.ts`](frontend/src/types/index.ts:210) + route `/academics/schedules` menggantikan placeholder
- [x] Verifikasi `tsc -b && vite build` sukses (1747 modul)

**Verifikasi end-to-end (HTTP, server dev port 8001)**
- [x] 47 jam pelajaran, 38 slot pelajaran Senin–Jumat, format jam `H:i`
- [x] Jadwal kelas X IPA 1 terisi penuh **38/38** slot tanpa bentrok
- [x] Keempat aturan bentrok ditolak dengan pesan pada field yang tepat
- [x] Grid: 7 hari · 47 slot · 38 entri; filter per guru & per ruang bekerja
- [x] Perubahan jadwal: guru pengganti, pindah, batal, cascade delete — **18/18 pemeriksaan lulus**
- [x] Jadwal pokok tetap utuh saat ada perubahan harian

### A3 — Data Siswa & Wali Murid (SELESAI)

Tahap **A3** dari [`06-BLUEPRINT-AKADEMIK.md`](./06-BLUEPRINT-AKADEMIK.md) §8. Ini tahap
yang membuka blokir terbesar: sebelum A3, tabel `students` **0 baris** sehingga Presensi,
Penilaian, Rapor, dan Kenaikan Kelas tidak bisa diuji sama sekali.

**Backend**
- [x] Migrasi [`create_student_histories_table`](backend/database/migrations/2026_09_15_120001_create_student_histories_table.php:1)
  - `student_histories` — posisi siswa per tahun ajaran (kelas + status + catatan); unik `(student_id, academic_year_id)`
- [x] Model [`StudentHistory`](backend/app/Models/StudentHistory.php:1); perluasan [`Student`](backend/app/Models/Student.php:1) — `histories()`, `user()` (morphOne), `primaryGuardian()`, `classStudentFor()`, scope `active` / `inClass`
- [x] FormRequest [`StudentRequest`](backend/app/Http/Requests/Student/StudentRequest.php:1) + [`GuardianRequest`](backend/app/Http/Requests/Student/GuardianRequest.php:1) dengan `attributes()` Indonesia
- [x] Resource [`StudentResource`](backend/app/Http/Resources/StudentResource.php:1) + [`GuardianResource`](backend/app/Http/Resources/GuardianResource.php:1)
- [x] [`StudentController`](backend/app/Http/Controllers/Api/StudentController.php:1) — CRUD penuh, `toggle-active`, `account`, `guardians`, `histories`
- [x] [`GuardianController`](backend/app/Http/Controllers/Api/GuardianController.php:1) — CRUD mandiri
- [x] **Satu wali utama per siswa** ditegakkan di server: menautkan wali utama otomatis menurunkan yang lama, melepasnya otomatis mempromosikan wali berikutnya
- [x] Wali bisa dipilih dari data yang ada **atau** dibuat sekaligus dari form siswa (inline)
- [x] **Rantai wilayah berjenjang** divalidasi (kota ∈ provinsi, kecamatan ∈ kota, desa ∈ kecamatan) + kode pos terisi otomatis dari desa
- [x] Riwayat kelas menyinkronkan `class_students`; penghapusannya pun simetris
- [x] 15 route baru (`/students*`, `/guardians*`) dengan `permission:student.*`
- [x] **Rapikan `routes/api.php`** — menghapus blok `time-slots`/`schedules` yang terduplikasi (sisa insiden edit paralel di A2)
- [x] **Perbaikan bug** [`bootstrap/app.php`](backend/bootstrap/app.php:1) — guest tanpa header `Accept: application/json` gagal **500** (`Route [login] not defined`) alih-alih **401**, karena default Laravel 11 mengalihkan guest ke route `login` yang tidak ada di SPA ini
- [x] [`StudentSeeder`](backend/database/seeders/StudentSeeder.php:1) — 40 siswa, 20 keluarga (ayah+ibu), 40 riwayat, 40 rombel
- [x] Feature test [`StudentTest`](backend/tests/Feature/StudentTest.php:1) — **37 test, 116 assertion PASS**

**Frontend**
- [x] [`studentsApi.ts`](frontend/src/features/students/api/studentsApi.ts:1) + [`guardiansApi.ts`](frontend/src/features/students/api/guardiansApi.ts:1)
- [x] [`useRegionCascade.ts`](frontend/src/features/students/hooks/useRegionCascade.ts:1) — dropdown wilayah berjenjang (tiap tingkat baru dimuat setelah induknya dipilih)
- [x] [`StudentsPage.tsx`](frontend/src/features/students/pages/StudentsPage.tsx:1) — filter kelas / status / gender / aktif / "belum ada kelas"
- [x] [`StudentFormModal.tsx`](frontend/src/features/students/components/StudentFormModal.tsx:1) — 4 seksi: Identitas, Alamat & Wilayah, Data Tambahan, Wali Murid (baris berulang + radio wali utama)
- [x] [`StudentDetailModal.tsx`](frontend/src/features/students/components/StudentDetailModal.tsx:1) — tab Profil / Wali Murid / Riwayat Kelas, termasuk buat akun portal
- [x] [`GuardiansPage.tsx`](frontend/src/features/students/pages/GuardiansPage.tsx:1) + [`GuardianFormModal.tsx`](frontend/src/features/students/components/GuardianFormModal.tsx:1)
- [x] Tipe TS kesiswaan di [`types/index.ts`](frontend/src/types/index.ts:434); `masterDataApi.all()` kini menerima `filter` (dibutuhkan dropdown berjenjang)
- [x] Route `/students` & `/students/guardians` menggantikan placeholder
- [x] Verifikasi `tsc -b && vite build` sukses (1755 modul)

**Verifikasi end-to-end (HTTP, server dev port 8001)**
- [x] Data dev: 40 siswa · 40 wali · 80 relasi · **tepat 40 bertanda wali utama** · 40 riwayat · 40 rombel
- [x] Filter per kelas mengembalikan 10 siswa; pencarian nama/NIS/NISN bekerja
- [x] Rantai wilayah berjenjang bekerja di keempat tingkat (provinsi → kota → kecamatan → desa)
- [x] NIS/NISN duplikat, dua wali utama, dan rantai wilayah tidak konsisten ditolak dengan pesan pada field yang tepat
- [x] Wali utama: penautan menurunkan yang lama, pelepasan mempromosikan berikutnya
- [x] Riwayat kelas: tahun yang sama diperbarui (tidak dobel), rombel ikut tersinkron
- [x] RBAC nyata: guru bisa melihat, tidak bisa menambah (403)
- [x] **42/42 pemeriksaan lulus**

---

## Blueprint Akademik (A0–A12) — kemajuan

Rencana lengkap ada di [`06-BLUEPRINT-AKADEMIK.md`](./06-BLUEPRINT-AKADEMIK.md) §8.

**Arsitektur kerja paralel** (agar beberapa modul bisa dikerjakan tanpa menyunting file bersama):
- Backend: route per-domain di [`routes/api/*.php`](backend/routes/api/README.md:1), dimuat otomatis oleh `routes/api.php`
  lewat `glob(__DIR__.'/api/*.php')`
- Frontend: route & menu per-domain di [`src/routes/domains/*.tsx`](frontend/src/routes/domains/index.ts:1),
  di-glob otomatis — `routes/index.tsx` dan `constants/menu.ts` tidak perlu disentuh lagi

### A4 — Kalender Akademik & Hari Efektif (SELESAI)
- [x] `academic_events` + `event_types` + `effective_days`
- [x] [`AcademicCalendarController`](backend/app/Http/Controllers/Api/AcademicCalendarController.php:1) — kalender per bulan, generate hari efektif dari rentang semester dikurangi libur
- [x] [`EventTypeSeeder`](backend/database/seeders/EventTypeSeeder.php:1), [`AcademicCalendarSeeder`](backend/database/seeders/AcademicCalendarSeeder.php:1) — 20 event, **2.760 baris hari efektif**
- [x] Feature test **30 test PASS**
- [x] Frontend: [`AcademicCalendarPage.tsx`](frontend/src/features/academic-calendar/pages/AcademicCalendarPage.tsx:1), [`EffectiveDaysPage.tsx`](frontend/src/features/academic-calendar/pages/EffectiveDaysPage.tsx:1) + route `academics/calendar`, `academics/effective-days`

### A5 — Kurikulum (SELESAI)
- [x] `subject_groups`, `programs`, `curriculum_structures`, `subject_prerequisites`
- [x] [`CurriculumController`](backend/app/Http/Controllers/Api/CurriculumController.php:1) — struktur kurikulum + penerapan ke kelas + prasyarat mapel
- [x] Feature test **23 test PASS**
- [x] Frontend: [`ProgramsPage.tsx`](frontend/src/features/curriculum/pages/ProgramsPage.tsx:1), [`SubjectGroupsPage.tsx`](frontend/src/features/curriculum/pages/SubjectGroupsPage.tsx:1), [`CurriculumStructuresPage.tsx`](frontend/src/features/curriculum/pages/CurriculumStructuresPage.tsx:1), [`SubjectPrerequisitesPage.tsx`](frontend/src/features/curriculum/pages/SubjectPrerequisitesPage.tsx:1)

### A6 — Presensi (SELESAI)
**Backend**
- [x] [`StudentAttendance`](backend/app/Models/StudentAttendance.php:1), [`EmployeeAttendance`](backend/app/Models/EmployeeAttendance.php:1), [`AttendanceCorrection`](backend/app/Models/AttendanceCorrection.php:1); [`AttendanceStatus`](backend/app/Models/AttendanceStatus.php:1) diperluas dengan kode `H/A/I/S/T`
- [x] [`AttendanceRecapService`](backend/app/Services/Attendance/AttendanceRecapService.php:1) — **persentase dihitung terhadap hari efektif**, bukan hari kalender; bila hari efektif belum ada, penyebutnya hari tercatat dan ditandai lewat `basis`
- [x] [`StudentAttendanceController`](backend/app/Http/Controllers/Api/StudentAttendanceController.php:1) — CRUD + `sheet` (daftar siswa satu kelas pada satu tanggal) + `bulk` (upsert satu kelas) + rekap per siswa/kelas
- [x] [`EmployeeAttendanceController`](backend/app/Http/Controllers/Api/EmployeeAttendanceController.php:1) — upsert per pegawai per tanggal + rekap periode
- [x] [`AttendanceCorrectionController`](backend/app/Http/Controllers/Api/AttendanceCorrectionController.php:1) — pengajuan → setujui/tolak; presensi tidak diubah di tempat agar tetap bisa diaudit
- [x] 20 route di [`routes/api/attendance.php`](backend/routes/api/attendance.php:1)
- [x] Aturan bisnis: satu presensi per siswa per tanggal per jadwal · tanggal harus di dalam semester · hanya hari efektif · siswa harus anggota aktif kelas · status izin/sakit wajib jenis izin
- [x] Seeder: 400 presensi siswa + 100 presensi pegawai
- [x] Feature test [`AttendanceTest`](backend/tests/Feature/AttendanceTest.php:1) — **39 test, 122 assertion PASS**

**Frontend**
- [x] [`attendanceApi.ts`](frontend/src/features/attendance/api/attendanceApi.ts:1) + [`types/attendance.ts`](frontend/src/types/attendance.ts:1)
- [x] [`StudentAttendancePage.tsx`](frontend/src/features/attendance/pages/StudentAttendancePage.tsx:1) — pilih kelas + semester + tanggal (+ jadwal opsional), tombol status cepat H/A/I/S/T per siswa, "Tandai Semua Hadir", simpan massal
- [x] [`AttendanceRecapPage.tsx`](frontend/src/features/attendance/pages/AttendanceRecapPage.tsx:1) + [`StudentRecapModal.tsx`](frontend/src/features/attendance/components/StudentRecapModal.tsx:1)
- [x] [`AttendanceCorrectionsPage.tsx`](frontend/src/features/attendance/pages/AttendanceCorrectionsPage.tsx:1) + [`CorrectionFormModal.tsx`](frontend/src/features/attendance/components/CorrectionFormModal.tsx:1) + [`RejectCorrectionModal.tsx`](frontend/src/features/attendance/components/RejectCorrectionModal.tsx:1)
- [x] [`EmployeeAttendancePage.tsx`](frontend/src/features/attendance/pages/EmployeeAttendancePage.tsx:1) + [`EmployeeAttendanceFormModal.tsx`](frontend/src/features/attendance/components/EmployeeAttendanceFormModal.tsx:1)
- [x] Route & menu di [`routes/domains/attendance.tsx`](frontend/src/routes/domains/attendance.tsx:1) — `academics/attendance/{students,recap,corrections,employees}`

### A11a — Jurnal Mengajar & RPP (backend selesai, frontend belum)
- [x] `lesson_plans` (DRAFT→SUBMITTED→APPROVED/REJECTED) + `teaching_journals` + `study_groups` + `room_bookings`
- [x] [`LessonPlanController`](backend/app/Http/Controllers/Api/LessonPlanController.php:1), [`TeachingJournalController`](backend/app/Http/Controllers/Api/TeachingJournalController.php:1) — termasuk `today` dan `recap`
- [x] [`LessonPlanSeeder`](backend/database/seeders/LessonPlanSeeder.php:1) 8 RPP, [`TeachingJournalSeeder`](backend/database/seeders/TeachingJournalSeeder.php:1) **304 jurnal**
- [x] Feature test **37 test PASS**
- [ ] **Frontend belum dibuat** (`src/features/teaching/**`, `routes/domains/teaching.tsx`)

### A11b — Tugas (backend selesai, frontend sebagian)
- [x] `assignments` + `assignment_submissions` (dikelola satu migrasi: `150002_create_assignment_tables`)
- [x] [`AssignmentController`](backend/app/Http/Controllers/Api/AssignmentController.php:1) — publish, pengumpulan, penilaian
- [x] [`AssignmentSeeder`](backend/database/seeders/AssignmentSeeder.php:1) 12 tugas + 60 pengumpulan
- [x] [`assignmentsApi.ts`](frontend/src/features/assignments/api/assignmentsApi.ts:1) + [`types/assignments.ts`](frontend/src/types/assignments.ts:1)
- [ ] **Halaman & route frontend belum dibuat**

### Seeding — perbaikan penting
- [x] [`ClassSubjectSeeder`](backend/database/seeders/ClassSubjectSeeder.php:1) — mengisi `class_subjects` yang sebelumnya kosong, sehingga 3 seeder hilir (RPP, jurnal, tugas) tidak lagi dilewati
- [x] [`ScheduleSeeder`](backend/database/seeders/ScheduleSeeder.php:1) — jadwal mingguan bebas bentrok guru (152 baris)
- [x] Perbaikan bug [`ClassroomSeeder`](backend/database/seeders/ClassroomSeeder.php:1) — `education_level_id` selalu `NULL` karena mencari nama `'Kelas 10'`, padahal nama tingkat adalah `"Kelas 1..3"` per jenjang. Kini dicari lewat `jenjang` + `level_number` dan memakai `updateOrCreate` agar data lama ikut diperbaiki
- [x] Duplikasi tabel `assignments`/`assignment_submissions` di dua migrasi dihapus (satu tabel satu pemilik)
- [x] `migrate:fresh --seed` berjalan **tanpa seeder yang dilewati**

### A7 — Penilaian & Grading Engine (SELESAI)
**Backend**
- [x] Engine perhitungan nilai [`GradingService`](backend/app/Services/Assessment/GradingService.php:1) — **tidak menyimpan hasil**; setiap request dihitung ulang: skor mentah → persen (÷ max_score) → aturan remedial → bobot → nilai akhir → huruf/predikat → KKTP. Hanya skor mentah yang disimpan, sehingga perubahan konfigurasi (bobot/KKTP/skala) langsung tercermin dan jejak audit tetap utuh
- [x] [`ScoreRecorder`](backend/app/Services/Assessment/ScoreRecorder.php:1) — satu-satunya pintu masuk tulis skor (input satuan, massal, remedial): cek kunci nilai, keanggotaan kelas, rentang 0..max, alasan wajib bila status ≠ draft, dan setiap perubahan menulis `score_revisions`
- [x] [`AssessmentController`](backend/app/Http/Controllers/Api/AssessmentController.php:1) — CRUD + `sheet` (siswa belum dinilai tetap muncul) + `stats` + alur status `draft → submitted → verified → finalized` (+`reopen` dengan alasan); kelas/mapel/semester tidak boleh diubah setelah ada skor
- [x] [`ScoreController`](backend/app/Http/Controllers/Api/ScoreController.php:1) — input/hapus skor + `bulk` (semua entri divalidasi sebelum satu pun ditulis, di dalam transaksi) + `studentScores` (portal siswa: persen & huruf per penilaian)
- [x] [`RemedialController`](backend/app/Http/Controllers/Api/RemedialController.php:1) — aturan remedial per kelas + kandidat (siswa di bawah KKTP) + nilai perbaikan
- [x] Konfigurasi: [`AssessmentTypeController`](backend/app/Http/Controllers/Api/AssessmentTypeController.php:1), [`GradeScaleController`](backend/app/Http/Controllers/Api/GradeScaleController.php:1) (skala dicek: rentang tidak boleh terbalik/tumpang tindih), [`PassingGradeController`](backend/app/Http/Controllers/Api/PassingGradeController.php:1) + `/resolve` (KKTP umum vs per jenjang), [`AssessmentWeightController`](backend/app/Http/Controllers/Api/AssessmentWeightController.php:1), [`GradingFormulaController`](backend/app/Http/Controllers/Api/GradingFormulaController.php:1)
- [x] `gradebook` + `gradebook/overview` — rekap per kelas/mapel dengan nilai akhir dihitung ulang + distribusi + `needs_attention`
- [x] 28 route di [`routes/api/assessment.php`](backend/routes/api/assessment.php:1) — permission: `assessment.*` (konfigurasi & CRUD penilaian), `academic.grade` (input/hapus/serahkan nilai), `academic.approve` (verifikasi, finalisasi, buka kembali)
- [x] Seeder: 280 penilaian (draft/submitted/verified/finalized), 2.000 skor, 2.000 jejak revisi, 120 remedial
- [x] Feature test [`AssessmentTest`](backend/tests/Feature/AssessmentTest.php:1) — **45 test, 1.236 assertion PASS** (suite penuh: **315 test PASS**)

**Perbaikan bug penting (ditemukan lewat test)**
- [x] [`Score`](backend/app/Models/Score.php:1) kini soft delete — sebelumnya `score_revisions.score_id` bermuatan `cascadeOnDelete`, sehingga **menghapus satu nilai ikut menghapus jejak auditnya** (justru peristiwa yang paling perlu direkam). Migrasi tambahan [`add_soft_deletes_to_scores`](backend/database/migrations/2026_09_19_100001_add_soft_deletes_to_scores.php:1); `ScoreRecorder::put()` memulihkan baris yang sudah dihapus saat nilainya dimasukkan lagi (indeks unik tetap ditempati baris lama)
- [x] Urutan operasi pecahan diperbaiki: `23/40*100` = 57,4999… di floating point → nilai akhir terlecut 57 bukan 57,5. Kini dikali dulu baru dibagi (`score*100/max`) di `GradingService::percent()`, `Score::percentage()`, `ScoreRecorder::convert()`
- [x] Permission hapus skor/remedial dipindah dari `academic.delete` ke `academic.grade` — guru wajib bisa menghapus nilai sendiri, dan penghapusan tercatat di `score_revisions`

**Verifikasi end-to-end (HTTP, server dev port 8001)** — [`/tmp/verify_a7.sh`](/tmp/verify_a7.sh): **38/38 lulus**
- [x] Bentuk respons (filter `filter[status]=…`, `class_id`/`teacher_id` sebagai nama API), sheet (graded+pending=total), stats (distribusi, rata-rata di antara min/maks, pass_rate 0–100), gradebook (nilai akhir dihitung ulang, bukan dibaca dari tabel; rata-rata = mean final_score), overview, remedial + kandidat, konfigurasi (KKTP + sumbernya, bobot + kelengkapan, skala, formula), portal nilai siswa
- [x] Aturan bisnis: mapel yang tidak diampu ditolak 422 · nilai terkunci (finalized) menolak perubahan & penghapusan
- [x] Jalur tulis: input → hapus (jejak revisi tetap) → input lagi **memulihkan baris yang sama**, bukan menduplikasi
- [ ] **Frontend belum dibuat** (`src/features/assessment/**`, `routes/domains/assessment.tsx`)

### Modul Tahfiz / Al-Qur'an Management System — SELESAI (BACKEND + FRONTEND)
Modul mandiri (di luar blueprint A0–A12) dengan 30 tabel, 36 model, 29 controller, 5 service, dan **154 route** di [`routes/api/tahfiz.php`](backend/routes/api/tahfiz.php:1). Suite penuh: **332 test / 1.325 assertion PASS** (sebelum Tahfiz: 315/1.236).

**Fondasi & konfigurasi**
- [x] 3 migrasi: `2026_09_19_10000{2,3,4}` — referensi (program, level, rubrik, taksonomi kesalahan, item mutabaah, 114 surah), organisasi (peserta terpadu, enrollment, halaqah, jadwal, sesi, kehadiran), aktivitas (setoran, murojaah, tilawah, tahsin, tasmi', mutabaah, ujian, sertifikat, progress, intervention, notifikasi)
- [x] Peserta terpadu [`TahfizParticipant`](backend/app/Models/TahfizParticipant.php:1) — `participant_type` ∈ student/employee/guardian/public/alumni/other, tepat satu tautan induk (`student_id`/`employee_id`/`guardian_id`)
- [x] Aturan adalah DATA, bukan kode: program/level/rubrik/taksonomi kesalahan/item mutabaah semuanya CRUD lewat API; seeder `TahfizConfigSeeder` hanya titik mulu
- [x] RBAC granular: `tahfiz.{view,create,update,delete,setoran,approve,assess,report}` — guru catat setoran (`setoran`), penilaian hanya `assess`, penerbitan sertifikat & keputusan ujian hanya `approve`

**Setoran & progress engine**
- [x] [`ProgressService`](backend/app/Services/Tahfiz/ProgressService.php:1) — satu-satunya penulis `tahfiz_participant_progress`; menghitung ulang dari setoran, tidak menyimpan ringkasan usang
- [x] **"Hafal" ≠ "mutqin"** — `accepted_with_revision` dihitung sebagai hafalan tapi bukan mutqin; rentang tumpang tindih digabung (`mergedRanges`) lalu dijumlahkan; status surah `belum → hafal → belum_mutqin → lancar/mutqin` (mutqin butuh skor rata-rata ≥ 90)
- [x] Peta hafalan ke tingkat ayat (`memorizationMap`): 6.236 ayat / 604 halaman, rekap per surah + per juz; kelengkapan juz dihitung dari **semua** surah yang dimulai di juz itu, bukan hanya yang disentuh
- [x] Target: individu > halaqah > program (`resolveTarget`), periode harian/mingguan/bulanan/semester/program
- [x] Taksonomi kesalahan tersimpan per setoran (`mistakes` + label snapshot) agar pola bisa dianalisis

**Ujian, sertifikat, mutabaah**
- [x] [`ExamService`](backend/app/Services/Tahfiz/ExamService.php:1) — butir `acak_ayat` diambil **hanya dari rentang yang sudah dihafal** (berbobot per jumlah ayat); hasil = rata-rata proporsi butir × `max_score`, grade A–E, hasil lulus/remedial/tidak_lulus
- [x] [`TahfizCertificateController`](backend/app/Http/Controllers/Api/TahfizCertificateController.php:1) — nomor & token QR (`Str::random(64)`) dibangkitkan server-side; `verify` **publik tanpa login** dan 404 untuk token tidak dikenal/sudah dicabut; `revoke` wajib alasan
- [x] Mutabaah dengan item konfigurabel (bukan hardcoded), idempoten per (peserta, tanggal) — kirim ulang memperbarui, bukan menduplikasi

**Dashboard, laporan, rapor**
- [x] [`DashboardService`](backend/app/Services/Tahfiz/DashboardService.php:1) — 5 peran: admin (global), guru (halaqah yang diajar), musyrif (halaqah yang dipantau), peserta (diri sendiri), wali
- [x] **Dashboard wali: "Saya sebagai Peserta" vs "Anak Saya"** — kedua tampilan tidak pernah dicampur; `view=child` diverifikasi sebagai anak wali (lewat `student_guardians`), peserta lain ditolak
- [x] [`ReportService`](backend/app/Services/Tahfiz/ReportService.php:1) — laporan individual/halaqah/program/guru + rapor; semua dihitung ulang dari data mentah; rapor memberi predikat + kekuatan/perbaikan
- [x] Intervention: alur `proposed → in_progress → done/cancelled` dengan peta transisi; final tidak bisa diubah

**Feature test** [`TahfizTest`](backend/tests/Feature/TahfizTest.php:1) — **17 test, 89 assertion PASS**: RBAC (guru bisa catat tapi tidak hapus; siswa hanya lihat), alur enrollment, audit setoran (`recorded_by`/`reviewed_by`), hafal vs mutqin, refresh progress, butir ujian dari rentang nyata, hasil ujian, terbit + verify QR publik + cabut, referensi surah (daftar & saring per juz)

**Bug ditemukan & diperbaiki (via subagent audit + smoke test HTTP)**
- [x] `permission:a|b` adalah **ATAU** — `memorization-records` dulu satu grup `view|setoran|assess`, sehingga `tahfiz.view` saja sudah bisa **menulis & menghapus**. Kini baca=`view`, tulis=`setoran`, hapus=`delete` (ditemukan oleh feature test)
- [x] 5 controller `apiResource` tanpa metode `show()` → 5 endpoint 500 (level, mistake-type, mutabaah-item, instructor, schedule)
- [x] `TahfizMutabaah` kehilangan `$table` — migrasi menamainya `tahfiz_mutabaah` (tunggal)
- [x] **Dispatcher controller meneruskan parameter rute secara posisional** — `scoreItem`/`recordResult` hanya menulis model anak, sehingga `Request` yang disisipkan menggeser slot dan `$item` menerima string. Kini semua parameter rute ditulis di tanda tangan
- [x] `verify(string $qrToken)` tidak cocok dengan route tanpa parameter → ArgumentCountError; kini membaca `qr_token` dari body
- [x] `TahfizExamItemResource` di-`use` di controller tapi diimpor ke namespace salah → class not found
- [x] `statusLabel()` return type `string` tapi kolom `status` diisi default DB — model di memori `null` saat baru dibuat → TypeError; kini `refresh()` + fallback `ucfirst`
- [x] `juz_completed` salah menghitung **rekap juz yang disentuh** sebagai juz selesai (Al-Fatiha saja terlihat "juz 1 selesai")
- [x] Jawaban ujian `actual_ayah_end` tidak dibatasi jumlah ayat surah — 999 diterima untuk Al-Fatiha
- [x] `rapor()` pengecekan terbalik (`exists` tidak pernah true) → selalu mengembalikan laporan individual
- [x] Duplicate route `attendances.index`
- [x] `filter[juz]` pada referensi surah — endpoint [`TahfizSurahController`](backend/app/Http/Controllers/Api/TahfizSurahController.php:1) ditambahkan agar form memilih surah dari `quran_surahs` (sumber yang sama dengan progress engine), bukan daftar yang ditulis di klien

**Frontend Tahfiz** (`src/features/tahfiz/**`, [`routes/domains/tahfiz.tsx`](frontend/src/routes/domains/tahfiz.tsx:1)) — **25 halaman + 28 komponen**, `npx tsc -p tsconfig.app.json --noEmit` bersih, `npm run build` sukses
- [x] Fondasi: [`types/tahfiz.ts`](frontend/src/types/tahfiz.ts:1) (seluruh kontrak resource/payload), [`api/tahfizApi.ts`](frontend/src/features/tahfiz/api/tahfizApi.ts:1) (CRUD generik + endpoint khusus), [`lib/labels.ts`](frontend/src/features/tahfiz/lib/labels.ts:1) (peta label & tone), [`hooks/useTahfizOptions.ts`](frontend/src/features/tahfiz/hooks/useTahfizOptions.ts:1)
- [x] **Dashboard per peran** — admin / guru / musyrif / peserta / wali, dengan pemilih **"Saya sebagai Peserta" vs "Anak Saya"** yang menampilkan hanya satu tampilan (tidak pernah dicampur), anak diverifikasi dari `available_children`
- [x] **Peta hafalan hingga level ayat** [`HafalanMap`](frontend/src/features/tahfiz/components/HafalanMap.tsx:1) — satu segmen per ayat (mutqin/hafal/belum), bar proporsional untuk surah > 300 ayat, rekap per juz
- [x] Peserta + detail (profil, progress/peta, setoran, rapor, intervensi), enrollment (+transisi & persetujuan), halaqah (+anggota)
- [x] Setoran (tanpa field status saat mencatat; **tinjau** sebagai satu-satunya pintu status, tenggat revisi wajib untuk `accepted_with_revision`), murojaah, tilawah, tahsin (skor per kriteria rubrik, total dihitung server), tasmi'
- [x] Mutabaah (item dirender dari konfigurasi), kehadiran (+absensi massal, keterangan wajib untuk izin/sakit), jadwal, sesi (+generate dari jadwal), target program/halaqah + target individual
- [x] Ujian + detail (daftar peserta, bangkitkan butir, nilai per butir, catat hasil — grade & hasil dihitung server), sertifikat (terbit/cabut + **verifikasi QR publik**)
- [x] Laporan individual/halaqah/program/guru + **rapor** (tata letak siap cetak), intervensi (transisi mengikuti peta status), notifikasi, pendaftaran mandiri
- [x] **Pendaftaran publik** `/tahfiz/daftar` di luar cangkang aplikasi (tanpa login)
- [x] Grup menu **"Tahfiz / Al-Qur'an"** tersendiri di [`constants/menu.ts`](frontend/src/constants/menu.ts:1) — tidak digabung ke grup "Akademik"
- [x] Pengaturan Tahfiz (tab: program, level, rubrik + kriteria berbobot, taksonomi kesalahan, item mutabaah, instruktur)

**Yang belum**
- [ ] Notifikasi belum dibangkitkan otomatis oleh event (endpoint baca/tandai sudah ada)
- [ ] `npm run lint` belum bisa dijalankan — **eslint belum terpasang** di `frontend/` (berlaku untuk seluruh modul, bukan hanya Tahfiz)

### Modul Library / Perpustakaan — STEP 1 (FONDASI) SELESAI
Modul mandiri dengan 6 tabel, 6 model, 5 service, 6 controller, **24 route** di [`routes/api/library.php`](backend/routes/api/library.php:1), **30 test / 99 assertion PASS** (suite penuh: **362 test / 1.424 assertion PASS**).

**Integrasi dengan arsitektur existing (tanpa duplikasi)**
- [x] **Audit memakai `spatie/laravel-activitylog`** yang sudah terpasang — bukan tabel audit baru. Nama aksi mengikuti konvensi spesifikasi (`LIBRARY_CREATED`, `LIBRARY_UPDATED`, `LIBRARY_STATUS_CHANGED`, `LIBRARY_SETTING_UPDATED`, `LIBRARY_OPERATING_HOURS_UPDATED`, `LIBRARY_LOCATION_CREATED/UPDATED/DELETED`) via [`LogsLibraryActivity`](backend/app/Models/Concerns/LogsLibraryActivity.php:1), dengan nilai lama & baru pada `attribute_changes`
- [x] **RBAC memakai spatie** — permission lama `library.view/create/update/delete` dipertahankan, ditambah granular: `library.settings.*`, `library.location.*`, `library.operating_hours.*`, `library.closure.*`, `library.numbering.*`, `library.dashboard.view`, `library.audit.view`; role baru **`library-manager`**, dan `pustakawan` dibatasi (tanpa konfigurasi/penomoran)
- [x] **Policy** [`LibraryPolicy`](backend/app/Policies/LibraryPolicy.php:1) (didaftarkan eksplisit di [`AppServiceProvider`](backend/app/Providers/AppServiceProvider.php:1)) sebagai pertahanan berlapis di samping middleware `permission:` — `AuthorizesRequests` ditambahkan pada `BaseApiController`
- [x] Institusi memakai `school_id` yang sudah ada (`schools`), bukan `institution_id` baru

**Keputusan sengaja berbeda dari spesifikasi (beserta alasannya)**
- [x] **`library_settings` dibuat** — tabel `settings` existing bersifat **global** (`key` unik tanpa pemilik), sehingga tidak dapat menyatakan konfigurasi per-perpustakaan
- [x] **`library_numbering_configs` dibuat** — `document_sequences` existing juga global dan tanpa padding/nomor awal; memaksakan scope per-perpustakaan berarti mengubah unique constraint tabel bersama. Didukung [`NumberingService`](backend/app/Services/Library/NumberingService.php:1) dengan token `{SEQ} {YYYY} {YY} {MM} {LIBRARY_CODE}`, padding, nomor awal, dan reset tahunan/bulanan
- [x] Auth tetap **Sanctum** (bukan JWT), struktur modul tetap `app/` + `routes/api/library.php` (bukan `Modules/`), ikon **lucide-react** (bukan Ionicons CDN), tanpa layer multi-tenant karena belum ada di sistem ini

**Fondasi yang dibangun**
- [x] `libraries` (kode unik; kode hanya boleh diubah selama **draf**), `library_settings` (key-value bertipe), `library_locations` (hierarki `parent_id`), `library_operating_hours` (beberapa interval per hari), `library_closures`, `library_numbering_configs`
- [x] Siklus status `draft → active → inactive → archived` (arsip final) dengan peta transisi; **aktivasi menuntut konfigurasi minimum** (nama, kode, zona waktu, jam operasional, minimal satu lokasi)
- [x] Soft delete pada perpustakaan + pemeriksaan dependensi (anggota/koleksi/peminjaman/reservasi) yang otomatis berlaku begitu tabel tersebut ada di Step berikutnya
- [x] **Dashboard** hanya menampilkan data yang tersedia: status operasional (dihitung dari jam + penutupan, memakai zona waktu perpustakaan), jam hari ini, persentase kelengkapan, jumlah lokasi, checklist setup, `last_update`; statistik koleksi/anggota = `null` → ditampilkan **"—"** (bukan 0 palsu)
- [x] **Jejak audit baca-saja** (`GET library/audit-logs`) tanpa endpoint tulis, sehingga tidak dapat dimanipulasi lewat API
- [x] Route memakai `scopeBindings()`: lokasi/penutupan milik perpustakaan lain → **404** (ID enumeration tidak memberi akses)
- [x] Seeder [`LibrarySeeder`](backend/database/seeders/LibrarySeeder.php:1) — Perpustakaan Utama `LIB-MAIN` (Senin–Jumat 08:00–16:00, Sabtu 08:00–12:00, Ahad tutup) + hierarki Lantai 1/2 dengan Ruang Baca, Service Desk, Ruang Referensi, Ruang Multimedia; **tanpa** buku/anggota/transaksi (sesuai lingkup Step 1)
- [x] Frontend: 7 halaman (`src/features/library/**` + [`routes/domains/library.tsx`](frontend/src/routes/domains/library.tsx:1)) — Dashboard, Perpustakaan, Konfigurasi (tab), Lokasi (pohon), Jam Operasional (editor mingguan multi-interval), Penutupan, Jejak Audit; menu "Perpustakaan" menggantikan placeholder `/library`

**Bug ditemukan & diperbaiki (lewat test)**
- [x] `spatie/laravel-activitylog` v5 memakai namespace `Models\Concerns\LogsActivity` (bukan `Traits\`) dan tidak lagi menyediakan `tapActivity` → penamaan aksi dipindah ke satu titik di `AppServiceProvider`
- [x] `LogOptions::defaults()` **tidak mencatat apa pun**; `logAll()` wajib dipanggil sebelum `logOnlyDirty()` (tanpa itu tidak ada jejak audit sama sekali)
- [x] **Kunci konfigurasi bertitik vs notasi bersarang Laravel** — aturan seperti `settings.loan.max_items` membuat `data_get()` mencari struktur bersarang, sehingga nilai tidak pernah tervalidasi dan `validated()` membuang induk `settings` (500). Validasi kini dilakukan per-entri terhadap `LibrarySetting::DEFAULTS`
- [x] `scopeBindings()` harus dipanggil **sebelum** `group()`, bukan setelahnya (kalau tidak, tidak berpengaruh)
- [x] Kolom `status` berisi default DB sehingga `null` pada model di memori setelah `create()` → resource menampilkan status kosong; diperbaiki dengan `refresh()`

**Yang belum (Step berikutnya, di luar lingkup Step 1)**
- [ ] Katalog & bibliografi, buku, eksemplar, anggota & keanggotaan, peminjaman/pengembalian, denda, reservasi, pengadaan, stock opname, OPAC, digital library

### Modul Library / Perpustakaan — STEP 2 (MASTER DATA) SELESAI
**18 master data** siap dipakai katalog Step 3: 15 tabel baru + 3 tabel existing yang diperluas, **133 route** di [`routes/api/library.php`](backend/routes/api/library.php:1), **22 test / 118 assertion PASS** (suite penuh: **384 test / 1.542 assertion PASS**).

**Reuse, bukan duplikasi (spesifikasi §4)**
- [x] **Penerbit** memakai tabel existing `publishers` (diperluas: kode, nama singkat, negara, kontak, status, dll.)
- [x] **Kreator** memakai tabel existing `authors` — perannya sebagai *person*: satu orang bisa menjadi Penulis di satu karya dan Penerjemah di karya lain; peran melekat pada relasi karya↔kreator (Step 3), bukan pada tabel orang
- [x] **Kategori** memakai tabel existing `book_categories` (diperluas: `parent_id` hierarkis, `library_id`, deskripsi, urutan, soft delete) — status tetap memakai kolom lama `is_active` agar tidak ada dua penanda status yang bertentangan (trait memetakannya ke `active|inactive`)
- [x] Ketiganya **dikeluarkan dari registry master-data generik** (`config/master_data.php` + `MASTER_RESOURCES` frontend) sehingga hanya ada **satu jalur tulis** dengan permission `library.*`, bukan dua jalur dengan permission berbeda
- [x] Master yang belum ada dibuat baru: `library_collection_types`, `library_material_types`, `library_languages`, `library_countries`, `library_creator_roles`, `library_subjects`, `library_classification_systems`, `library_classifications`, `library_genres`, `library_audience_types`, `library_acquisition_sources`, `library_collection_conditions`, `library_collection_statuses`, `library_series`, `library_tags`
- [x] Tidak ada master yang di-hardcode: jenis koleksi, kategori, klasifikasi (DDC/LCC/Custom adalah data), genre, target pembaca, sumber perolehan, kondisi, status, series, tag — semuanya dapat ditambah admin
- [x] Duplikasi data dicegah secara terstruktur: relasi (publisher/creator/subject/classification/series/tag) sesuai §38, bukan string gabungan

**Arsitektur: satu registry, satu controller generik**
- [x] [`LibraryMasterRegistry`](backend/app/Services/Library/MasterData/LibraryMasterRegistry.php:1) menjadi **sumber kebenaran tunggal** per master: path API, model, scope, hierarki, aturan validasi, kolom cari/filter, relasi, definisi pemakaian, dan **definisi field untuk antarmuka**
- [x] [`LibraryMasterService`](backend/app/Services/Library/MasterData/LibraryMasterService.php:1) memegang aturan relasional: hierarki tanpa siklus, scope, proteksi hapus, transisi status, dan `usage_count` (satu query per definisi pemakaian, bukan per baris)
- [x] Satu `LibraryMasterController` + `LibraryMasterRequest` + `LibraryMasterResource` melayani 18 master; rute tetap **dibangkitkan per master** sehingga setiap master punya middleware permission granular-nya sendiri (`library.collection_type.create`, dst.)
- [x] Hendak menambah master baru? Cukup satu entri registry — model, rute, permission, validasi, dan form di antarmuka ikut menyesuaikan

**Scope (§22)**: master global (`library_id = NULL`, mis. bahasa & negara) vs per-perpustakaan (`library_id = X`). Filter scope terpisah; master global tidak menyimpan `library_id` meski dikirim.
**Hierarki (§24)**: induk tidak boleh dirinya sendiri, tidak boleh keturunannya (siklus ditolak), harus se-scope, dan harus aktif; induk yang masih punya anak tidak dapat dihapus.
**Proteksi hapus (§33)**: master yang sudah dipakai tidak dapat dihapus — pesan menyebutkan jumlah pemakaian dan menyarankan **nonaktifkan**; pemakaian dihitung dari definisi registry dan otomatis berlaku saat tabel Step 3 dibuat.
**Permission (§34)**: `library.master.view` + `library.{key}.{view,create,update,delete}` untuk 18 master, **dibangkitkan dari registry** di seeder; `admin-sekolah` penuh, `library-manager` kelola tanpa hapus, `pustakawan` tambah/ubah tanpa hapus, `kepala-sekolah` hanya lihat.
**Audit (§35)**: `MASTER_CREATED`, `MASTER_UPDATED`, `MASTER_ACTIVATED`, `MASTER_DEACTIVATED` (+ nilai lama/baru) memakai activity-log yang sama dengan Step 1.
**Frontend**: satu halaman master terkelompok [`LibraryMastersPage`](frontend/src/features/library/pages/LibraryMastersPage.tsx:1) (`/library/masters/:master`) — pemilih master bergrup (inti/pendukung), tabel dinamis (kode, nama, induk, status, pemakaian, diperbarui), filter/pencarian/pagination, form **disetir `master-meta`**, aksi massal aktif/nonaktif, dan pesan proteksi hapus apa adanya.

**Bug ditemukan & diperbaiki (lewat test)**
- [x] **SQLite menganggap NULL unik** pada indeks unik `(library_id, code)` → duplikasi kode global lolos dari constraint; keunikan ditegakkan di validasi dengan `whereNull`/`where` sesuai scope
- [x] **Trait property tidak boleh dideklarasikan ulang dengan nilai berbeda** — `book_categories` memakai kolom `is_active`, jadi override dilakukan lewat method `masterStatusAttribute()`, bukan properti
- [x] `Model::only(['status','is_active'])` mengembalikan **kolom yang tidak ada** → insert gagal pada master enum; kini kolom status dipilih eksplisit per tipe
- [x] Model master belum memakai `SoftDeletes` meski kolomnya ada → hapus menjadi permanen; kini trait menyertakannya
- [x] `children_count` & `usage_count` tidak terisi pada respons **satu baris** (hanya pada daftar) → ditambahkan pada show/store/update
- [x] Helper `buildParams` frontend **membuang `sort_by`/`sort_dir`** → kontrol pengurutan tidak sampai ke server; diperbaiki

**Yang belum (Step berikutnya)**
- [ ] Bibliographic catalog & record, buku, eksemplar/copy, barcode & RFID, anggota & keanggotaan, peminjaman/pengembalian, denda, reservasi, OPAC, pengadaan (transaksi), stock opname, digital library — **katalog, eksemplar, anggota & OPAC selesai pada FASE 3 di bawah ini**; sisa transaksi = Fase 4

### Modul Library / Perpustakaan — FASE 3 (CATALOG, COLLECTION & MEMBERSHIP) SELESAI
10 tabel baru + 6 migration total, 8 model, 5 service, 6 controller, **176 route** di [`routes/api/library.php`](backend/routes/api/library.php:1), **42 test / 273 assertion** (4 test file baru); suite penuh: **426 test / 1.749 assertion PASS** (sebelumnya 384/1.542).

**Konsep inti yang ditegakkan**
- [x] **Bibliographic record ≠ eksemplar** — satu record (judul) memiliki 0..N eksemplar (`library_items`), masing-masing dengan barcode, RFID, lokasi, kondisi, dan status sendiri; satu eksemplar hanya menunjuk satu record
- [x] Control number (`BIB-{YYYY}-{SEQ}`) & item code (`LIB-{LIBRARY_CODE}-{SEQ}`) dibangkitkan dari **konfigurasi penomoran Fase 1** (kunci baru `bibliography`, `member_card`, `accession`), bukan hardcode; control number **immutable** setelah dibuat
- [x] **Item code ≠ nomor inventaris** — dua nomor terpisah (`LIB-…` vs `ACQ-…`)
- [x] Identifier banyak per record (`library_bibliography_identifiers`: ISBN-10/13, ISSN, DOI, OCLC, lokal) dengan `normalized_value` untuk pencarian; **ISBN/ISSN duplikat ditolak** pada perpustakaan yang sama, sedangkan kemiripan judul hanya memunculkan **peringatan** (`duplicate-check`, §18)
- [x] Kreator many-to-many **beserta peran & urutan** (`library_bibliography_creators` + `creator_roles`) — satu orang bisa Penulis di satu karya dan Penerjemah di karya lain; hanya satu kreator utama
- [x] Subjek, genre, tag = relasi many-to-many (bukan string gabungan); klasifikasi + call number yang **disusun dari pola configurable** (`catalog.call_number_pattern` dengan token `{CLASS} {AUTHOR} {YEAR} {TITLE}`)
- [x] Slug publik untuk OPAC + `previous_slugs` agar tautan lama tetap ditelusuri (§50)

**Koleksi**
- [x] Bulk pembuatan eksemplar (§28) dengan kode/inventaris/barcode per eksemplar; barcode otomatis mengikuti item code (dapat dimatikan lewat `catalog.barcode_auto`), atau manual; RFID hanya data (`rfid_status`) — **tanpa integrasi perangkat** (§23)
- [x] Barcode & RFID unik; status lifecycle item lengkap; `on_loan`/`reserved` ditandai **dikendalikan sirkulasi** (Fase 4)
- [x] Aksi massal: pindah lokasi, ubah kondisi, ubah status, bangkitkan barcode; hapus ditolak bila sedang dalam sirkulasi

**Keanggotaan & kartu**
- [x] Anggota menautkan orang yang **sudah ada** (siswa/pegawai/wali/pengguna) — identitas ditarik otomatis, biodata tidak diinput ulang (Rule 11); anggota umum mengisi identitas sendiri dan **tidak wajib punya akun** (§37)
- [x] Satu orang hanya boleh punya **satu keanggotaan aktif per perpustakaan** (Rule 7); nomor anggota dari penomoran (`LIB-MBR-…`) dan immutable
- [x] Masa berlaku `valid_from`/`valid_until`, perpanjangan (**mengaktifkan kembali**, kecuali diblokir), penangguhan dengan alasan, dan **evaluasi kedaluwarsa** yang hanya mengubah status — data tidak pernah dihapus (Rule 10)
- [x] Kartu anggota: nomor dari penomoran + **token QR dibuat server-side**; terbit ulang mencabut kartu lama (jejak tetap), pencetakan dihitung (`printed_count`) untuk batch; **"Kartu Saya"** untuk pengguna yang login (§40)

**OPAC (publik, tanpa login)**
- [x] `GET /library/opac`, `/opac/{slug}`, `/opac/{slug}/availability` — dirilis **tanpa autentikasi**; perpustakaan yang mematikan `opac.enabled` tidak muncul
- [x] Hanya record **aktif** yang tampil (Rule 8); **ketersediaan selalu dihitung dari eksemplar** (Rule 9) dengan agregat (satu query per status, bukan per baris)
- [x] **Privasi (§48)**: keluaran OPAC tidak memuat id basis data, harga perolehan, catatan internal, data peminjam, atau riwayat; identifier publik = slug
- [x] Pencarian judul (partial), kreator, penerbit, subjek, klasifikasi, dan identifier ternormalisasi; filter ketersediaan/tahun/kategori; pagination

**Dashboard (§62)** — kini menampilkan angka nyata: jumlah judul, judul aktif, eksemplar, eksemplar tersedia, anggota aktif, keanggotaan kedaluwarsa (+yang belum disinkronkan), dan **penambahan katalog terbaru**; peminjaman/keterlambatan tetap `null` ("—") sampai Fase 4.

**Frontend** — 10 halaman baru (katalog list/form 7 tab/detail + eksemplar, koleksi list/detail, anggota list/detail, Kartu Saya, dan **OPAC publik**) + 4 komponen; total modul Library: **18 halaman, 9 komponen**. Form katalog memuat pemeriksaan duplikat yang tidak memblokir, dan pratinjau OPAC untuk pustakawan.

**Bug ditemukan & diperbaiki (lewat test)**
- [x] **Keunikan bertabrakan dengan penomoran per-perpustakaan** — `control_number`, `item_code`, `member_number`, dan `card_number` awalnya unique global, sehingga perpustakaan kedua tidak bisa membuat nomor `…-001`. Keunikan kini per-perpustakaan (`unique(library_id, …)`), `library_member_cards` mendapat `library_id`
- [x] **SQLite menganggap identifier berkutip yang bukan kolom sebagai string literal** — query `whereNull('library_id')` pada master global (tanpa kolom itu) tidak error, melainkan mengembalikan nol baris; filter scope kini hanya diterapkan pada master yang memang ber-scope
- [x] Meta OPAC ter-*double-wrap* oleh `ResourceCollection` → diganti pola standar (meta pagination dari paginator)
- [x] Pencarian judul awalnya hanya berawalan (`LIKE 'x%'`) sehingga kata di tengah judul tidak ditemukan; kini judul memakai partial match, kolom lain tetap ramah indeks

**Yang belum (saat itu)**
- [x] Peminjaman/pengembalian, denda & pembayaran, reservasi, pengadaan (PO/receiving), stock opname — **selesai pada FASE 4 di bawah ini**; sisa digital lending & analytics lanjutan

### Modul Library / Perpustakaan — FASE 4 + PHASE 5 (SIRKULASI & FINALISASI) SELESAI
> **Catatan penting:** spesifikasi "Phase 5" mengasumsikan Fase 1–4 sudah selesai, padahal **Fase 4 (sirkulasi) belum pernah dikerjakan**. Setelah dikonfirmasi, Fase 4 dibangun lebih dulu, lalu seluruh butir Phase 5 dikerjakan di atasnya.

**Fase 4 — sirkulasi, denda, reservasi, pengadaan, stock opname**
- [x] **Sirkulasi**: `library_loans` + `library_loan_renewals`. Checkout/return/renew dengan policy dari **jenis keanggotaan** (durasi, jumlah maksimum, batas perpanjangan) dan fallback konfigurasi perpustakaan; kode pinjaman dari penomoran (`LOAN-…`). Status eksemplar `on_loan`/`reserved` kini benar-benar dikendalikan modul ini.
- [x] Aturan yang ditegakkan: keanggotaan harus aktif & dalam masa berlaku, eksemplar harus `available`, **batas pinjam** per jenis anggota, **satu eksemplar tidak bisa dipinjam dua kali**, keterlambatan **tidak dapat diperpanjang**, dan perpanjangan **diblokir bila ada antrean reservasi**. Opsi blokir karena denda belum dibayar bersifat konfigurasi (`loan.block_on_outstanding_fine`).
- [x] **Denda & pembayaran**: `library_fines` + `library_fine_payments`. Denda keterlambatan dinilai **idempoten** (unik per pinjaman+tipe) sehingga scheduler boleh berulang; pembayaran boleh sebagian, kelebihan bayar ditolak, dan **pembebasan (waiver) wajib beralasan**. Pembayaran memakai master `payment_methods` yang sudah ada — **tanpa sistem akuntansi baru**.
- [x] **Reservasi**: `library_reservations` dengan antrean berposisi. Pengembalian eksemplar **menahan** salinan untuk pemesan terdepan (`reserved`), pengambilan otomatis menghasilkan peminjaman, dan reservasi yang tidak diambil **kedaluwarsa** lalu diteruskan ke antrean berikutnya.
- [x] **Pengadaan**: `library_acquisition_requests` + `_items` dengan alur `draft → submitted → approved → ordered → received` (peta transisi). Penerimaan membuat bibliographic record (lewat `CatalogService` — tanpa menduplikasi logika katalog) + eksemplar, sehingga alur "Pengadaan → Terima → Eksemplar → Tersedia" utuh. Pemasok memakai master `publishers` (sudah punya kontak), bukan master vendor baru.
- [x] **Stock opname**: `library_inventory_sessions` + `_lines`. Buat sesi → pindai (di luar cakupan = tak terduga) → finalisasi menghitung expected/found/missing/unexpected/damaged dengan **query agregat**, opsi menandai eksemplar hilang sebagai `lost`, dan laporan hasil.
- [x] **Notifikasi & otomatisasi**: tabel `notifications` **standar Laravel** (kanal database) — tidak menduplikasi tabel notifikasi Tahfiz. Command harian `library:daily-maintenance` (terjadwal 20:00) menandai keterlambatan, menilai denda, mengedaluwarsakan reservasi & keanggotaan, lalu mengirim pengingat — semuanya **idempoten** (satu notifikasi per referensi).

**Phase 5 — finalisasi**
- [x] **Dashboard** (§2) kini lengkap: judul, eksemplar, tersedia, dipinjam, terlambat, anggota aktif, reservasi aktif, denda belum dibayar, koleksi baru, **tren 6 bulan** (pinjam/kembali), dan **koleksi terpopuler** — semua dari satu payload agregat.
- [x] **Laporan** (§3) 7 jenis: koleksi (kategori/jenis/lokasi/tahun/status), sirkulasi (termasuk perpanjangan, keterlambatan, terpopuler, anggota teraktif), anggota (per tipe & jenis tautan), denda (dibayar/belum/dibebaskan), pengadaan (per pemasok), inventaris, reservasi — semuanya **dihitung ulang saat diminta**, bukan ringkasan tersimpan.
- [x] **Ekspor CSV** (§4) untuk katalog, eksemplar, anggota, peminjaman, keterlambatan, denda, reservasi, dan pengadaan (ber-BOM agar rapi di Excel) — **ditambah ekspor PDF** untuk laporan (lihat butir Barcode/QR + PDF di bawah). Format **XLSX** belum ada.
- [x] **Impor CSV** (§5) katalog/eksemplar/anggota dengan **template unduhan**, **pratinjau** (total/valid/invalid/duplikat + **error per baris**), dan commit yang **hanya menyimpan baris valid** dalam satu transaksi.
- [x] **Barcode/QR asli + PDF** (§6): memakai pustaka — **QR** `bacon/bacon-qr-code` (server) & `qrcode` (klien), **barcode Code 128** `picqer/php-barcode-generator` (server) & `jsbarcode` (klien), **PDF** `barryvdh/laravel-dompdf`. Tersedia: **kartu anggota PDF** (QR berisi token kartu), **label eksemplar PDF** (barcode + QR per eksemplar), dan **laporan PDF** untuk 7 jenis laporan. Pratinjau di layar (Kartu Saya, panel kartu, halaman Cetak Label) kini memakai QR/barcode yang benar-benar dapat dipindai — bukan lagi pola pratinjau.
- [x] **Layar cepat** (§7 & §8): Checkout & Pengembalian berbasis pindai (input menerima masukan scanner sebagai keyboard, Enter memproses), plus `GET circulation/lookup` yang mengenali barcode/kode eksemplar/nomor inventaris/nomor anggota/kartu QR, dan ringkasan anggota (pinjaman aktif, terlambat, denda, reservasi).
- [x] **Bulk action & permintaan konfirmasi** (§16): aktivasi/nonaktivasi katalog & eksemplar, pindah lokasi/kondisi, bangkitkan barcode, aktivasi & perpanjangan anggota, batch kartu, dan status massal.

**Frontend Fase 4 + Phase 5** — 14 halaman baru: Checkout Cepat, Pengembalian, Peminjaman, Keterlambatan, Reservasi, Denda, Pengadaan (+detail), Stock Opname (+detail), Cetak Label, Laporan (7 tab + ekspor CSV per tab), Impor Data (pratinjau + error per baris), Notifikasi; plus 4 komponen (LoanDetailModal, PayFineModal, AcquisitionFormModal, ReservationCreateModal) dan **Dashboard diperluas** (kartu Sirkulasi: pinjam/terlambat/denda/reservasi, grafik batang tren 6 bulan, koleksi populer). Total modul Library: **32 halaman, 13 komponen**. `npx tsc --noEmit` bersih, `npm run build` sukses.

**Verifikasi**
- **28 test baru** (3 file: [`LibraryCirculationTest`](backend/tests/Feature/LibraryCirculationTest.php:1) 16 test — 5 skenario §22 + validasi/otorisasi; [`LibraryReportTest`](backend/tests/Feature/LibraryReportTest.php:1) 7 test — laporan/ekspor/impor/notifikasi/otomatisasi; [`LibraryDocumentTest`](backend/tests/Feature/LibraryDocumentTest.php:1) 5 test — QR/barcode PNG, kartu PDF, label PDF, 7 laporan PDF, otorisasi). Suite penuh: **454 test / 1.994 assertion PASS** (sebelumnya 426/1.749).
- Permukaan tambahan: 3 route PDF (`members/{member}/card/pdf`, `labels/pdf`, `reports/{type}/pdf`).
- Permukaan modul: **40 tabel `library_*`** + tabel `notifications`, **230 route**, **134 permission** `library.*`, 3 service Fase 4, 4 service Phase 5, 1 service dokumen.
- Smoke test HTTP: statistik sirkulasi (tren 6 bulan), `lookup` kode tak dikenal, **7 endpoint laporan**, ekspor CSV (header + BOM), template impor, jumlah notifikasi, `library:daily-maintenance`, dan `schedule:list` (harian 20:00).

**Dependensi yang ditambahkan (PHP + npm)**
- PHP: `barryvdh/laravel-dompdf` (PDF), `bacon/bacon-qr-code` (QR), `picqer/php-barcode-generator` (barcode Code 128).
- npm: `qrcode` + `@types/qrcode`, `jsbarcode` + `@types/jsbarcode`.
- PDF memakai **font core Helvetica** agar ringan: label ±7 KB dan laporan ±3 KB (sebelumnya ±880 KB saat font DejaVu disematkan). `DejaVu Sans` tetap menjadi fallback bila ada glyph non-Latin.

**Yang belum / keterbatasan jujur**
- [ ] **XLSX/PDF ekspor tabular**: PDF kini tersedia untuk **kartu, label, dan 7 laporan**; ekspor CSV tetap untuk data mentah (katalog, eksemplar, anggota, peminjaman, keterlambatan, denda, reservasi, pengadaan). Format **XLSX** belum ada (butuh paket tambahan).
- [ ] **Import Excel** (.xlsx) — masih CSV.
- [ ] Notifikasi luar aplikasi (email/WhatsApp/push) belum ada; kanal yang dipakai adalah `database` (in-app).
- [ ] `npm run lint` masih tidak dapat dijalankan (eslint belum terpasang — berlaku seluruh repo).
- [ ] **`npm audit` melaporkan 2 kerentanan moderate pada `react-router-dom`** (open redirect & constructor injection) — **pre-existing**, bukan dari dependensi baru; perbaikannya butuh upgrade mayor ke react-router 7 sehingga tidak dilakukan tanpa persetujuan.

### Kesiapan UI end-to-end — 2026-09-28

Rute yang sebelumnya menampilkan `ModulePlaceholderPage` sekarang memakai halaman operasional dan API nyata. Cakupan yang ditutup pada batch ini:

- [x] **Tagihan & invoice** — buat billing siswa dengan rincian, terbitkan invoice, cari/filter daftar tagihan dan sisa piutang.
- [x] **Pembayaran** — catat tunai atau transfer, tandai transfer menunggu verifikasi, verifikasi/tolak, dan perbarui piutang melalui service ledger finance.
- [x] **Tabungan / wallet** — buka wallet siswa, lihat saldo/buku besar, dan verifikasi antrean top up. Saldo tetap hanya bergerak lewat ledger.
- [x] **Absensi pegawai** — alias menu Kepegawaian memakai halaman presensi/rekap yang sudah ada; permission UI dan endpoint mendukung `employee.*` tanpa memutus rute akademik lama.
- [x] **Penilaian inti (A7)** — CRUD penilaian, input nilai kelas secara bulk, dan alur ajukan → verifikasi → finalisasi/lock → buka revisi.
- [x] **Rapor inti (A9)** — generate snapshot dari nilai yang sudah final + rekap kehadiran, catatan wali kelas, review/persetujuan/publikasi, dan PDF per siswa. Laporan yang terbit tidak dapat dibuat ulang.
- [x] **Inventaris sekolah** — CRUD aset/persediaan, saldo awal, mutasi masuk/keluar beralasan, riwayat audit, dan filter stok menipis; tidak mengubah stock opname koleksi perpustakaan.
- [x] **Pengaturan umum** — baca/tulis profil sekolah dengan validasi identitas, kontak, dan jenjang.

**Verifikasi batch:** frontend `npm run build` sukses; backend 22 tes terkait termasuk RBAC (89 assertion), ditambah regression suite Assessment (45/320), Attendance (56/218), serta Finance Billing/Payment (37/240) lulus. Browser smoke pada alur tagihan → invoice → pembayaran tunai berhasil; wallet, inventaris, rapor, pengaturan, dan alias absensi termuat. Vite masih memberi peringatan chunk JavaScript >500 kB; pemecahan bundle belum dikerjakan.

**Batas cakupan yang masih tersisa:** halaman konfigurasi bobot/skala/KKTP dan remedial A7; transkrip/dokumen akademik A9; modul ujian A8, kenaikan kelas A10, analitik A12; operasi finance lain (pengeluaran, pembelian, closing, rekonsiliasi); top up dari portal pengguna tetap lewat alur portal terpisah.

### Tahap berikutnya
- [x] ~~**Modul Tahfiz / Al-Quran Management System**~~ — **selesai** (backend + frontend), lihat bagian di atas
- [x] ~~**Modul Library / Perpustakaan — Step 1 (Fondasi)**~~ — **selesai**
- [x] ~~**Modul Library / Perpustakaan — Step 2 (Master Data)**~~ — **selesai**
- [x] ~~**Modul Library / Perpustakaan — Fase 3 (Katalog, Koleksi & Keanggotaan + OPAC)**~~ — **selesai**
- [x] ~~**Modul Library / Perpustakaan — Fase 4 + Phase 5 (Sirkulasi, Laporan, Finalisasi)**~~ — **selesai** (backend + frontend); Phase 5 adalah fase final sesuai spesifikasi
- [x] A7 — frontend inti penilaian & input nilai; konfigurasi bobot/KKTP/remedial masih menyusul
- [ ] **A8** Bank Soal & Ujian
- [x] A9 — generate, review, publikasi, dan PDF rapor; transkrip/dokumen masih menyusul
- [ ] **A10** Kenaikan Kelas & Kelulusan
- [ ] **A12** Analitik, Alur Persetujuan, Ekspor
- [ ] Upload foto siswa (kolom `students.photo_path` sudah ada)
- [ ] Frontend A11a & A11b

### Fondasi Akademik (Seeder Baru)
Sebelum 2C dikerjakan, ditemukan bahwa sebagian besar fondasi akademik **belum punya data**.
Seeder berikut dibuat agar modul di atasnya bisa diuji:

- [x] [`RegionSeeder`](backend/database/seeders/RegionSeeder.php:1) — 5 provinsi, 14 kabupaten/kota, 24 kecamatan, 18 kelurahan *(data contoh — produksi impor dataset resmi BPS)*
- [x] [`SubjectSeeder`](backend/database/seeders/SubjectSeeder.php:1) — 23 mata pelajaran SMA Kurikulum Merdeka (Wajib / Pilihan / Muatan Lokal)
- [x] [`RoomSeeder`](backend/database/seeders/RoomSeeder.php:1) — 14 ruangan (kelas, lab, perpustakaan, kantor)
- [x] [`AchievementTypeSeeder`](backend/database/seeders/AchievementTypeSeeder.php:1) — 12 jenis prestasi
- [x] Data dev terisi: 4 kelas × 10 mapel wajib = **40 baris `class_subjects`** dengan guru pengampu
- [x] Data dev terisi: **47 jam pelajaran** + **39 baris `schedules`** (X IPA 1 penuh 38 jam/pekan, X IPA 2 1 jam)

### Perbaikan Teknis Pendukung
- [x] Whitelist `filter` ditegakkan di [`MasterDataController`](backend/app/Http/Controllers/Api/MasterDataController.php:64) — sebelumnya kolom apa pun dari query string bisa dipakai memfilter
- [x] Database dev dipindah ke SQLite + `database.sqlite` masuk `.gitignore`
- [x] `users` dibangun ulang dari migrasi terkini (file SQLite lama masih memakai skema usang tanpa `deleted_at`)
- [x] `SchoolClassResource` kini menyertakan `class_subjects_count`

### Sisa Blocker Fase 2
- ~~Tabel `students` belum punya seeder~~ → **selesai di A3** (40 siswa + 40 wali + 40 riwayat)
- ~~`StudentController` masih read-only~~ → **selesai di A3** (CRUD penuh + wali + riwayat)
- 6 tabel baru dibutuhkan (4 kesiswaan + 2 kepegawaian) — lihat §5 `05-PLAN-FASE-2.md`
- Upload foto siswa belum dibuat (kolom `students.photo_path` sudah ada)
- Rencana modul Akademik lengkap (8 domain, 35 area, 5 engine) ada di [`06-BLUEPRINT-AKADEMIK.md`](./06-BLUEPRINT-AKADEMIK.md)
- Tahap berikutnya menurut blueprint: **Modul Tahfiz / Al-Quran Management System** (spesifikasi 50 bagian, modul mandiri di luar blueprint A0–A12) — dilanjutkan dengan **A8** (Bank Soal/Ujian), A9 (Rapor/Transkrip), A10 (Kenaikan/Kelulusan), A12 (Analitik/Ekspor)
- **Catatan verifikasi:** `npx tsc --noEmit` di root `frontend/` **tidak memeriksa apa pun** (tsconfig hanya berisi `references`). Pakai `npx tsc -p tsconfig.app.json --noEmit` atau `npm run build` (yang memakai `tsc -b`)

### Risiko yang Belum Ditangani
- **Repositori belum di-`git init`** — tidak ada baseline commit, sehingga perubahan besar tidak bisa ditelusuri atau dibatalkan. Ini risiko tertinggi saat ini.
- ~~`.env.example` masih bawaan Laravel~~ → sudah memuat blok modul (`MIDTRANS_*` untuk pembayaran PPDB, lengkap dengan peringatan `ALLOW_UNSIGNED_NOTIFICATIONS` hanya untuk uji lokal)
- Belum ada pagination server-side untuk relasi anak (wali & riwayat dimuat sekaligus di detail siswa) — aman untuk skala sekolah, perlu ditinjau bila satu siswa punya riwayat lintas bertahun-tahun

## Fase 3 — Keuangan & Penilaian
- [x] Billing/invoice siswa, pembayaran & verifikasi, daftar wallet/saldo dan top up pending (alur inti frontend + API)
- [x] CRUD penilaian, input nilai massal, transisi submit/verifikasi/finalisasi dan rapor semester/PDF
- [ ] Konfigurasi bobot/skala/KKTP/remedial A7, transkrip/dokumen A9, serta modul A8/A10/A12

## Fase 4 — Pendukung
- [x] Perpustakaan (lihat penyelesaian modul Library di atas)
- [x] Inventaris sekolah dengan mutasi stok yang dapat diaudit
- [ ] Laporan dan ekspor lintas modul

## Fase 5 — Portal & PPDB
- [ ] Portal siswa
- [x] Portal wali murid — dipakai lintas modul: PPDB memakai akun `guardian` yang sama, tanpa sistem auth kedua
- [x] **PPDB online** — lihat "Modul PPDB" di bawah

### Modul PPDB / Penerimaan Peserta Didik Baru — SELESAI (BACKEND + FRONTEND + AUDIT FINAL, 2026-09-21)

**Skala terverifikasi:** 125 rute API (`api/v1/ppdb/*`, 4 publik + 121 terautentikasi dengan 19 permission berbeda), 31 halaman React (5 publik, 8 portal orang tua, 11 operasi panitia, 7 konfigurasi), 8 file feature test PPDB.

- [x] **Siklus penuh berjalan end-to-end**, dibuktikan tes HTTP-driven (bukan manipulasi DB): publik → daftar akun → buat pendaftaran → formulir dinamis → dokumen berversi → verifikasi → need_revision → bayar → seleksi → pengumuman → sanggah → daftar ulang → konversi jadi siswa → laporan
- [x] **Diklik sungguhan di browser** (bukan hanya phpunit): daftar mandiri → token → portal → tambah anak → buat draf pendaftaran (`PPDB-2026-000002` dari `PpdbNumberingService`) → autosave draf → wizard 8 langkah → unggah berkas (file benar-benar muncul di disk, versi 1) → halaman tagihan (`INV-2026-000001`, Rp 225.000 = 150.000 + 75.000 sesuai perkiraan biaya program) → antrean verifikasi panitia membaca berkas yang sama. Run ini menemukan **CRITICAL #82** yang tidak mungkin terlihat dari tes: `EnsureFrontendRequestsAreStateful` di grup `api` membuat SETIAP mutasi dari SPA (Origin `localhost:5173`) mati 419 CSRF, sementara 542 tes tetap hijau
- [x] **Satu akun orang tua, banyak anak** (`skenario_b_…tanpa_menambah_user`); tidak ada user baru per anak, dan `Applicant` tidak pernah otomatis jadi `Student`
- [x] **Keputusan pemilik yang sudah ditegakkan**
  - Ekspor = **CSV UTF-8 ber-BOM** (langsung rapi di Excel) + penguatan anti formula injection; tidak ada dependency Excel baru — [`PpdbReportController`](../backend/app/Http/Controllers/Api/Ppdb/PpdbReportController.php:61)
  - **Sanggah tidak pernah meloloskan sendiri**: keputusan hanya membuka berkas ke panitia, hasil baru ditetapkan eksplisit lewat alur seleksi, semuanya masuk riwayat — `sanggah_diterima_hanya_membuka_keputusan_panitia`
  - **Bobot tahap wajib tepat 100%**, tanpa normalisasi otomatis; grup yang belum pas menolak dihitung skor gabungannya — `bobot_tahap_ditolak_sampai_totalnya_tepat_seratus`, `skor_gabungan_menolak_dihitung_dari_bobot_yang_belum_seratus`
  - **Lulus di atas kuota** butuh hak `ppdb.registration.override_quota` **dan** `quota_override_reason`; tanpa alasan 422, tanpa hak 403 — `PpdbAnnouncementService::assertQuotaAvailable()`, panitia-ppdb sengaja tidak memegang hak ini
  - **Midtrans** siap sandbox (`MIDTRANS_*` terdokumentasi di `.env.example`); tanpa credential produksi portal tetap melayani transfer manual, callback publik tetap menolak notifikasi tanpa tanda tangan SHA-512
- [x] **Sistem existing dipakai ulang, tidak diduplikasi**: user/akun guardian + Sanctum, spatie permission, `Student` (dengan pencocokan NISN dan pemulihan baris soft-delete alih-alih duplikat), penomoran via `PpdbNumberingService`, notifikasi in-app, audit `activity('ppdb')`/`LogsPpdbActivity`, dompdf untuk PDF
- [x] **Berkas benar-benar tertulis** ke disk `public` dan versi lama tidak pernah ditimpa (`Storage::disk('public')->exists(...)` ditegakkan di tes)
- [x] **Siklus hidup tagihan** lengkap: `unpaid/pending/paid/failed/expired/cancelled/refunded`, perintah `ppdb:expire-invoices` terjadwal harian, `cancel`/`refund` beralasan wajib
- [x] **Privasi**: hasil & berkas hanya terbaca pemiliknya (`orang_tua_hanya_melihat_pendaftaran_miliknya`, `kotak_masuk_notifikasi_terisolasi_per_pengguna`); panitia tidak butuh `master-data.view` untuk mengisi dropdown lewat `GET /ppdb/lookups`
- [x] **Tidak ada endpoint utama yang 500**: sapuan smoke 9 tes menempuh ±176 pemanggilan rute; setiap URL yang dipakai klien juga dicocokkan dengan `route:list` (rute yang hilang = 404, bukan 500, jadi tidak akan tertangkap sapuan status)
- [x] Perbaikan kontrak klien↔server sesi terakhir: unggah bukti bayar orang tua (404 senyap), detail calon untuk admin (rutenya tidak pernah ada), `registrations`/`parent_name` hilang di daftar calon, kunci `links.*` yang menunjuk rute fiktif, `education_level` di halaman publik, tombol kirim wizard terkunci oleh field `file`
- [x] **Verifikasi FINAL**: `vendor/bin/phpunit` penuh hijau, PPDB 88 tes / 2298 assertion, `npx tsc -p tsconfig.app.json --noEmit` 0 error, `npm run build` sukses
- [ ] Sisa keputusan pemilik (bukan bug): daftar ulang hanya dapat dibuka/diselesaikan petugas (orang tua belum bisa swastatus), `buildComposite()` mengekstrapolasi dari bobot yang *sudah* dinilai untuk calon yang belum lengkap dinilai, portal tidak punya endpoint pengumuman (dashboard membaca kotak masuk notifikasi), `eslint` belum terkonfigurasi di repo ini

## Modul CBT — Ujian Berbasis Komputer (selesai, 2026-09-30)

- [x] Bank soal 8 tipe + revisi, kisi acak, sesi ber-token, ruang ujian siswa, timer server
- [x] Insiden integritas, penilaian esai, analisis butir, ekspor CSV, dorong nilai ke penilaian
- [x] UI: dasbor, bank soal, kisi, sesi, monitor, ujian saya, pemain, antrean esai
- [x] Feature test `CbtTest` (7 tes) hijau
- [ ] Belum: penguncian OS lab dan proktor video (butuh klien terpisah)

## Fase 6 — Hardening & Deploy
- [ ] Testing menyeluruh
- [ ] Optimasi performa
- [ ] Dokumentasi API
- [ ] Deployment produksi

---

## Fase LMS — Modul Learning Management System (Phase 1)

Modul LMS dibangun di atas ERP/SIAKAD yang sudah ada. Seluruh kode modul berada
di [`backend/app/Modules/LMS`](backend/app/Modules/LMS/1) (Domain, Application,
Infrastructure, Http, Models, Database, Routes, Providers) dengan dokumentasi
lengkap di [`docs/07-MODUL-LMS.md`](docs/07-MODUL-LMS.md).

### Arsitektur & Mode
- [x] Struktur modular `app/Modules/LMS/**` + `config/lms.php`
- [x] `LmsServiceProvider` mendaftarkan konfigurasi, migrasi, route (`/api/v1/lms/*`),
      middleware (`lms.actor`, `lms.permission`), dan binding kontrak domain
- [x] `LMS_STANDALONE=false` (default) → memakai entity ERP: User, Student, Employee,
      SchoolClass, Subject, AcademicYear, Semester, Program — tanpa duplikasi
- [x] `LMS_STANDALONE=true` → identitas mandiri (`lms_users`, `lms_learners`,
      `lms_guardians`) dengan alur belajar yang sama
- [x] Kontrak abstraksi: `UserProviderInterface`, `StudentProviderInterface`,
      `TeacherProviderInterface`, `ClassProviderInterface`,
      `AcademicCourseProviderInterface`, `AcademicActivityFeedInterface`
- [x] DTO netral: `LmsActor`, `LmsLearner`, `LmsTeacher`, `AccessDecision`

### Database (14 tabel inti + 3 tabel standalone)
- [x] `lms_categories`, `lms_courses`, `lms_course_teachers`, `lms_course_sections`,
      `lms_learning_items`, `lms_resources`, `lms_book_chapters`
- [x] `lms_enrollments`, `lms_groups`, `lms_group_members`, `lms_course_announcements`,
      `lms_activity_completions`, `lms_course_progress`, `lms_bookmarks`, `lms_learning_views`
- [x] `lms_users`, `lms_learners`, `lms_guardians` (hanya Standalone Mode)

### Fitur
- [x] Kategori bertingkat (Category → Subcategory → Course) + pemilihan urutan
- [x] Course: DRAFT/PUBLISHED/ARCHIVED, PUBLIC/PRIVATE/INSTITUTION/ENROLLED_ONLY,
      tipe ACADEMIC/NON_ACADEMIC/TRAINING/WORKSHOP/GENERAL, kode otomatis pola SIAKAD
- [x] Wizard pembuatan course (identitas → kaitan SIAKAD → pembelajaran → publikasi)
- [x] Section & learning item (RESOURCE/ACTIVITY) dengan urutan, duplikasi, sembunyikan
- [x] Resource: PAGE (HTML tersanitasi), FILE (disk Laravel + unduhan ber-otorisasi),
      URL, VIDEO (embed YouTube/Vimeo/Drive), AUDIO, BOOK (bab), FOLDER, TEXT
- [x] Enrollment: manual, massal, mandiri (non-akademik), sinkronisasi SIAKAD,
      status PENDING/ACTIVE/SUSPENDED/COMPLETED/CANCELLED, kuota peserta
- [x] Kelompok peserta + kapasitas
- [x] Availability: jendela waktu, prasyarat item, nilai minimum, penyelesaian syarat
- [x] Completion: MANUAL/VIEW/PASS/SUBMIT/COMPLETE_ACTIVITY + progress
      `completed / required × 100` dan "Continue Learning"
- [x] Pengumuman course + notifikasi database ke peserta
- [x] Kalender belajar (hari ini / minggu ini / mendatang)
- [x] Dashboard per peran: siswa, guru, wali (portal anak), admin (analitik)
- [x] LMS publik `/learn` (home, katalog, detail course by slug, guru) — hanya course PUBLIC
- [x] Pencarian global + saran pencarian, bookmark course & materi
- [x] Permission granular `lms.*` + audit `lms` (spatie/activitylog)
- [x] Pengaturan LMS tersimpan di tabel `settings` (berlaku tanpa deploy)
- [x] Frontend `src/features/lms/**` + `routes/domains/lms.tsx` (menu + rute)
- [x] Responsif: desktop 2 kolom, tablet sidebar dapat dilipat, mobile bilah progress melekat
- [x] Data contoh `LmsDemoSeeder` (kategori, course publik, course akademik tersinkron)

### Pengujian
- [x] `tests/Feature/Lms/` — 10 berkas, 66 kasus, 260+ assertion (course, enrollment,
      access, content, completion, parent, integration, dashboard, permission, standalone)

### Lanjutan (phase berikutnya)
- [ ] Assignment & submission LMS (pengumpulan, penilaian, umpan balik)
- [ ] Quiz + Question Bank (bank soal, bank kategori, penilaian otomatis)
- [ ] Forum diskusi, Lesson, Choice, Survey, Workshop
- [ ] Gradebook LMS, sertifikat, attendance LMS

---

## Fase LMS Phase 2 — Assignment, Quiz, Question Bank, Forum & Learning Activities

Melanjutkan Phase 1 **tanpa membuat ulang** course, section, learning item,
enrollment, kategori, penyelesaian, progress, atau sistem berkas. Setiap
aktivitas tetap menempel pada `lms_learning_items`, sehingga otomatis mengikuti
visibilitas, prasyarat, kelompok, penyelesaian, dan progress yang sudah ada.
Dokumentasi: [`docs/08-MODUL-LMS-PHASE-2.md`](docs/08-MODUL-LMS-PHASE-2.md).

### Aktivitas & Tugas
- [x] `lms_activities` + konfigurator per tipe (`ActivityConfiguratorInterface`)
- [x] Wizard aktivitas 4 langkah (tipe → dasar → jadwal → penyelesaian)
- [x] Status DRAFT/PUBLISHED/ARCHIVED, duplikasi tanpa menyalin riwayat
- [x] Assignment: bentuk pengumpulan, nilai maksimum/lulus, percobaan, penalti
- [x] Submission: draf, kirim, kirim ulang dengan attempt baru (riwayat utuh)
- [x] Berkas submission + unduhan ber-otorisasi, batas tipe & ukuran
- [x] Penilaian: nilai asli vs nilai setelah penalti, umpan balik teks/berkas
- [x] Penilaian massal, pengembalian & penolakan submission
- [x] Statistik tugas (terkirim, terlambat, dinilai, rata-rata, tingkat lulus)

### Kuis & Bank Soal
- [x] Bank soal + kategori bertingkat, soal reusable lintas kuis
- [x] 7 tipe soal: PG tunggal/ganda, benar-salah, singkat, numerik, esai, menjodohkan
- [x] Versioning soal + snapshot per attempt (hasil lama tetap sah)
- [x] Pemilihan soal manual maupun acak per kategori
- [x] Percobaan kuis dengan timer server-side + auto submit & auto grade
- [x] Penilaian otomatis, penilaian manual esai, normalisasi nilai ke maximum_grade
- [x] Strategi nilai lintas percobaan (tertinggi/terakhir/rata-rata/pertama)
- [x] Review hasil mengikuti konfigurasi; kunci tidak pernah bocor sebelum selesai
- [x] Statistik kuis & per soal

### Forum, Lesson, Choice, Survey
- [x] Forum: diskusi, balasan bersarang dengan batas kedalaman, pin, kunci
- [x] Moderasi (setujui/sembunyikan/hapus) + antrean moderasi & notifikasi
- [x] Lesson: halaman materi, halaman pertanyaan bercabang, progres per halaman
- [x] Choice: polling tunggal/ganda, batas pilihan, visibilitas hasil, anonim
- [x] Survey: rating/pilihan/ya-tidak/teks, mode anonim, rekap hasil

### Integrasi
- [x] Penyelesaian aktivitas masuk ke mesin penyelesaian Phase 1 (§40)
- [x] Ketersediaan aktivitas: jadwal, prasyarat item, prasyarat aktivitas, nilai minimum, kelompok
- [x] Navigasi Previous/Next antar aktivitas
- [x] Catatan nilai aktivitas + `GradeProviderInterface` siap untuk Gradebook Phase 3
- [x] Sinkronisasi nilai ke SIAKAD hanya eksplisit (tidak pernah otomatis)
- [x] Dasbor siswa (segera dikerjakan, baru dinilai) & guru (menunggu penilaian)
- [x] Portal wali: status aktivitas anak (read-only)
- [x] Frontend: player 6 tipe aktivitas, halaman kelola guru, bank soal, wizard
- [x] Permission granular Phase 2 (72 izin `lms.*` total)

### Pengujian
- [x] 7 berkas tes Phase 2: assignment, quiz, forum, lesson, choice/survey, security, alur menyeluruh
- [x] Dokumentasi `docs/08-MODUL-LMS-PHASE-2.md`

### Lanjutan (phase berikutnya)
- [ ] Gradebook LMS penuh (bobot kategori, ekspor, sinkronisasi terkendali)
- [ ] Workshop, bank soal institusi dengan workflow persetujuan
- [ ] Analytics pembelajaran & laporan per siswa/course

---

## Fase LMS Phase 3 — Gradebook, Assessment, Rubric, Competency & Learning Outcomes

Melanjutkan Phase 1–2 **tanpa membuat ulang** course, aktivitas, submission,
percobaan kuis, penyelesaian, progress, atau identitas pengguna. Dokumentasi:
[`docs/09-MODUL-LMS-PHASE-3.md`](09-MODUL-LMS-PHASE-3.md).

Prinsip yang ditegakkan: satu penulis hasil penilaian
(`AssessmentResultService`), satu mesin perhitungan
(`GradeCalculationService`/`FinalGradeService`), satu lapisan evidence
(`lms_activity_grades` → `lms_assessment_results.activity_grade_id`), dan SIAKAD
tetap sumber resmi nilai akademik.

### Gradebook
- [x] `lms_gradebooks` (satu per course) + status DRAFT/ACTIVE/LOCKED/FROZEN/ARCHIVED
- [x] `lms_grade_categories` bersarang dengan bobot, agregasi, drop lowest, extra credit
- [x] `lms_grade_items` dari ASSIGNMENT/QUIZ/FORUM/LESSON/MANUAL/EXTERNAL
- [x] `lms_grade_scales` + entries; resolusi course → tahun ajaran → sekolah
- [x] Bobot: STRICT (wajib 100%) & NORMALIZE, dengan ringkasan validasi
- [x] 6 metode agregasi: WEIGHTED_MEAN, SIMPLE_MEAN, SUM, HIGHEST, LOWEST, MEDIAN
- [x] Kebijakan nilai kosong PENDING/EXCLUDE/MISSING_AS_ZERO (bukan otomatis nol)
- [x] Pembebasan (EXEMPT) beserta alasan, guru, dan waktu
- [x] Extra credit CAP/UNCAPPED/IGNORE
- [x] Pembekuan gradebook (§44)

### Nilai, rilis, koreksi
- [x] `lms_assessment_results` sebagai entitas terpusat + jejak ke nilai aktivitas
- [x] Mesin perhitungan dengan rincian kontribusi per komponen
- [x] Skala nilai diterapkan pada hasil perhitungan (huruf, predikat, GPA)
- [x] Snapshot perhitungan saat rilis + `config_hash` untuk deteksi konfigurasi usang
- [x] Rilis individu/sebagian/semua, pembatalan rilis, penguncian, buka kunci berizin
- [x] Koreksi nilai berjejak; riwayat nilai immutable (menolak update/delete)
- [x] Statistik course & per komponen, distribusi 5 bucket, tanpa ranking default
- [x] Ekspor CSV mengikuti filter aktif (§38)
- [x] Observer otomatis: nilai aktivitas Phase 2 langsung tercermin di gradebook

### Rubric, outcome, competency
- [x] Rubric + kriteria + level, template institusi, salin & arsip
- [x] Penilaian rubric: pilih level → total → normalisasi → gradebook
- [x] Learning outcome per course + pemetaan aktivitas (banyak-ke-banyak)
- [x] Capaian outcome dengan ambang batas configurable + rekap kelas
- [x] Competency framework → competency → course → activity
- [x] Status kompetensi ditetapkan guru (bukan turunan angka) + bukti aktivitas/rubric

### Tampilan, integrasi, keamanan
- [x] Tampilan siswa (`/lms/grades`, `/lms/performance`) & wali (`/lms/children/:id/grades`)
- [x] Tabel nilai guru (kolom siswa melekat, sunting langsung, simpan otomatis, filter)
- [x] Banding nilai: pengajuan siswa → keputusan guru → koreksi berjejak
- [x] `AcademicGradeProviderInterface` + provider SIAKAD & mandiri, sinkronisasi idempotent
- [x] Penguncian otomatis setelah sinkronisasi + alur unlock → koreksi → re-sync
- [x] Permission Phase 3 (89 izin `lms.*`) dan pemeriksaan kepemilikan course
- [x] Notifikasi nilai (rilis, perubahan, banding) lewat kanal database yang ada
- [x] 8 berkas tes Phase 3 (gradebook, rilis, rubric, outcome/competency, banding, integrasi, keamanan, alur menyeluruh)

### Lanjutan (phase berikutnya)
- [ ] Gradebook penuh: ekspor Excel/PDF, bobot per kelas lintas course
- [ ] Workshop & penilaian sebaya
- [ ] Bank soal institusi dengan workflow persetujuan
- [ ] Analitik pembelajaran lanjutan & laporan multi-tahun

---

## Fase LMS Phase 4 — Advanced Learning Experience

Melanjutkan Phase 1–3 **tanpa membuat mesin baru**: konten interaktif, kelas
langsung, rekaman, dan sertifikat semuanya menempel pada `lms_learning_items`
sehingga penyelesaian, progress, prasyarat, dan akses tetap dihitung mesin
course yang sama. Dokumentasi: [`docs/10-MODUL-LMS-PHASE-4.md`](10-MODUL-LMS-PHASE-4.md).

Status: **fondasi (migrasi + model + enum + service) selesai**; lapisan HTTP &
UI menyusul.

### Skema & domain
- [x] 8 migrasi Phase 4: jalur belajar/prasyarat/drip, konten interaktif, kelas
      langsung, sertifikat, gamifikasi, ulasan, engagement, dan kolom tambahan
      Phase 1–3 (semuanya opsional dengan default aman)
- [x] 25 model baru (`LmsLearningPath`, `LmsInteractiveContent`, `LmsLiveSession`,
      `LmsCertificate`, `LmsBadge`, `LmsGamificationPoint`, `LmsLearningStreak`,
      `LmsCourseReview`, `LmsLearningEvent`, `LmsCourseTemplate`,
      `LmsCourseArchive`, …)
- [x] 17 enum domain (LearningPathMode, InteractiveContentType, LiveSessionStatus,
      CertificateStatus, BadgeRuleType, GamificationEventType, ReviewStatus,
      LearningEventType, LearningHealthStatus, CourseCompletionRule, …)
- [x] Relasi Phase 4 pada `LmsCourse`, `LmsEnrollment`, dan
      `LmsLearningItem::activity()` (prasyarat bertipe, badge forum, kelas langsung)
- [x] Konfigurasi `config/lms.php`: blok `learning_path`, `drip`, `interactive`,
      `live`, `certificate`, `gamification`, `reviews`, `engagement`, `messaging`

### Service
- [x] `LearningPathService`, `PrerequisiteService`, `DripContentService` (§1–§4)
- [x] `InteractiveContentService` — normalisasi per tipe, versi, pemutar tanpa
      kunci jawaban, evaluasi completion (§7–§12)
- [x] `LiveSessionService` + `LiveMeetingProviderInterface`/`LiveMeetingRegistry`
      — jendela gabung, kehadiran MANUAL/SELF/PROVIDER, rekaman (§13–§17)
- [x] `CertificateService` — kelayakan berdaftar, penerbitan idempoten per
      enrollment, verifikasi publik berjejak, template & placeholder (§21–§25)
- [x] `BadgeService` & `GamificationService` — aturan otomatis/manual, ledger XP
      ber-`dedupe_key`, rentetan belajar + bonus mingguan, leaderboard privasi
      (§26–§30)
- [x] `CourseReviewService` — ulasan termoderasi + cache rating, umpan balik
      kualitatif (§31–§32)
- [x] `LmsMessagingService` — pesan guru ↔ siswa/wali lewat notification center (§33–§35)
- [x] `LearningHealthService` — ON_TRACK/AT_RISK/INACTIVE/COMPLETED, daftar
      perlu perhatian, tren aktivitas (§58–§61)
- [x] `CourseTemplateService`, `CourseCopyService`, `CourseArchiveService` —
      template, salin konten tanpa data siswa, arsip yang dapat dipulihkan
      (§46–§51)
- [x] `LmsNotifier` + 4 notifikasi Phase 4 (badge, sertifikat, jadwal kelas,
      moderasi ulasan)

### Keamanan & operasional
- [x] Anti-abuse: XP `dedupe_key` unik, badge unik per (badge, siswa, course),
      sertifikat unik per enrollment (§72)
- [x] Payload siswa tidak memuat kunci jawaban, `host_url`, atau `passcode`
- [x] Permission Phase 4 (138 izin `lms.*`; 46 izin baru) untuk admin, kepala
      sekolah, guru, wali kelas, siswa, dan wali murid
- [x] Sakelar modul opsional dapat diubah dari `lms.settings` tanpa deploy
- [x] 37 skenario tes baru: `LmsPhase4FoundationTest` (20) &
      `LmsPhase4ExperienceTest` (17)

### Lanjutan (langkah berikutnya)
- [ ] HTTP layer: controller + route Phase 4 (kelas langsung, konten interaktif,
      sertifikat, badge/leaderboard, ulasan, template/salin/arsip, pesan,
      dasbor engagement)
- [ ] Frontend: pemutar konten interaktif, halaman kelas langsung & rekap
      kehadiran, halaman sertifikat + verifikasi publik, galeri badge &
      leaderboard, moderasi ulasan, dasbor kesehatan belajar
- [ ] Pekerjaan terjadwal: penutupan status sesi, penerbitan sertifikat otomatis,
      evaluasi badge berkala, penyegaran cache kesehatan belajar
- [ ] PDF sertifikat mengikuti template (kolom `file_path` sudah disiapkan)

---

## Fase LMS Phase 5 — Finalization, Integration, Interoperability & Production Hardening

Audit total Phase 1–4, penyelesaian temuan CRITICAL/HIGH, dan pengerasan
produksi. Tidak ada mesin kedua untuk progress, completion, grading,
enrollment, notification, atau authorization.
Dokumentasi: [`docs/14-MODUL-LMS-PHASE-5.md`](14-MODUL-LMS-PHASE-5.md) ·
[audit](11-LMS-AUDIT-PHASE-5.md) · [API](12-MODUL-LMS-API.md) ·
[deployment](13-MODUL-LMS-DEPLOYMENT.md).

### Audit & temuan yang ditutup
- [x] Audit 376 berkas LMS (70 service, 96 model, 70 enum, 36 controller,
      259 rute) + tabel ERP yang dipakai integrasi
- [x] CRITICAL: 9 service Phase 4 tanpa muara → 10 controller + 94 rute baru
- [x] HIGH: 8 kontrak integrasi yang belum ada → lengkap menjadi 18 kontrak
- [x] HIGH: sinkronisasi nilai sinkron → antrean + riwayat + retry + compare
- [x] HIGH: audit tanpa jejak asal → `lms_audit_logs` (ip, user agent,
      correlation id, immutable)
- [x] HIGH: publikasi tanpa gerbang → 9 validator + deteksi siklus prasyarat
- [x] HIGH: frontend Phase 4–5 belum ada → dicatat sebagai sisa pekerjaan
- [x] MEDIUM: impor/ekspor, SCORM, report center, integritas, health check,
      penjadwal, rate limit, dokumentasi API — semuanya ditambahkan

### Integrasi (§4–§9)
- [x] `LmsGateway` sebagai satu gerbang; tidak ada `if (standalone)` di service
- [x] Kontrak baru: Parent, Enrollment, Notification, Calendar, FileStorage,
      Attendance, FinancialEligibility, InteractiveContentProvider
- [x] Implementasi ERP + mandiri untuk setiap kontrak, dapat ditimpa lewat
      `lms.providers.*`
- [x] KRS → enrollment: `aktif`→ACTIVE, `pindah/keluar`→SUSPENDED tanpa
      menghapus progress, submission, nilai, atau riwayat
- [x] Kebijakan keuangan opsional; kemampuan inti belajar tidak pernah ditahan
- [x] Kehadiran akademik / LMS / kelas langsung dipisah jelas (`AttendanceScope`)

### Nilai, sertifikat, interoperabilitas
- [x] Antrean sinkronisasi nilai: PENDING→PROCESSING→SYNCED/FAILED→RETRYING/
      CANCELLED, riwayat percobaan, retry, cancel, compare LMS vs SIAKAD
- [x] Idempotensi menyeluruh (`idempotency_key` unik pada tabel antrean)
- [x] SCORM 1.2 & 2004: impor → validasi → ekstrak → pasang aktivitas → lacak
      (attempt unik, commit idempotent, resume) → nilai & ketuntasan lewat
      service Phase 2–3 yang sama
- [x] Ekspor/impor course: UPLOAD→VALIDATE→PREVIEW→RESOLVE→IMPORT→VERIFY,
      strategi SKIP/REPLACE/DUPLICATE/MERGE, tanpa menyalin data siswa
- [x] Backup & pemulihan course (backup sah hanya setelah `verify()`)

### Validasi, laporan, tata kelola
- [x] 9 validator konten + `ContentValidationService`; penerbitan menolak
      temuan ERROR dan siklus prasyarat (item maupun antar-course)
- [x] Report center 15 laporan + ekspor CSV; laporan berat di antrean
- [x] Audit trail immutable dengan korelasi permintaan
- [x] Pemeriksa integritas 13 pemeriksaan + saran perbaikan (tanpa perbaikan
      destruktif otomatis)
- [x] Health check `/lms/health` (publik aman) & `lms:health` (admin)
- [x] 7 perintah artisan + 6 job, seluruhnya terjadwal dengan `withoutOverlapping`
- [x] 15 rate limiter bernama, dapat diubah lewat `lms.rate_limits.*`
- [x] Dokumentasi API + OpenAPI 3.0 (307 path) & panduan deployment/rollback/retensi

### Tes
- [x] `LmsPhase5GovernanceTest`: 18 tes / 118 assertion — hijau
- [x] Dua bug nyata ditemukan tes dan diperbaiki: kebijakan keuangan yang tidak
      pernah berlaku (`LmsGateway`) & cast tanggal antrean nilai
- [x] Frontend Phase 4–5 (sebagian): klien API `phase4Api.ts` + halaman Kelas
      Langsung, Sertifikat & Verifikasi Publik, Capaian/Badge, Laporan LMS, Tata
      Kelola (audit/integritas/kesehatan); `npm run build` hijau
- [ ] Sisa UI: pemutar konten interaktif, rekap kehadiran kelas langsung,
      moderasi ulasan, impor/ekspor & SCORM, dasbor engagement guru
- [ ] PDF/XLSX laporan & sertifikat; uji restore basis data pada lingkungan nyata


---

## Modul Supervisi — Fase Riset (selesai)

Riset lengkap sebelum implementasi: dasar hukum, model, instrumen baku, penskoran,
penilaian kinerja pegawai, benchmark perangkat lunak, dan rancangan teknis.

- [x] Riset konsep & prinsip supervisi akademik/manajerial/klinis
- [x] Dasar hukum: PP 19/2017, Permendikbud 6 & 15/2018, Permendiknas 12/13/16/24,
      PermennegPANRB 16/2009 (PKG), PermenPANRB 6/2022 + SE BKN 18/2024 (SKP/e-Kinerja),
      Panduan Pengelolaan Kinerja PMM 2024–2025
- [x] Model: supervisi klinis (Goldhammer 5, Cogan 8, Acheson & Gall 3),
      developmental supervision (Glickman), lesson study (plan–do–see),
      coaching PMM (A–E), Marzano 0–4, Danielson, TAP/NIET
- [x] Teknik individual (9) & kelompok (18) + pemilihan berbasis tujuan & kondisi guru
- [x] **Instrumen baku beserta butir & penskorannya**: perangkat pembelajaran 12,
      telaah RPP 36 (0–2, maks 72), observasi pelaksanaan 40, penilaian hasil belajar 11,
      rubrik GTK 5, dampak/outcome 5, wawancara pra 8 & pasca 9, analisis data, RTL,
      instrumen tendik per jabatan, PKG 14/17 + tugas tambahan, NKKS, koreksi kehadiran
- [x] Ambang & konversi: 90/80/70 (supervisi) dan 91/76/61/51 (PKG) + % angka kredit
- [x] Penilaian pegawai: bobot kriteria, skala 1–5, multi-sumber (360°), PIP,
      kuadran SKP (hasil kerja × perilaku kerja) + pembinaan kinerja
- [x] Benchmark perangkat lunak: StaffTrac, NEE Data Tool, iObservation,
      Flint/Education Walkthrough + praktik baik & 9 jebakan umum
- [x] Rancangan teknis: 21 fitur, state machine, 30+ tabel `sup_*`, mesin penilaian,
      23 instrumen bawaan, API, UI, laporan wajib, integrasi, keamanan, 5 fase
- [ ] **Menunggu keputusan** (skala baku, bobot ke penilaian kinerja, siapa supervisor,
      multi-sumber, cakupan SKP, frekuensi minimum, visibilitas hasil) — §16 blueprint
- [ ] Implementasi Fase 1 (migrasi, model, seeder instrumen, izin, service inti)

Dokumen: [`docs/supervisi/00-RESEARCH-SUPERVISI.md`](supervisi/00-RESEARCH-SUPERVISI.md) ·
[`docs/supervisi/01-BLUEPRINT-MODUL-SUPERVISI.md`](supervisi/01-BLUEPRINT-MODUL-SUPERVISI.md)


### Modul Supervisi — Fase 1 (selesai)

- [x] Keputusan desain ditetapkan (skala fleksibel skala/angka, registry supervisor,
      peer tanpa finalisasi, bobot supervisi default 0% = bukti pendukung)
- [x] 4 migrasi / 30 tabel `sup_*` + 21 enum domain
- [x] Registry supervisor: pilih siapa saja yang boleh menilai, cakupan, kapasitas,
      masa berlaku, hak finalisasi + peta cakupan & peringatan tata kelola
- [x] Pembuat instrumen fleksibel: indikator `SCALE` (rubrik) atau `NUMBER`
      (min/maks/langkah/satuan/arah), `BOOLEAN`, teks/tanggal/berkas; versi terbit beku
- [x] Mesin skor: bobot, `NA`, konversi 100, predikat; 22 instrumen bawaan terbit
      (termasuk telaah RPP 36 butir/72, pengamatan 40 butir/40, PKG 14/56, PKG BK 17/68)
- [x] API `/api/v1/supervisi/*` + 44 permission `supervisi.*` untuk 9 peran ERP
- [x] Antarmuka: Pengaturan Supervisor, Pustaka Instrumen, Pembuat Instrumen
      (menu grup Akademik) — lolos `tsc -b` + `vite build`, diuji di browser
- [x] 32 tes (158 asersi) hijau; regresi modul lain tetap hijau
- [ ] Fase 2–5 (kegiatan & penskoran lapangan, RTL, PKG/NKKS/koreksi kehadiran,
      analitik & laporan wajib, kalibrasi, mode luring)

Dokumen: [`docs/supervisi/03-IMPLEMENTASI-FASE-1.md`](supervisi/03-IMPLEMENTASI-FASE-1.md)


### Modul Supervisi — Fase 2 (selesai)

- [x] Pelaksanaan kegiatan: buat dari instrumen terbit, isi instrumen
      (skala rubrik / entri angka / ya-tidak / pilihan ganda / narasi),
      wawancara pra & pasca-observasi, temuan, finalisasi
- [x] Nilai dihitung server: klien hanya mengirim pilihan/angka mentah,
      validasi NA, rentang, langkah, dan komentar wajib di bawah ambang
- [x] Finalisasi berchecklist + **snapshot beku bersidik jari** sehingga
      revisi instrumen tidak mengubah hasil lama; pembukaan ulang tercatat
- [x] Tanda tangan penerimaan (guru), keberatan + keputusan moderator,
      riwayat revisi, unggah bukti foto/dokumen/tautan
- [x] Tata kelola: guru hanya melihat miliknya, peer/mentor tidak boleh
      finalisasi, tak boleh menilai diri sendiri, pemegang
      `supervisi.visit.view.all` memantau seluruh sekolah
- [x] API `/api/v1/supervisi/visits/*` + UI tiga tab (Observasi, Wawancara &
      Temuan, Hasil & Tanda Tangan) dengan bilah skor sementara
- [x] 16 tes Fase 2 (total 48 tes/297 asersi hijau); build produksi frontend
      hijau; diuji di browser nyata
- [ ] Fase 3–5 (RTL & PKG/NKKS, analitik & laporan wajib, mode luring, OpenAPI)

Dokumen: [`docs/supervisi/04-IMPLEMENTASI-FASE-2.md`](supervisi/04-IMPLEMENTASI-FASE-2.md)


### Modul Supervisi — Fase 3 (selesai)

- [x] **RTL**: susun otomatis dari temuan kegiatan, butir + tenggat (prioritas
      mendesak/tinggi lebih cepat), laporan kemajuan, verifikasi atasan yang
      mengunci, dan papan pemantauan (terlambat / jatuh tempo / menunggu
      verifikasi / terverifikasi)
- [x] **Penilaian kinerja**: PKG guru (56), PKG guru BK (68), NKKS (24),
      tinjauan pegawai berbobot 1–5, kuadran SKP periodik — seluruh rumus ada
      di `AppraisalCalculator` yang teruji dengan angka pedoman
- [x] **Koreksi kehadiran** Permendikbud 35/2010 (hari alfa ÷ 46) dan
      **kontribusi supervisi** ke penilaian kinerja bila sekolah mengaktifkan
      bobotnya
- [x] **Multi-sumber (360°)** dengan bobot 60/20/10/10, validasi total 100%
      bila normalisasi dimatikan, dan penolakan isian tak sahih
- [x] **Sebutan & angka kredit** (125/100/75/50/25%) serta penguncian hasil
      terbit + buka ulang beralasan yang tercatat di snapshot
- [x] **Pengembangan kompetensi** (jam, sertifikat, biaya), **coaching**
      (komitmen + tenggat tindak lanjut), dan **PIP** (sasaran terukur, tinjauan
      berkala, keputusan hasil, tanda tangan pemahaman)
- [x] API `/api/v1/supervisi/{action-plans,appraisals,developments,coachings,pips}`
      + UI papan RTL, lembar PKG, dan halaman pengembangan/pembinaan
- [x] 27 tes Fase 3 (total 75 tes/546 asersi hijau); build produksi & uji
      browser hijau
- [ ] Fase 4–5 (laporan wajib & analitik, CIPP, kalibrasi, ekspor/cetak;
      audit menyeluruh, mode luring, OpenAPI)

Dokumen: [`docs/supervisi/05-IMPLEMENTASI-FASE-3.md`](supervisi/05-IMPLEMENTASI-FASE-3.md)


### Modul Supervisi — Fase 4 (selesai)

- [x] **Analitik**: cakupan supervisi (guru/tendik, daftar yang belum
      disupervisi), statistik kegiatan & realisasi program, rata-rata/median
      nilai per bulan, sebaran predikat, rata-rata per bagian instrumen, dan
      **indikator terlemah** dengan ambang 75% skor maksimum
- [x] **Disiplin tindak lanjut**: RTL tepat waktu, butir terlambat, rata-rata
      hari penyelesaian, persentase terverifikasi
- [x] **Rekap penilaian & pembinaan**: predikat PKG/NKKS/SKP, angka kredit,
      daftar nilai di bawah ambang yang belum punya PIP, hasil PIP, jam
      pengembangan, dan tindak lanjut coaching yang jatuh tempo
- [x] **Kinerja penilai**: jumlah kegiatan, nilai rata-rata, dan **indeks
      kelonggaran** (selisih dari rata-rata sekolah) dengan penanda *perlu
      kalibrasi*
- [x] **Laporan wajib Permendikbud 15/2018**: 6 laporan (program, hasil per
      pegawai, tindak lanjut, analitik indikator, rekap penilaian, kalibrasi)
      dengan pratinjau, riwayat, dan ekspor **CSV/XLSX/PDF/JSON**
      (XLSX ditulis sendiri tanpa dependensi; CSV ber-BOM & pemisah titik koma)
- [x] **Tanda tangan & verifikasi dokumen**: kode acak + checksum SHA-256 tiap
      dokumen, QR pada PDF, halaman verifikasi publik yang menolak berkas yang
      diubah
- [x] **Kalibrasi penilai**: sesi moderasi + ambang selisih yang disepakati,
      ringkasan selisih maks−min per indikator, kesepakatan wajib sebelum
      finalisasi, sesi terkunci, dan bahan moderasi dari kegiatan nyata
- [x] **Evaluasi program CIPP**: skor context/input/process/product 0–100,
      narasi bukti, pemangku kepentingan, bukti otomatis dari data supervisi,
      rekomendasi wajib saat finalisasi
- [x] **Pengingat terjadwal** `supervisi:remind` (kegiatan mendatang/belum
      final, RTL jatuh tempo, coaching, tinjauan PIP) — idempoten, ada
      `--dry-run`, terjadwal harian 06.30
- [x] UI: Analitik & Laporan, Kalibrasi Penilai, Evaluasi Program (CIPP), dan
      halaman verifikasi dokumen publik
- [x] 9 tes Fase 4 (total **84 tes / 728 asersi hijau**); build produksi &
      uji browser hijau
- [x] **Fase 5 — pengerasan & dokumentasi (selesai)**:
  * **Audit 13 temuan berbukti** ([`supervisi/07-AUDIT-FASE-5.md`](supervisi/07-AUDIT-FASE-5.md))
  * **Privasi**: cakupan data (`DataScope`) — guru/staf hanya melihat hasil
    sendiri; identitas rekan tidak pernah terbuka; ringkasan agregat opsional
    lewat `peer_summary_enabled` (blueprint §1.2 & §11.6)
  * **N+1 papan RTL**: 324 kueri/25 baris → di bawah 40 kueri (eager load +
    penulisan status berkelompok), stabil di halaman ke-5
  * **Konkurensi**: `lockForUpdate` + periksa ulang di dalam transaksi pada
    finalisasi & pembukaan ulang (dua tab tidak dapat memfinalkan dua kali)
  * **Sumber daya**: `per_page` dibatasi 100; wildcard `LIKE` dibersihkan
    (`SearchTerm`); **seluruh 125 rute** berbatas laju (modul 300/menit + 7
    batas khusus: verifikasi publik 30, ekspor 20, analitik 60, jawaban 240,
    unggah bukti 30, finalisasi 20, pencarian 90)
  * **Mode luring** (blueprint §12): formulir PDF huruf pilihan, sinkronisasi
    idempoten tahan-sebagian dengan laporan konflik, antrean `localStorage`,
    panel Mode Luring di UI
  * **Operasional**: `GET /supervisi/health` + `supervisi:health` (9 pemeriksaan
    integritas, terjadwal Senin), `supervisi:prune` (retensi berkas; riwayat &
    kode verifikasi dipertahankan), `config/supervisi.php`
  * **Dokumentasi**: OpenAPI 97 path/125 operasi, panduan API, panduan pengguna,
    dokumen deployment + **latihan pencadangan/pemulihan nyata**
    (cadangan 73 ms, pemulihan 28 ms, integritas ok, dokumen tetap sah)
  * **Uji**: 105 tes / 1.024 asersi hijau (Fase 5: 20 tes, termasuk uji
    struktural yang mewajibkan setiap rute berizin & berbatas laju)

Dokumen: [`docs/supervisi/06-IMPLEMENTASI-FASE-4.md`](supervisi/06-IMPLEMENTASI-FASE-4.md)

## Modul UKS — Usaha Kesehatan Sekolah (selesai)

- [x] 12 tabel `uks_*` (tambahan, tidak mengubah tabel inti): petugas, profil
      kesehatan, obat & mutasi stok, kunjungan, obat kunjungan, persetujuan
      orang tua, rujukan, pemeriksaan berkala, imunisasi, insiden, surat
- [x] **5 pihak terhubung** dengan cakupan baris di `UksAccessService`:
      petugas/admin = seluruh sekolah, kepala sekolah = lihat semua, guru &
      wali kelas = siswa kelas yang diampu (wali kelas atau pengajar mapel),
      orang tua = anaknya saja, siswa = dirinya sendiri
- [x] Alur kunjungan: catat → tangani → obat (stok berkurang dalam transaksi
      berkunci; obat kedaluwarsa ditolak; stok tidak bisa minus) → rujuk →
      tutup (terkunci). Kasus berat/darurat otomatis mengabari akun orang tua
- [x] Persetujuan orang tua lewat aplikasi (atau dicatat petugas via telepon)
- [x] Rekam kesehatan: profil, pemeriksaan berkala (IMT dihitung server),
      imunisasi + jadwal susulan
- [x] Surat keterangan PDF + kode verifikasi 8 karakter, endpoint publik
      `GET /uks/letters/verify/{code}` (30/menit/IP)
- [x] Operasional: `uks:health` (8 pemeriksaan) & `uks:remind` (idempoten),
      keduanya terjadwal; batas laju di `config/uks.php`
- [x] Izin `uks.*` untuk peran yang sudah ada (termasuk `wali-murid` dan
      `petugas-kesehatan`); 37 rute, semuanya berizin & berbatas laju
- [x] Frontend: 7 halaman (beranda, kunjungan + detail, obat, rekam kesehatan,
      insiden, surat, petugas) di menu Akademik
- [x] **10 tes / 128 asersi hijau**; `tsc --noEmit` bersih

Dokumen: [`docs/uks/01-MODUL-UKS.md`](uks/01-MODUL-UKS.md)

---

## Modul Clinic / UKS — ruang kerja klinis (2026-09-30)

Modul kesehatan kedua, sengaja dipisah dari UKS ringan: `App\Modules\Clinic`
(tabel `clinic_*`, API `/api/v1/clinic/*`) untuk sekolah/pesantren yang punya
tenaga medis dan butuh alur klinis penuh.

- [x] **40 tabel `clinic_*`** dalam 3 migrasi, **40 model**, **53 enum** domain, **17 layanan**, **11 controller**, **96 rute**
- [x] **10 kontrak integrasi** (`StudentProvider`, `ParentProvider`,
      `InventoryProvider`, `FinanceProvider`, `NotificationProvider`,
      `DocumentProvider`, `AssetProvider`, `AttendanceProvider`,
      `BoardingProvider`, `DiagnosisCodeProvider`) — semuanya dapat diganti satu
      binding di `ClinicServiceProvider`
- [x] **Tanpa duplikasi sumber kebenaran**: identitas siswa dari master ERP,
      saldo obat di `inventory_items` (klinik tidak punya buku stok — diuji),
      dokumen di disk privat, tanpa jurnal akuntansi
- [x] **Nomor rekam medis imun** (`MR-2026-000001`) — tidak berubah saat pindah
      kelas atau ganti tahun ajaran
- [x] Alur lengkap: registrasi → antrean → triase → tanda vital (IMT otomatis +
      peringatan ambang konfigurasi) → asesmen SOAP ber-versi → diagnosis
      berkode → tindakan → resep (periksa alergi) → penyerahan obat (FEFO,
      blokir kedaluwarsa, stok berkurang lewat Inventory) → pemberian obat (MAR)
      → observasi → rujukan → pemulangan → notifikasi → tindak lanjut
- [x] Gawat darurat **melewati antrean**, cedera dengan mekanisme kejadian,
      rujukan wajib tujuan + alasan + pelacakan status
- [x] Skrining massal per kelas (idempotent per siswa+tanggal+jenis), imunisasi,
      surat keterangan ber-alur (diminta → ditinjau → terbit) + verifikasi publik
      tanpa membuka isi klinis
- [x] **Privasi dua lapis**: 41 izin `clinic.*` (layar) + `ClinicAccessService`
      (baris). Guru **tidak** otomatis mendapat rekam medis; orang tua hanya
      anaknya; siswa hanya dirinya lewat portal baca-saja
- [x] **Jejak audit** semua akses/perubahan + amendment non-destruktif (nilai
      lama, nilai baru, alasan, pelaku); tidak ada endpoint hapus catatan klinis
- [x] Notifikasi ber-kebijakan privasi: pesan menyebut layanan, bukan diagnosis;
      yang ditahan dicatat beserta alasannya
- [x] Operasional: `clinic:expire-batches` (05:30) & `clinic:expire-follow-ups`
      (06:00) terjadwal; `GET /clinic/health` memeriksa 8 integrasi tanpa data pasien
- [x] Frontend: **12 halaman** (dasbor + papan antrean, pasien & rekam medis,
      daftar kunjungan, workspace klinis, farmasi, gawat darurat & cedera,
      rujukan & tindak lanjut, skrining & imunisasi, surat & dokumen, laporan,
      portal orang tua) di grup menu **Clinic / UKS**
- [x] **52 tes / 342 asersi hijau**; `tsc -b && vite build` bersih (2298 modul);
      diverifikasi di browser: dasbor, daftar pasien, workspace dengan data nyata
      (banner alergi berat, SOAP, diagnosis J06, IMT), portal wali
Perbaikan yang ikut terbawa:
- [x] `Badge` menerima alias semantik (`danger`/`warning`/`success`/`info`/`neutral`)
- [x] `vite.config.ts`: `server.allowedHosts` agar dev server dapat dibuka lewat
      URL tunnel
- [x] Impor `Label` tak terpakai di halaman UKS (menghambat build `tsc`)

Dokumen: [`docs/16-MODUL-CLINIC.md`](16-MODUL-CLINIC.md)

---

## Modul Workflow & Approval Engine — infrastruktur bersama (2026-09-30)

Mesin persetujuan **lintas modul**: Finance, Procurement, HRD, Inventory, Asset,
PPDB, Student, Boarding, Document/e-Office, Maintenance, dan Clinic mengirim
permintaannya ke satu engine — bukan membangun sistem approval masing-masing.

- [x] **24 tabel `workflow_*`** dalam 3 migrasi, **24 model**, **24 enum domain**,
      9 kontrak, **19 layanan**, 4 controller, **70+ rute** `/api/v1/workflows/*`
- [x] **Satu titik ekstensi** (`WorkflowRegistrar`): resolver approver, aksi
      otomatis, provider konteks, trigger, evaluator kondisi — modul lain
      menyumbang kemampuannya tanpa menyentuh kode engine
- [x] **Tanpa duplikasi sumber kebenaran**: mesin tidak pernah membaca tabel
      bisnis; data datang lewat `WorkflowContextProviderInterface` dan dibekukan
      sebagai snapshot ber-hash (§65). Nominal yang dipakai percabangan tidak
      dapat dipalsukan dari payload klien
- [x] **Graf node**: START · APPROVAL · REVIEW · CONDITION · PARALLEL ·
      NOTIFICATION · AUTOMATIC · END, dengan mode SEQUENTIAL/PARALLEL/HYBRID dan
      kuorum ALL/ANY/MINIMUM_N/PERCENTAGE; cabang paralel menunggu **seluruh**
      cabang hidup selesai sebelum bergabung
- [x] **Versioned & beku**: setiap perubahan alur melahirkan versi baru
      ber-`graph_hash`; request lama tetap memakai versi saat ia dibuat
- [x] **Kebijakan approver kosong** yang jujur (§59): FAIL · ESCALATE ·
      USE_FALLBACK · PAUSE — tidak ada jalur "lewati diam-diam"; request yang
      tertahan muncul di dasbor beserta alasannya
- [x] **Self-approval dilarang secara default** (§36) dan **SoD dapat dikonfigurasi**
      (`requester_cannot_be_final_approver`, `forbid_same_user_multiple_steps`,
      `min_distinct_approvers`)
- [x] **Idempotensi dua lapis** (§64, §68): indeks unik (step, approver) +
      `Idempotency-Key` pada keputusan; kunci idempoten unik pada notifikasi —
      klik ganda, retry jaringan, dan webhook berulang tetap satu keputusan
- [x] **Delegasi tidak menghapus identitas** (§8): penugasan dialihkan, tetapi
      `original_approver_id` + `delegation_id` tercatat pada penugasan *dan* keputusan
- [x] **SLA kalender kerja** (§41, §70): "48 jam kerja" sungguhan — melewati akhir
      pekan, hari libur, dan di luar jam kerja; penyapu `workflow:check-sla`
      menjalankan pengingat → segera jatuh tempo → terlambat → eskalasi berjenjang
- [x] **Audit berantai hash** (§48): rantai per instance, `POST /audit/verify`
      membuktikan tidak ada baris yang disunting/dihapus — termasuk lewat SQL langsung
- [x] **Analitik operasional** (§53) yang memantau proses, bukan menilai orang:
      durasi, kepatuhan SLA, volume per modul/unit, titik tersangkut, beban tertunda
- [x] **Peran sistem** ERP_ADMIN · WORKFLOW_ADMIN · WORKFLOW_MANAGER · AUDITOR —
      Workflow Admin **tidak** otomatis boleh menyetujui request bisnis (§10)
- [x] **4 definisi bawaan** siap pakai (Pengadaan, Cuti, Pengeluaran, Dokumen)
      dikirim sebagai **data** oleh seeder, bukan kode program
- [x] Perintah: `workflow:check-sla` (tiap 5 menit), `workflow:expire` (harian),
      `workflow:health`, `workflow:trigger`
- [x] Frontend: **12 halaman** (dasbor, kotak masuk approver dengan penyaring,
      permintaan saya, ruang kerja request + visualizer + timeline + konteks +
      jejak audit, definisi & builder graf, delegasi, analitik, audit, pengaturan
      kalender & direktori) + grup menu **Workflow & Persetujuan**
- [x] **51 tes / 253 asersi hijau**; `tsc -b` & `vite build` bersih (2.314 modul);
      diverifikasi di browser sungguhan: login → kotak masuk → keputusan →
      request berpindah cabang kondisi nominal → APPROVED, rantai audit utuh
Perbaikan yang ikut terbawa:
- [x] `WorkingCalendar` menyimpan **array konfigurasi** ke cache, bukan model
      Eloquent (model ter-cache pecah menjadi `__PHP_Incomplete_Class` pada proses
      lain — tertangkap saat verifikasi runtime)
- [x] Daftar "Permintaan Saya" menyertakan ringkasan progres langkah
- [x] Tombol keputusan di ruang kerja request hanya tampil bila penugasan benar
      milik pengguna (atau ia memegang izin override)

Dokumen: [`docs/17-MODUL-WORKFLOW-ENGINE.md`](17-MODUL-WORKFLOW-ENGINE.md) ·
[`docs/workflow/01-PANDUAN-INTEGRASI.md`](workflow/01-PANDUAN-INTEGRASI.md)

---

## Modul Student Activity & Extracurricular — Student Development Platform (2026-10-01)

Seluruh kegiatan non-akademik siswa dalam satu modul: ekstrakurikuler, klub,
organisasi, kompetisi, prestasi, sertifikat, dan portofolio — dengan modul lain
tetap menjadi sumber kebenarannya.

- [x] **45 tabel `activity_*`** dalam 6 migrasi, **55 model**, **59 enum**, 17 kontrak,
      **19 layanan**, 12 controller, **175 rute** `/api/v1/activities/*`
- [x] **Pemisahan konsep yang tegas**: kategori ≠ program ≠ periode ≠ keanggotaan ≠
      sesi ≠ penilaian ≠ prestasi ≠ sertifikat; prestasi adalah entitas tersendiri
      (individu/tim) yang menjadi dasar sertifikat dan isi portofolio
- [x] **Katalog configurable**: kategori berjenjang, tujuan perkembangan,
      keterampilan, peran anggota, jenis & tipe kegiatan, periode (tahun ajaran/
      semester/term/musim)
- [x] **Aturan penerimaan berbasis data** (`activity_registration_requirements`):
      usia, jenjang, gender, program, prasyarat, keterampilan, kehadiran akademik,
      rekomendasi guru, audisi/tes coba, persetujuan orang tua, batas jumlah aktivitas
- [x] **Pendaftaran → seleksi → keanggotaan** dengan pemisahan tugas: admin katalog
      ≠ pemutus pendaftaran ≠ verifikator prestasi
- [x] **Kapasitas & daftar tunggu**: kursi terakhir dijaga kunci transaksi; promosi
      daftar tunggu idempoten (`promotion_key`)
- [x] **Persetujuan orang tua**: siswa mendaftar, wali memutuskan (setujui/tolak),
      keanggotaan menunggu sampai persetujuan ada; kedaluwarsa terjadwal
- [x] **Deteksi bentrok nyata**: aktivitas ↔ aktivitas, ↔ jadwal pelajaran, ↔ kegiatan
      asrama, ↔ fasilitas; kebijakan tegas atau longgar diatur sekolah
- [x] **Sesi nyata dari jadwal berulang** dengan kunci idempoten, daftar hadir
      idempoten (aman untuk QR/NFC berulang), cermin opsional ke modul Attendance
- [x] **Kehadiran jujur**: persentase dihitung dari pertemuan yang sudah berlangsung;
      di bawah minimum → peringatan + pembatas kelayakan (bukan sanksi otomatis)
- [x] **Penilaian perkembangan**: rubrik & butir berbobot, nilai → bukti keterampilan,
      progres, dan **kelayakan penyelesaian** yang menolak bila syarat belum terpenuhi
- [x] **Kompetisi**: kategori, peserta, tim, babak, pertandingan, juri berjangkauan,
      skor terkunci, **koreksi bersebab berjejak audit**, hasil terverifikasi,
      lalu otomatis menjadi prestasi (kredit penuh bagi anggota tim)
- [x] **Sertifikat beralasan**: snapshot kelayakan, nomor `CERT-ACT-YYYY-NNNNNN`,
      pencabutan berjejak, dan **verifikasi publik tanpa data pribadi**
- [x] **Portofolio & transkrip aktivitas**: dirakit ulang dari data asli, tautan
      berbagi eksplisit, dan transkrip yang berbeda dari transkrip akademik
- [x] **Integrasi lewat kontrak** (17): siswa, wali, pegawai, kehadiran, akademik,
      LMS, keuangan, inventaris, aset, sarpras, event, transportasi, notifikasi,
      sertifikat, dokumen, asrama, BK — setiap panggilan tercatat, kegagalan modul
      tetangga tidak menjatuhkan alur
- [x] **BK dibatasi secara desain**: hanya sinyal perkembangan; catatan konseling
      tidak pernah melewati modul ini
- [x] **Dasbor 4 peran** (pengelola, pembina, siswa, orang tua) + 13 laporan
      operasional + jejak audit append-only
- [x] **6 perintah** (dijalankan nyata di DB pengembangan, semuanya bersih): `activity:generate-sessions`, `activity:session-reminders`,
      `activity:expire-consents`, `activity:evaluate-completion`,
      `activity:check-anomalies`, `activity:health` (terjadwal) — plus 11 jenis laporan operasional yang semuanya berjalan
- [x] Frontend: **12 halaman** — katalog, detail, aktivitas saya, ruang kerja pembina,
      dasbor & 11 laporan, aktivitas anak (persetujuan orang tua), verifikasi
      sertifikat, **kompetisi** (babak, pertandingan, juri, skor terkunci, koreksi
      beralasan, penerbitan hasil), **prestasi & sertifikat** (verifikasi,
      penerbitan, pencabutan), **portofolio & transkrip** (cuplikan + tautan
      berbagi), **tata kelola & audit** (jejak audit, persetujuan menggantung,
      tagihan, perlengkapan & ruang), dan **portofolio publik** di luar cangkang
      aplikasi — plus grup menu **Aktivitas Siswa**
- [x] **71 tes / 294 asersi hijau**; `tsc -b` & `vite build` bersih; diverifikasi di
      browser sungguhan (11 langkah, termasuk aksi tulis):
      - dasbor, katalog, ruang kerja pembina (daftar hadir & rekap 100%)
      - verifikasi sertifikat `CERT-ACT-2026-000001` → **Valid tanpa PII**
      - **kompetisi dibuat dari layar** → detail terbuka → pendaftaran dibuka
      - **prestasi dicatat dari layar → diverifikasi → sertifikat diterbitkan**
      - portofolio siswa (Aisyah Rahma: 100% hadir, nilai 88, sertifikat) + transkrip
      - **portofolio publik dibuka tanpa login**, nama tersamar (`Aj*********`), NIS tidak dikirim
- [x] Kontras teks header tabel dinaikkan (`slate-500` → `slate-600`) setelah audit
      keterbacaan menandai rasio 3,82:1 (di bawah WCAG AA 4,5:1)

Temuan yang diperbaiki setelah modul ditelusuri ulang (bukan ditemukan lewat tes
fitur, tetapi lewat pemeriksaan sistematis izin vs rute vs kode):

- [x] **Empat izin tidak dipegang peran mana pun** sehingga kemampuannya mati:
      `activity.registration.cancel` (petugas tidak dapat membatalkan pendaftaran),
      `activity.registration.override` (batas aktivitas tidak dapat dikecualikan),
      `activity.attendance.override` (kehadiran terkunci tidak dapat dikoreksi),
      `activity.counseling.view` (ringkasan pembinaan tidak terjangkau). Kini
      diberikan ke peran yang tepat **dan** benar-benar bekerja (13 rute baru).
- [x] **`activity.settings.manage` dihapus** dari katalog: konfigurasi modul
      memang berbasis berkas/env, dan izin tanpa wujud hanya menyesatkan.
- [x] **Daftar persetujuan untuk staf** dan **jejak audit yang dapat dibaca**
      ditambahkan (`GET /activities/consents`, `GET /activities/audit`) — sebelumnya
      audit hanya ditulis, tidak pernah dapat diperiksa.
- [x] **Permintaan ke modul lain kini dapat dirapikan dari sisi aktivitas**
      (`integration/finance/charges`, `integration/inventory/requests`,
      `integration/facility/bookings`) sehingga izin `activity.finance.*`,
      `activity.inventory.*`, dan `activity.facility.*` tidak lagi hiasan.
- [x] **Bug 500 pada pembatalan pendaftaran**: layanan menulis kolom
      `release_waitlist` yang **tidak pernah ada** di migrasi — ditemukan oleh tes
      baru, kini dihapus (status `CANCELLED` sendirilah yang membebaskan kursi).
- [x] **Bug seeder yang berbahaya**: peran `*` (ERP_ADMIN/super-admin) di-**sync**
      sehingga menjalankan seeder modul akan **menghapus seluruh izin modul lain**
      dari peran tersebut. Sekarang aditif (`givePermissionTo`).
- [x] **Laporan keanggotaan menampilkan kolom kosong**: kunci baris memakai
      `UPPER_SNAKE` sedangkan kolom tabel huruf kecil, sehingga angka keanggotaan
      tidak pernah terlihat. Kini dipetakan, dengan tes kontrak untuk semua laporan.
- [x] **Kehadiran setelah sesi selesai** kini benar-benar terkunci kecuali pemegang
      `activity.attendance.override`, lengkap dengan jejak `ATTENDANCE_OVERRIDDEN`.
- [x] **Seeder modul tidak terdaftar di `DatabaseSeeder`**: instalasi baru
      (`php artisan migrate --seed`) tidak akan memiliki peran `ACTIVITY_*`,
      sehingga seluruh layar modul tidak dapat dibuka siapa pun. Kini terdaftar
      (bersama `WorkflowPermissionSeeder` yang juga tertinggal), dengan tes
      penjaga + tes idempotensi yang sekaligus mengunci sifat **aditif** seeder.
- [x] **Kontras header tabel** dinaikkan (`slate-500` → `slate-600`) setelah audit
      keterbacaan menandai rasio 3,82:1 (di bawah WCAG AA 4,5:1).

Perbaikan yang ikut terbawa:
Perbaikan yang ikut terbawa:

- [x] Persentase kehadiran memakai penyebut **pertemuan yang sudah berlangsung**
      (hadir 1 dari 3 terbaca 33%, bukan 100%)
- [x] Kelengkapan catatan kehadiran tidak lagi dapat melebihi 100% (catatan anggota
      yang sudah berhenti tetap tersimpan, tetapi tidak dihitung sebagai cakupan)
- [x] `RoomBooking` tidak tersedia sebagai model → pemesanan ruang lewat query
      builder dengan kosakata status tabel yang ada (`approved`, `cancelled`)
- [x] Status kehadiran cermin memakai nilai yang dikenal tabel absensi akademik

Dokumen: [`docs/18-MODUL-AKTIVITAS-SISWA.md`](18-MODUL-AKTIVITAS-SISWA.md) ·
[`docs/activity/01-PANDUAN-INTEGRASI.md`](activity/01-PANDUAN-INTEGRASI.md)

## Modul Bimbingan & Konseling (BK) — Phase 1 (2026-12-01)

Dukungan siswa dari kekhawatiran pertama sampai kasus dinyatakan selesai, dengan
kerahasiaan sebagai fondasi: 30 tabel, 35 enum, 31 model, 19 layanan, 12 kontrak
integrasi, 79 rute `/api/v1/bk/*`, 7 halaman, dan 41 tes / 133 asersi hijau.

- [x] **Lifecycle lengkap**: referral → screening → kasus → penugasan → asesmen →
      rencana → sesi → intervensi → tindak lanjut → tinjauan → penutupan, dengan
      timeline append-only dan pembukaan kembali yang terjaga
- [x] **Kerahasiaan berlapis enam tingkat** ditegakkan `BkAccessService` pada
      endpoint, Query, Resource, dokumen, laporan, dan notifikasi — guru/siswa/wali
      tidak pernah menerima `counselor_note`, dan pembacaan data rahasia (termasuk
      penolakan) selalu tercatat
- [x] **Mesin asesmen configurable**: template → bagian → pertanyaan (9 tipe),
      dikirim ke siswa/orang tua/guru/konselor, dengan pertanyaan sensitif yang
      disembunyikan dari audiens yang tidak berhak
- [x] **Janji temu sadar bentrok**: jadwal pelajaran, ketersediaan konselor, janji
      lain konselor/siswa, kegiatan asrama, ujian, dan kegiatan sekolah — dapat
      ditembus hanya koordinator dan selalu tercatat
- [x] **Penutupan yang tidak asal tutup**: checklist penghalang (tindak lanjut,
      intervensi, sasaran, sesi, pertemuan, tinjauan) sebelum kasus boleh ditutup
- [x] **Portal siswa & orang tua**: permintaan dukungan tanpa detail sensitif,
      jadwal sendiri, dan persetujuan yang hanya dapat diputuskan wali sah
- [x] **Integrasi lewat 12 kontrak** (siswa, wali, akademik, presensi, kedisiplinan,
      asrama, klinik **tanpa diagnosis**, aktivitas, notifikasi, dokumen, workflow,
      audit) — BK tidak menduplikasi sumber kebenaran
- [x] **5 perintah artisan** (keterlambatan tindak lanjut, pengingat janji,
      kedaluwarsa persetujuan, tinjauan kasus, kesehatan modul)
- [x] **12 laporan agregat** tanpa isi catatan, aman untuk kepala sekolah & auditor
- [x] Frontend **7 halaman** + grup menu **Bimbingan & Konseling**, diverifikasi di
      browser sungguhan
- [x] `docs/19-MODUL-BK.md` sebagai blueprint modul

### Cacat yang ditemukan & diperbaiki selama pengujian

- [x] **`bk_cases.severity` NOT NULL** diisi `null` saat kasus dibuka dari referral
      → deret 500 pada alur utama; kini diturunkan dari prioritas
      (`Severity::fromPriority`) sehingga tidak pernah kosong
- [x] **`document_sequences.reset_period`** memakai `YEARLY` sedangkan kolomnya
      `enum('never','year','month')` → penomoran BK gagal pada basis data ber-CHECK;
      kini `year`
- [x] **SoftDeletes pada tabel tanpa `deleted_at`** (label, bagian asesmen, sasaran,
      peserta, tugas pertemuan, …) → setiap kueri gagal; 10 model dibersihkan
- [x] **Cast enum ditulis sebagai nama pendek** (`'status' => 'CaseStatus'`) → Laravel
      membacanya sebagai kelas cast; 60 cast diperbaiki menjadi
      `\App\Modules\Bk\Domain\Enums\…::class`
- [x] **Pembuat kasus tidak dapat melihat kasusnya sendiri** (bukan koordinator, bukan
      peserta) → pembuat kini otomatis menjadi peserta kasus
- [x] **Wali/staf tidak dapat menindaklanjuti permintaan portal** karena aturan
      sumber `PARENT` menuntut hubungan wali pada **pelaku**, bukan pada siswa;
      kini staf BK dikecualikan (tetap mengikat wali yang mengajukan sendiri)
- [x] **Precedence `&& … ?? …`** pada pembuatan tindak lanjut otomatis → tindak
      lanjut tidak pernah lahir; diperbaiki dengan tanda kurung yang benar
- [x] **Laporan memaksa `(string)` pada kolom ber-enum** → 500 pada 12 laporan;
      kini dinormalkan lewat helper `scalar()`
- [x] **Jejak audit hanya mencatat akses yang ditolak** → pembacaan sesi rahasia
      yang berhasil kini juga tercatat (`VIEW_CONFIDENTIAL`)
- [x] **Seeder modul belum terdaftar di `DatabaseSeeder`** (bersama Workflow) →
      instalasi baru tidak memiliki peran `BK_*`; kini terdaftar dan **aditif**

## Modul BK — Phase 2: platform dukungan siswa (2026-12-15)

Advanced student support: konferensi kasus, rujukan eksternal, persetujuan
granular, penanda risiko, mesin peringatan dini ber-versi, rencana pengembangan,
bimbingan karier, program kelompok, umpan balik siswa & wali, otomasi idempoten,
SLA & eskalasi, analitik berprivasi, pencarian aman, ekspor terjaga, retensi
beraudit, dan isolasi unit sekolah.

- [x] **27 tabel baru** + kolom aditif pada 20 tabel Phase 1 (tidak ada yang dihapus
      atau diganti nama; istilah status lama tetap diterima sebagai alias baca)
- [x] **Mesin peringatan dini ber-versi**: ambang, jendela, jeda, dan tindakan
      tersimpan sebagai data; metrik dibaca dari modul pemiliknya lewat kontrak;
      metrik tak tersedia dicatat apa adanya, bukan dianggap nol
- [x] **SLA configurable** per kategori/prioritas/severity/tipe kasus, kebijakan
      paling spesifik menang, cuplikan kebijakan tersimpan pada setiap baris SLA
- [x] **Eskalasi berjenjang** `NONE → COORDINATOR → MANAGEMENT` yang dapat diakui
      dan diselesaikan, dengan pemisahan tugas pada pemusnahan data
- [x] **Otomasi 9 alur** dengan kunci idempoten (cron ganda & retry antrean aman)
- [x] **Rujukan eksternal berpagar persetujuan**: pengiriman ditolak selama izin
      wali belum berlaku; data medis ditolak dengan penjelasan (milik Clinic/Health)
- [x] **Penutupan kasus tetap terjaga**: usul tinjauan penutupan dibuat otomatis,
      tetapi keputusan menutup tetap milik manusia
- [x] **Analitik berprivasi**: kelompok < 3 kasus disamarkan; cache hanya untuk
      agregat tanpa dimensi pribadi
- [x] **Isolasi unit sekolah** lewat `school_id` + global scope yang dapat
      dinyalakan, dengan perilaku lama tetap utuh ketika dimatikan
- [x] **Pencarian & ekspor terjaga**: penyaring per peran/cakupan/kerahasiaan,
      daftar kolom terlarang terpusat, ekspor melewati izin → konfirmasi → audit
- [x] **Retensi beraudit**: kebijakan ber-versi, pemusnahan menuntut persetujuan
      orang lain, jejak pemusnahan tersimpan setelah isinya dihapus
- [x] 4 perintah artisan baru + seeder kebijakan (aturan, SLA, retensi, izin)
- [x] **170 rute** `/api/v1/bk/*` (+91) dan 10 controller Phase 2
- [ ] Frontend Phase 2 (18 entri menu, tab profil siswa, dasbor lanjutan) — API siap
- [ ] 12 uji Phase 2 masih merah (jalur HTTP konferensi/rujukan/pencarian + 1 asersi
      riwayat retensi); **matriks privasi §28 lulus**

### Cacat yang ditemukan & diperbaiki selama Phase 2

- [x] **`DatabaseSeeder` menunjuk namespace seeder BK yang salah**
      (`Database\Seeders\BkPermissionSeeder`) → instalasi baru gagal saat seeding;
      kini memakai namespace modul dan mendaftarkan empar seeder Phase 2
- [x] **`EscalationLevel::COUNSELOR` tidak ada** (nilainya `NONE`, `COORDINATOR`,
      `MANAGEMENT`, `EXTERNAL`) → SLA tidak pernah berjalan; tingkat awal kini `NONE`,
      dan seeder SLA memakai nilai enum yang sah
- [x] **`PlanStatus::IN_PROGRESS` tidak ada** (`DRAFT`, `ACTIVE`, `REVIEW`,
      `COMPLETED`, `CANCELLED`) → rencana pengembangan gagal diperbarui
- [x] **`BkTenantResolver` bukan singleton** → unit yang dipaksa pada satu instance
      tidak terlihat oleh global scope, sehingga isolasi unit tidak bekerja pada uji
- [x] **Kunci cooldown menabrak kunci tindakan** pada jejak peringatan dini →
      evaluasi ulang tidak meninggalkan jejak; kini kunci jeda tersendiri per hari
- [x] **Keputusan retensi mengubah baris permintaan** → riwayat kehilangan jejak
      "diajukan"; keputusan kini ditulis sebagai baris baru
- [x] **`RetentionEntityType` tidak terimpor pada model retensi** → tipe argumen
      scope jatuh ke namespace model dan kueri gagal
- [x] **`acceptsNewMembers()` dipanggil pada model, bukan pada enum status**
- [x] **Trait `ApiResponse` terpasang sebagai impor, bukan pemakaian di kelas** →
      seluruh controller Phase 2 gagal dengan "undefined method successResponse"
- [x] **`BkConsentService::request()` menulis `PENDING`** sementara Phase 2 memakai
      `REQUESTED` → keputusan wali ditolak; kini istilah kanonik ditulis dan ejaan
      lama tetap diterima
- [x] **`BkEarlyWarningRule::appliesTo()`** memakai `$this->scope` yang belum
      dipastikan array → aturan ber-scope gagal dievaluasi

## Modul Finance — penyelarasan standar akuntansi (2026-12-16)

Menyelaraskan akuntansi proyek ini dengan standar pada
[AbuNarasya/al-wafi](https://github.com/AbuNarasya/al-wafi) bagian akuntansi, secara **aditif**
(tidak ada tabel/kolom/alur lama yang diubah maknanya).

- [x] `App\Support\Money` — aritmetika uang BCMath (skala 2, kuantiti 4); float hanya boleh masuk
      di batas sistem
- [x] `JournalValidator` — minimal 2 baris, satu sisi per baris, tidak negatif, dan keseimbangan
      dibandingkan **tepat** (toleransi `TOLERANCE = 0.005` dicabut dari jalur jurnal)
- [x] `SumberModul` — 29 modul asal jurnal sebagai daftar sah; nilai di luar daftar ditolak
- [x] `CoaHierarchy` — akar kelompok 1–5 diturunkan dari kode akun (tipe hanya cadangan)
- [x] **Dimensi unit bisnis** pada baris jurnal: `finance_business_units`, `finance_unit_defaults`,
      `kode_unit` + aturan **unit penampung** untuk seluruh liabilitas & rekening kas/bank
- [x] **Fund accounting**: `finance_funds`, `finance_fund_accounts`,
      `finance_fund_contra_accounts`, `DanaPolicy` — dana terikat hanya membatasi baris beban
- [x] **Saldo awal** dengan tahap rancangan → posting (`finance_opening_balances`), wajib seimbang,
      terkunci setelah diposting
- [x] **Buka periode** lewat permohonan + persetujuan orang lain (`PeriodReopenService`)
- [x] **Pembatalan jurnal** lewat permohonan + persetujuan orang lain (`JournalVoidService`),
      jurnal pembalik menyalin dimensi per baris
- [x] **Laporan standar**: neraca saldo, laba rugi per unit, laporan dana
      (penerimaan/penggunaan/saldo + arus kas terpisah)
- [x] Snapshot **nama akun** pada baris jurnal agar laporan lama tidak bergeser saat akun diubah
- [x] 6 controller + 22 endpoint baru dengan izin yang sudah ada (83 izin `finance.*`) + 2 izin baru
      (`finance.closing.approve`, `finance.journal.approve`)
- [x] 12 uji baru (`FinanceAccountingStandardTest`) — HIJAU; 10 uji `FinanceAccountingE2ETest`
      tetap HIJAU (277 asersi)
- [ ] Uji HTTP untuk endpoint baru belum dibuat (layanan & engine sudah diuji)
- [ ] Modul persediaan, aset tetap, dan akrual: kolom dimensinya sudah disiapkan di
      `SumberModul` (`PERSEDIAAN`, `DEPRESIASI`, `PELEPASAN_ASET`) tetapi modulnya belum ada di repo

### Cacat nyata yang ditemukan & diperbaiki saat penyelarasan

- [x] **Keseimbangan jurnal ditoleransi 0,005** sehingga selisih nyata dapat lolos; kini dibandingkan
      tepat sebagai string BCMath
- [x] **Seluruh perhitungan uang memakai float** (`round((float) …)`) — dibaca sebagai bug presisi,
      bukan sekadar gaya; kini lewat `Money`
- [x] **Laporan tidak pernah menghitung hari terakhir rentang**: batas kueri memakai tanggal
      `Y-m-d` sementara kolom `transaction_date` menyimpan datetime, sehingga transaksi hari terakhir
      terbuang diam-diam (ditemukan saat uji laporan per unit)
- [x] **Laporan dana berpotensi menghitung ganda**: penerimaan/penggunaan semula dihitung dari sisi
      debet/kredit baris kas, sehingga pembayaran beban dari kas berdana terhitung sekali sebagai
      penggunaan dan sekali lagi sebagai penerimaan; kini penerimaan dibaca dari akun pendapatan dan
      penggunaan dari akun beban, dengan arus kas dilaporkan terpisah
- [x] **Asal modul jurnal tidak dapat dipastikan** (`reference_type` bebas) — kini divalidasi
      terhadap daftar `SumberModul`
- [x] **`EscalationLevel`/`PlanStatus` (BK)** — catatan lintas modul: pola yang sama (nilai enum yang
      tidak ada) pernah membuat fitur tidak pernah berjalan; kini setiap nilai enum yang dipakai
      seeder & layanan dipastikan ada

## Modul Finance — implementasi penuh standar akuntansi (2026-12-16)

Melanjutkan penyelarasan dengan standar al-wafi: **seluruh** modul standar diimplementasikan dan
**seluruh** endpoint diuji.

### Modul yang ditambahkan

- [x] **Aset tetap & penyusutan** (`finance_assets`, `finance_asset_depreciations`, `AssetService`,
      `DepreciationService`): garis lurus & saldo menurun, penyusutan per periode (idempoten lewat kunci
      basis data), `preview()` sebelum menjalankan, pelepasan aset dengan satu jurnal penutup
- [x] **Akrual** (`finance_accruals`, `AccrualService`): pembalikan otomatis pada tanggal balik,
      sisi akun divalidasi menurut jenis, `reverse()` kini dapat bertanggal
- [x] **Persediaan bermuara jurnal** (`InventoryLedgerService`, kolom `kode_persediaan` & `kuantiti`
      pada baris jurnal): satu transaksi menghasilkan satu jurnal + satu pergerakan stok, stok terkunci
      (`lockForUpdate`) sehingga tidak dapat minus
- [x] **Seeder data awal** (`FinanceStandardSeeder`): unit YAYASAN/SEKOLAH/ASRAMA/KOPERASI, pemetaan unit
      bawaan 12 modul, dana bebas, dan unit penampung
- [x] **9 izin baru** yang diberikan mengikuti izin "saudaranya" (aset, penyusutan, akrual, persediaan,
      persetujuan tutup buku & void jurnal)

### Uji

- [x] `FinanceStandardApiTest` — **11 tes, 171 asersi**: seluruh endpoint baru diuji lewat HTTP
      (unit bisnis, dana, saldo awal, permohonan buka periode & void, laporan, aset & penyusutan, akrual,
      persediaan), termasuk penolakan 403 untuk pengguna tanpa izin dan 422 untuk permintaan cacat
- [x] `FinanceAccountingStandardTest` (12 tes, 93 asersi) dan `FinanceAccountingE2ETest` (10 tes,
      277 asersi) tetap hijau → **33 tes, 541 asersi** untuk modul Finance
- [x] Verifikasi peramban 7 layar baru pada desktop & seluler; memperbaiki kontras header tabel
      (3,82:1 → 5,32:1, berlaku seluruh aplikasi) dan tombol ikon tanpa `aria-label`

### Perbaikan modul BK (12 kegagalan fase 2 → 0)

Suite BK kini **69 tes, 285 asersi, HIJAU**. Akar masalahnya bukan satu, melainkan tujuh:

- [x] **23 tabel BK tidak punya kolom `school_id`** padahal 41 modelnya memakai `BelongsToSchool` yang
      mengisinya saat baris dibuat → begitu penyaringan unit menyala, pembuatan rekaman gagal
      ("no column named school_id"). Migrasi baru menambahkan kolomnya (nullable, ber-FK, ber-indeks)
- [x] **5 tabel BK memakai `SoftDeletes` tanpa kolom `deleted_at`** → setiap kueri gagal
      ("no such column: … deleted_at"); kolomnya ditambahkan
- [x] **`bk_audit_logs` tidak punya kolom `granted`** — keputusan ditolak/diberikan hanya tersimpan di
      dalam JSON `context`, sehingga penolakan tidak dapat disaring maupun dihitung; kini kolom tersendiri
- [x] **Konferensi tidak pernah bisa dibuat**: peserta bawaan ditambahkan hanya dengan jenisnya, tanpa
      pengguna/wali/siswa → `addParticipant` menolaknya. Peserta bawaan sekarang pengguna nyata
      (konselor penanggung jawab + pengaju), tanpa duplikat
- [x] **Perbandingan kerahasiaan konferensi terbalik**: konferensi yang lebih ketat daripada kasusnya
      justru ditolak, dan yang lebih terbuka lolos. Kini ditolak bila lebih TERBUKA, dan bawaannya
      mengikuti tingkat kasusnya
- [x] **Wali melihat seluruh konferensi anaknya** (termasuk rapat internal), dan daftar konferensi dijaga
      izin modul sehingga peserta yang sah menerima 403. Kini daftar disaring menurut keikutsertaan
      (wali menerima daftar kosong), dan rincian hanya untuk peserta serta tim BK
- [x] **Penolakan tidak meninggalkan jejak audit** pada jalur policy → kini dicatat
      (`VIEW_CONFIDENTIAL`, `granted = false`); endpoint rincian sesi konseling juga ditambahkan —
      sebelumnya permintaan atas sesi menjawab 404, bukan 403
- [x] **Early warning tidak pernah mencatat COOLDOWN**: operator `+` pada array membuat kunci cooldown
      tertimpa kunci tindakan, sehingga jejak "kenapa tidak ada peringatan kedua?" hilang; kini
      `array_merge` dan baris cooldown tidak dihitung sebagai kecocokan baru
- [x] **`approveDisposal()` mengembalikan baris permintaan, bukan baris keputusan** → pemeriksa hasil
      tidak pernah melihat keputusannya
- [x] **Izin fase 2 tidak disemai di basis uji** sehingga peran yang seharusnya berwenang ditolak;
      basis uji fase 2 kini menyemainya

### Masih berjalan

- [ ] Suite backend **penuh** (1.718 tes) sedang dijalankan di latar belakang; hasil akhirnya dicatat
      pada pesan berikutnya. Modul yang sudah diverifikasi sendiri: Finance 33/33, BK 69/69.

## Modul Asset & Sarpras — implementasi penuh (2026-12-17)

Modul baru `App\Modules\Asset`: 232 berkas, 18.683 baris, 60 tabel, 44 enum,
15 kontrak integrasi, 28 layanan, 17 controller (139 rute), 11 kebijakan,
12 peran & 41 izin, 5 perintah otomasi.

**Uji: `tests/Feature/Asset` — 47 tes, 166 asersi, HIJAU.**

Yang ditegakkan (bukan sekadar niat, semuanya beruji): tanpa penghapusan aset ·
pengakuan sebelum pencatatan (habis pakai ≠ aset) · 7 mesin keadaan ·
**satu aset satu pinjaman** (dijaga kunci unik basis data) · pemulangan wajib
periksa kondisi (rusak → laporan + pemeliharaan) · pemindahan wajib tujuan &
konfirmasi penerima · riwayat pemegang tidak ditimpa · booking bebas benturan
(kunci baris fasilitas) · penutupan fasilitas melaporkan booking terdampak ·
stok opname tidak mengubah aset sebelum disetujui · suku cadang dari Persediaan
(stok kurang → pekerjaan ditahan) · preventif idempoten · temuan inspeksi
melahirkan pemeliharaan · penghapusan tanpa persetujuan ditolak · usulan
penggantian hanya rekomendasi · pengunci versi · isolasi unit sekolah ·
angka keuangan hanya untuk pemegang izinnya · QR publik bebas data keuangan ·
jejak audit immutable · ekspor tercatat.

Integrasi modul lain lewat 15 kontrak + outbox `asset_integration_outbox`:
sekolah yang belum memasang Keuangan/Persediaan/Pengadaan tetap dapat memakai
modul ini, dan niat integrasi tidak pernah hilang. **Tanpa mesin persetujuan,
permintaan menunggu — tidak pernah disetujui diam-diam** (pilihan sadar).

Belum ada: **antarmuka pengguna** (seluruh endpoint siap, layar belum),
pemetaan ruang akademik, impor XLSX, integrasi pembaca RFID, definisi alur
Workflow, modul Vendor/Pengadaan, dan pemecahan otomasi ke job antrean.
Rinciannya pada `docs/22-MODUL-ASSET-SARPRAS.md`.

## Antarmuka Aset & Sarpras (2026-12-17)

16 halaman React di `frontend/src/features/asset` (24 berkas), grup menu
**Aset & Sarpras** (13 submenu), rute di `routes/domains/asset.tsx`.
`tsc -b` bersih, `vite build` sukses, dan **diuji pada peramban sungguhan**
(Chromium, masuk sebagai admin sekolah): dasbor, daftar, detail 10 tab,
fasilitas, pemeliharaan, penghapusan, laporan, lokasi, peminjaman, serta alur
stok opname (sesi → mulai → pindai kode tak dikenal → muncul sebagai selisih).

Pilihan yang disengaja pada layar: angka keuangan **tidak dirender** tanpa izin
(bukan disamarkan); layar penghitungan tidak meminta klik antar barang (kolom
pindai memegang fokus, kamera hanya jalan alternatif dengan pesan jujur bila
tidak didukung); label hanya memuat identitas fisik; alasan selalu ditanyakan
di tempat keputusan dibuat.

Pengujian peramban menemukan dua cacat yang tidak terlihat oleh `tsc` dan sudah
diperbaiki: daya tampung fasilitas yang datang sebagai objek pernah
meruntuhkan layar, dan balasan `/scan` berbentuk `{item, result}` membuat pesan
pindai menampilkan "undefined". `DatabaseSeeder` kini juga mendaftarkan
`AssetPermissionSeeder` + `AssetMasterSeeder` sehingga instalasi baru langsung
punya peran modul.

Belum: pemetaan ruang akademik, impor XLSX, integrasi RFID, definisi alur
Workflow, modul Vendor/Pengadaan, tabel ringkasan laporan, otomasi masih
sinkron. Rincian pada `docs/22-MODUL-ASSET-SARPRAS.md`.

---

## Aplikasi Mobile (wali murid · siswa · karyawan) — `mobile/`
- [x] Expo + React Native + TS + Expo Router (Android, iOS, export web)
- [x] Satu akun multi-peran: `user_identities`, `GET /auth/contexts`, `POST /auth/switch-context`, token ber-ability `ctx:*`
- [x] Penegakan konteks di server (`EnforceContext`, `RequireContext`); wali/siswa hanya `auth/*` + `mobile/*`
- [x] Endpoint `/mobile/*`: beranda, anak, absensi, nilai, rapor, jadwal, keuangan, asrama, presensi pegawai, notifikasi
- [x] Admin: tautkan identitas (`/users/{user}/identities`) + petunjuk kecocokan telepon/email
- [x] UI: login, pemilih peran, Beranda, Akademik, Keuangan, Presensi, Kabar, Akun (verifikasi `tsc` + export web + tangkapan layar)
- [ ] Tes backend `MobileContextTest` ditulis tetapi **belum dijalankan** (sandbox PHP 8.3, Laravel 13 butuh 8.4) — jalankan `php artisan test --filter=MobileContextTest`
- [ ] Layar admin web untuk menautkan identitas (API sudah ada)
- [ ] Push saat aplikasi tertutup (sengaja tanpa Firebase)

### Mobile — Fase 0 (fondasi lintas modul)
- [x] Resolver identitas tunggal `ContextService` (userable + `user_identities` + akun PPDB); `PpdbParentAccount::childStudentIds` (Keuangan, Asrama) kini mendelegasikan ke sana
- [x] Peran aktif per request (`ContextState`): `userable` ditimpa di memori sehingga modul Aktivitas/Klinik/UKS/LMS melihat identitas peran aktif tanpa diubah satu per satu
- [x] Role & permission per peran aktif (`User::hasPermissionTo/hasRole/getRoleNames/getAllPermissions` + `ContextPermissions`)
- [x] Allowlist path per peran wali/siswa di `config/mobile.php`; identitas dicek ulang tiap request (tautan dicabut → 401)
- [x] Agregator notifikasi: tabel Laravel (UKS, Aktivitas, LMS, Aset, Keuangan, Perpustakaan) + inbox Tahfiz, disaring per peran
- [x] Perbaikan bug: Tahfiz `DashboardService` dan BK `BkAccessService` memakai kolom `guardians.user_id` yang tidak ada
- [x] Mobile: registry fitur (`src/lib/features.ts`), tab Layanan, Akses cepat di Beranda, tombol kembali
- [ ] Tes backend (`MobileContextTest`, 10 skenario) **belum dijalankan** — sandbox PHP 8.3, Laravel 13 butuh 8.4
