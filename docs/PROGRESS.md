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

## Fase 6 — Hardening & Deploy
- [ ] Testing menyeluruh
- [ ] Optimasi performa
- [ ] Dokumentasi API
- [ ] Deployment produksi
