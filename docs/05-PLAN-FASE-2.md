# Syntaf School ERP — Rencana Fase 2 (Akademik, Kesiswaan & Kepegawaian)

> Dokumen ini adalah breakdown kerja Fase 2. Fase 2 memuat **banyak menu**, sehingga dipecah menjadi sub-fase **2A–2E** yang punya urutan ketergantungan jelas.
>
> Status ditandai: `[x]` selesai · `[-]` sedang dikerjakan · `[ ]` belum.

---

## 1. Ruang Lingkup

Fase 2 menutup tiga modul yang saat ini masih berupa halaman placeholder:

| Modul | Permission | Menu Group di Sidebar | Kondisi Awal |
|-------|-----------|----------------------|--------------|
| **Akademik** | `academic.*` | Akademik | 2 dari 7 menu jadi |
| **Kesiswaan** | `student.*` | Kesiswaan | 0 dari 6 menu jadi |
| **Kepegawaian** | `employee.*` | Kepegawaian | 0 dari 3 menu jadi |

**Yang TIDAK termasuk Fase 2** (jangan dikerjakan di sini): Keuangan, Penilaian/Rapor, Perpustakaan, Inventaris, Absensi Siswa, PPDB, Portal Siswa/Wali, Laporan & ekspor, Kalender Akademik.

---

## 2. Kondisi Awal (Hasil Audit)

### 2.1 Sudah Selesai

| Menu | Backend | Frontend | Catatan |
|------|---------|----------|---------|
| Periode Akademik | `AcademicController` (active, activateYear, activateSemester) | `AcademicPeriodsPage.tsx` | ✅ lengkap |
| Kelas & Rombel | `SchoolClassController` (CRUD + kelola siswa) | `ClassesPage.tsx`, `ClassFormModal.tsx`, `ClassStudentsModal.tsx` | ✅ lengkap |
| Manajemen Pengguna & Role | `UserController`, `RoleController` | `UsersPage.tsx`, `RolesPage.tsx` | ✅ lengkap (Fase 1E) |

### 2.2 Blocker yang Harus Diselesaikan Lebih Dulu

Ditemukan saat audit — **ini yang membuat Fase 2 tidak bisa langsung jalan**:

> **Status per A3:** blocker #1, #2, #3 sudah **selesai**; #4 sebagian (4 tabel Kesiswaan
> inti sudah dibuat, tabel lanjutan menunggu A4+).

1. ~~**Tabel `employees` kosong.**~~ → **selesai di 2A** (`EmployeeSeeder`, 10 pegawai)
2. ~~**Tabel `students` kosong.**~~ → **selesai di 2B/A3** (`StudentSeeder`, 40 siswa + 40 wali + 40 riwayat)
3. ~~**`StudentController` & `EmployeeController` hanya punya `index()`**~~ → **selesai**: keduanya CRUD penuh
4. **Tabel Kesiswaan & Kepegawaian lanjutan belum ada migrasinya** (lihat §5)

> **Konsekuensi urutan:** Kepegawaian (2A) dan Kesiswaan dasar (2B) harus dikerjakan **sebelum** menu Akademik lanjutan (2C), karena menu Akademik bergantung pada data pegawai dan siswa.

### 2.3 Peta Tabel yang Sudah Ada

| Tabel | Migrasi | Model | API | UI |
|-------|:-------:|:-----:|:---:|:--:|
| `academic_years`, `semesters` | ✅ | ✅ | ✅ | ✅ |
| `school_classes` | ✅ | ✅ | ✅ | ✅ |
| `class_students` | ✅ | ✅ | ✅ | ✅ |
| `class_subjects` | ✅ | ✅ | ✅ | ✅ |
| `schedules` | ✅ | ✅ | ✅ | ✅ |
| `students` | ✅ | ✅ | ✅ CRUD | ✅ |
| `guardians`, `student_guardians` | ✅ | ✅ | ✅ CRUD | ✅ |
| `student_histories` | ✅ | ✅ | ✅ | ✅ |
| `guardians`, `student_guardians` | ✅ | ✅ | ❌ | ❌ |
| `employees` | ✅ | ✅ | ⚠️ index saja | ❌ |
| `subjects`, `rooms`, `curricula`, `days` | ✅ | ✅ | ✅ (master) | ✅ |
| `violation_types`, `achievement_types`, `extracurriculars` | ✅ | ✅ | ✅ (master) | ✅ |
| `attendance_statuses`, `leave_types` | ✅ | ✅ | ✅ (master) | ✅ |

---

## 3. Peta Menu Fase 2

```
Akademik
├── Periode Akademik            ✅ sudah
├── Kelas & Rombel              ✅ sudah
├── Mata Pelajaran per Kelas    ✅ 2C
├── Jadwal Pelajaran            ✅ 2C
├── Kenaikan Kelas & Mutasi     ⬜ 2D
└── Beban Mengajar Guru         ⬜ 2E (opsional)

Kesiswaan
├── Data Siswa                  ✅ 2B
├── Wali Murid                  ✅ 2B
├── Pelanggaran & Poin          ⬜ 2D
├── Prestasi Siswa              ⬜ 2D
└── Ekstrakurikuler Siswa       ⬜ 2D

Kepegawaian
├── Data Pegawai                ✅ 2A
├── Absensi Pegawai             ⬜ 2E
└── Riwayat Jabatan             ⬜ 2E (opsional)
```

---

## 4. Breakdown Detail per Menu

Format tiap menu: **Route · Permission · Tabel · Endpoint · Frontend · Aturan bisnis · Kriteria selesai**.

---

### 2A — Kepegawaian Dasar

#### A1. Data Pegawai

| Item | Nilai |
|------|-------|
| Route | `/employees` (placeholder sudah ada, ganti implementasi) |
| Permission | `employee.view` / `employee.create` / `employee.update` / `employee.delete` |
| Tabel | `employees` (sudah ada) |
| Model | `App\Models\Employee` (sudah ada) |

**Endpoint:**

| Method | Path | Deskripsi |
|--------|------|-----------|
| GET | `/employees` | List + search (nama, NIP) + filter (`employee_type`, `is_teacher`, `is_active`) + paginasi |
| POST | `/employees` | Tambah pegawai |
| GET | `/employees/{employee}` | Detail + relasi |
| PUT | `/employees/{employee}` | Ubah |
| DELETE | `/employees/{employee}` | Soft delete |
| PATCH | `/employees/{employee}/toggle-active` | Aktif/nonaktif |
| POST | `/employees/{employee}/account` | Buat akun login (users polymorphic `userable`) |

**Frontend:** `features/employees/api/employeesApi.ts`, `EmployeesPage.tsx`, `EmployeeFormModal.tsx`, `EmployeeDetailPage.tsx` (tab: Profil · Beban Mengajar · Riwayat Jabatan)

**Aturan bisnis:**
- `nip` unik bila diisi, `email` unik bila diisi
- `employee_type` ∈ `guru` \| `staf`; `is_teacher` otomatis `true` bila type `guru`
- Pegawai yang punya relasi `school_classes.homeroom_teacher_id` tidak boleh dihapus keras → pakai soft delete
- Akun login opsional; bila dibuat, role default `guru` (atau `staf`)

**Kriteria selesai:** CRUD jalan · seeder berisi ≥ 8 pegawai contoh (guru + staf) · feature test CRUD + validasi unique NIP · `tsc` 0 error.

---

### 2B — Kesiswaan Dasar

> **STATUS: SELESAI** (dikerjakan sebagai tahap **A3** di [`06-BLUEPRINT-AKADEMIK.md`](./06-BLUEPRINT-AKADEMIK.md)).
> Ringkasan hasil & penyimpangan dari rencana awal:
>
> | Rencana di dokumen ini | Yang dibangun | Catatan |
> |---|---|---|
> | Form bertahap (stepper) 3 langkah | **Satu modal, 4 seksi** | Field banyak tapi tidak saling bergantung; seksi lebih cepat dipakai daripada stepper |
> | `GET /students/{s}/class-history` | `GET /students/{s}/histories` | Ikut aturan penamaan resource |
> | `POST /students/{s}/photo` | **belum dibuat** | Satu-satunya item 2B yang tertunda |
> | — | `PATCH /students/{s}/guardians/{g}` | Ditambahkan: perlu bisa mengubah hubungan & status wali utama tanpa melepas relasi |
> | — | `POST /students/{s}/histories` + `DELETE .../histories/{h}` | Ditambahkan: riwayat kelas perlu bisa diisi & dikoreksi |
> | — | `GET /guardians?student_id=&unlinked=` | Ditambahkan: filter wali per siswa & wali yang belum tertaut |
>
> Verifikasi: **37 test / 116 assertion PASS** · **42/42 pemeriksaan E2E HTTP lulus** ·
> data dev 40 siswa · 40 wali · 80 relasi · 40 riwayat.

#### B1. Data Siswa

| Item | Nilai |
|------|-------|
| Route | `/students` (placeholder sudah ada) |
| Permission | `student.view` / `student.create` / `student.update` / `student.delete` |
| Tabel | `students` (sudah ada) |

**Endpoint:**

| Method | Path | Deskripsi |
|--------|------|-----------|
| GET | `/students` | List + search (nama, NIS, NISN) + filter (`status_id`, `is_active`, `gender_id`, `school_class_id`) + paginasi |
| POST | `/students` | Tambah siswa |
| GET | `/students/{student}` | Detail lengkap |
| PUT | `/students/{student}` | Ubah |
| DELETE | `/students/{student}` | Soft delete |
| PATCH | `/students/{student}/toggle-active` | Aktif/nonaktif |
| POST | `/students/{student}/photo` | Upload foto |
| POST | `/students/{student}/account` | Buat akun portal siswa |
| GET | `/students/{student}/class-history` | Riwayat kelas (dari `student_histories`) |

**Frontend:** `features/students/api/studentsApi.ts`, `StudentsPage.tsx`, `StudentFormModal.tsx`, `StudentDetailPage.tsx` (tab: Biodata · Alamat · Wali · Riwayat Kelas · Pelanggaran · Prestasi)

**Aturan bisnis:**
- `nis` wajib & unik; `nisn` opsional & unik
- **Alamat berjenjang**: provinsi → kabupaten → kecamatan → kelurahan, memakai `SearchSelect` + `useLookupOptions` (pola sudah tersedia). Kolom `province_id`/`city_id`/`district_id`/`village_id` bertipe string dengan panjang 2/4/7/10 — pastikan panjang kode wilayah cocok dengan data `provinces`/`cities`/`districts`/`villages`.
- `birth_date` tidak boleh di masa depan
- Form dibuat **bertahap (stepper)**: Biodata → Alamat → Data Akademik, karena fieldnya banyak (±24 kolom)

**Kriteria selesai:** CRUD + upload foto jalan · seeder ≥ 20 siswa · dropdown wilayah berjenjang berfungsi · feature test CRUD + validasi unique NIS.

#### B2. Wali Murid

| Item | Nilai |
|------|-------|
| Route | `/students/guardians` (placeholder sudah ada) |
| Permission | `student.view` / `student.create` / `student.update` / `student.delete` |
| Tabel | `guardians`, `student_guardians` (sudah ada) |

**Endpoint:**

| Method | Path | Deskripsi |
|--------|------|-----------|
| GET | `/guardians` | List + search |
| POST | `/guardians` | Tambah wali |
| GET | `/guardians/{guardian}` | Detail + daftar anak |
| PUT | `/guardians/{guardian}` | Ubah |
| DELETE | `/guardians/{guardian}` | Soft delete |
| GET | `/students/{student}/guardians` | Wali dari seorang siswa |
| POST | `/students/{student}/guardians` | Hubungkan wali ke siswa (`guardian_relation_id`, `is_primary`) |
| DELETE | `/students/{student}/guardians/{guardian}` | Lepas relasi |

**Frontend:** `GuardiansPage.tsx`, `GuardianFormModal.tsx`, tab **Wali** di `StudentDetailPage.tsx`, komponen `LinkGuardianModal.tsx`

**Aturan bisnis:**
- Satu siswa **hanya boleh punya satu wali `is_primary = true`**. Bila menandai wali baru sebagai primary, otomatis turunkan yang lama (lakukan dalam `DB::transaction`).
- Kombinasi (`student_id`, `guardian_id`) unik — sudah dijaga constraint DB, tapi tetap validasi di request agar pesan error ramah.
- Satu wali boleh terhubung ke beberapa siswa (kakak-beradik)

**Kriteria selesai:** CRUD wali + hubungkan/lepas relasi · aturan satu primary teruji · feature test relasi + constraint primary.

---

### 2C — Akademik Lanjutan

#### C1. Mata Pelajaran per Kelas

| Item | Nilai |
|------|-------|
| Route | `/academics/class-subjects` |
| Permission | `academic.view` / `academic.update` |
| Tabel | `class_subjects` (sudah ada, belum dipakai) |

**Endpoint:**

| Method | Path | Deskripsi |
|--------|------|-----------|
| GET | `/classes/{class}/subjects` | Daftar mapel kelas + guru pengajar |
| POST | `/classes/{class}/subjects` | Tambah mapel (opsional langsung set guru) |
| PUT | `/classes/{class}/subjects/{classSubject}` | Ubah guru pengajar |
| DELETE | `/classes/{class}/subjects/{classSubject}` | Hapus mapel dari kelas |

**Frontend:** `ClassSubjectsModal.tsx` (pola meniru `ClassStudentsModal.tsx`), tab **Mapel** di `ClassesPage.tsx`, `features/academics/api/classSubjectsApi.ts`

**Aturan bisnis:**
- Unik per (`school_class_id`, `subject_id`, `academic_year_id`) — sudah ada constraint
- `employee_id` harus pegawai dengan `is_teacher = true`
- Sediakan aksi **"Salin dari kelas lain"** — input satu kelas jadi acuan, sangat menghemat waktu setup di awal tahun ajaran
- Mapel yang belum punya guru tetap boleh disimpan (guru menyusul)

**Kriteria selesai:** assign/ubah/hapus mapel per kelas jalan · filter guru hanya `is_teacher` · feature test CRUD + constraint unik.

#### C2. Jadwal Pelajaran

| Item | Nilai |
|------|-------|
| Route | `/academics/schedules` (placeholder sudah ada) |
| Permission | `academic.view` / `academic.create` / `academic.update` / `academic.delete` |
| Tabel | `schedules` (sudah ada, belum dipakai) |

**Endpoint:**

| Method | Path | Deskripsi |
|--------|------|-----------|
| GET | `/schedules` | List + filter (`school_class_id`, `semester_id`, `day_id`, `employee_id`) |
| POST | `/schedules` | Buat slot jadwal |
| PUT | `/schedules/{schedule}` | Ubah slot |
| DELETE | `/schedules/{schedule}` | Hapus slot |
| GET | `/classes/{class}/schedule` | Jadwal 1 kelas, dikelompokkan per hari |
| GET | `/employees/{employee}/schedule` | Jadwal mengajar 1 guru |

**Frontend:** `SchedulesPage.tsx` (grid hari × jam), `ScheduleFormModal.tsx`, `features/academics/api/schedulesApi.ts`

**Aturan bisnis (validasi bentrok — bagian terpenting):**
1. `end_time` harus lebih besar dari `start_time`
2. **Bentrok kelas**: kelas yang sama tidak boleh punya dua slot yang jamnya beririsan di hari & semester yang sama
3. **Bentrok guru**: guru yang sama tidak boleh mengajar dua kelas pada jam beririsan
4. **Bentrok ruangan**: ruangan yang sama tidak boleh dipakai dua kelas pada jam beririsan
5. Mapel yang dijadwalkan **harus sudah terdaftar** di `class_subjects` kelas tersebut (integritas dengan C1)
6. Jadwal terikat `semester_id` — saat semester berganti, jadwal semester baru dibuat dari salinan semester sebelumnya

**Catatan teknis:** deteksi bentrok cukup dengan query rentang beririsan `start_time < $end AND end_time > $start` pada `day_id` + `semester_id` yang sama, kecualikan id sendiri saat update.

**Kriteria selesai:** grid jadwal tampil rapi · keempat aturan bentrok teruji (masing-masing satu test) · guru/ruangan bentrok ditolak dengan pesan jelas.

---

### 2D — Proses & Catatan Siswa

#### D1. Kenaikan Kelas & Mutasi

| Item | Nilai |
|------|-------|
| Route | `/academics/promotions` |
| Permission | `academic.view` / `academic.update` |
| Tabel | `class_students` (ada) + `student_histories` (**baru**) |

**Endpoint:**

| Method | Path | Deskripsi |
|--------|------|-----------|
| GET | `/academic/promotions` | Pratinjau: siswa per kelas di tahun ajaran aktif |
| POST | `/academic/promotions` | Proses kenaikan massal (payload: mapping `student_id` → `target_class_id`) |
| POST | `/students/{student}/transfer` | Mutasi keluar/pindah (ubah status + catat riwayat) |
| GET | `/students/{student}/histories` | Riwayat kelas & mutasi |

**Frontend:** `PromotionPage.tsx` (tabel pilih-siswa → pilih kelas tujuan → konfirmasi), tab **Riwayat Kelas** di `StudentDetailPage.tsx`

**Aturan bisnis:**
- Proses massal **wajib** dalam `DB::transaction` — kalau satu gagal, semua dibatalkan
- Setiap perpindahan menulis satu baris `student_histories` (kelas asal, kelas tujuan, tahun ajaran, status, catatan)
- Siswa yang sudah punya kelas di tahun ajaran tujuan tidak boleh dipindah lagi tanpa konfirmasi eksplisit
- Mutasi keluar mengubah `class_students.status` menjadi `pindah`/`keluar`, bukan menghapus baris
- Tampilkan ringkasan hasil: berapa siswa berhasil, berapa gagal beserta alasannya

**Kriteria selesai:** pratinjau akurat · proses massal rollback saat ada kegagalan · riwayat tercatat · feature test proses massal (sukses + rollback).

#### D2. Pelanggaran & Poin

| Item | Nilai |
|------|-------|
| Route | `/students/violations` |
| Permission | `student.view` / `student.create` / `student.update` / `student.delete` |
| Tabel | `student_violations` (**baru**) |

**Endpoint:**

| Method | Path | Deskripsi |
|--------|------|-----------|
| GET | `/violations` | List + filter (`student_id`, `violation_type_id`, rentang tanggal) |
| POST | `/violations` | Catat pelanggaran |
| PUT | `/violations/{violation}` | Ubah |
| DELETE | `/violations/{violation}` | Hapus |
| GET | `/students/{student}/violations` | Pelanggaran seorang siswa + total poin |

**Frontend:** `ViolationsPage.tsx`, `ViolationFormModal.tsx`, tab **Pelanggaran** di `StudentDetailPage.tsx`

**Aturan bisnis:**
- `point` terisi otomatis dari `violation_types.point` (boleh ditimpa manual)
- Total poin dihitung agregat, tidak disimpan di tabel `students` (hindari data tidak sinkron)
- Sediakan ambang batas peringatan (mis. poin > 50 → tanda merah di daftar)

**Kriteria selesai:** CRUD + total poin akurat · tab di detail siswa jalan · feature test perhitungan poin.

#### D3. Prestasi Siswa

| Item | Nilai |
|------|-------|
| Route | `/students/achievements` |
| Permission | `student.*` |
| Tabel | `student_achievements` (**baru**) |

**Endpoint:** `GET/POST/PUT/DELETE /achievements`, `GET /students/{student}/achievements`

**Frontend:** `AchievementsPage.tsx`, `AchievementFormModal.tsx`, tab **Prestasi** di `StudentDetailPage.tsx`

**Aturan bisnis:** `achievement_type_id` dari master `achievement_types` · `level` (sekolah/kecamatan/kabupaten/provinsi/nasional/internasional) · `rank` (juara 1/2/3/harapan) · tanggal tidak boleh masa depan · upload foto sertifikat opsional.

**Kriteria selesai:** CRUD jalan · upload foto sertifikat · feature test CRUD.

#### D4. Ekstrakurikuler Siswa

| Item | Nilai |
|------|-------|
| Route | `/students/extracurriculars` |
| Permission | `student.*` |
| Tabel | `student_extracurriculars` (**baru**) |

**Endpoint:** `GET/POST/PUT/DELETE /student-extracurriculars` (filter `academic_year_id`, `extracurricular_id`) · `GET /students/{student}/extracurriculars`

**Frontend:** `StudentExtracurricularsPage.tsx`, tab **Ekstrakurikuler** di `StudentDetailPage.tsx`

**Aturan bisnis:** unik per (`student_id`, `extracurricular_id`, `academic_year_id`) · `grade` berupa nilai huruf A–D atau predikat.

**Kriteria selesai:** CRUD + rekap per ekskul per tahun ajaran · feature test constraint unik.

---

### 2E — Opsional (Kerjakan Bila 2A–2D Sudah Stabil)

#### E1. Absensi Pegawai

| Item | Nilai |
|------|-------|
| Route | `/employees/attendance` (placeholder sudah ada) |
| Permission | `employee.*` |
| Tabel | `employee_attendances` (**baru**) |

**Endpoint:** `GET /employee-attendances` (filter tanggal/pegawai) · `POST /employee-attendances` · `POST /employee-attendances/bulk` (input massal per tanggal) · `GET /employee-attendances/report`

**Frontend:** `EmployeeAttendancePage.tsx` (input harian ala absensi kelas)

**Aturan bisnis:** unik per (`employee_id`, `date`) · status dari master `attendance_statuses` · bila status izin/sakit, `leave_type_id` dari master `leave_types` wajib diisi.

#### E2. Beban Mengajar Guru

Route `/academics/teaching-load` · read-only · rekap dari `class_subjects` + `schedules`: guru X mengajar mapel apa di kelas mana, berapa jam per minggu. Berguna untuk kepala sekolah memeriksa pemerataan beban.

#### E3. Riwayat Jabatan

Tabel `employee_positions` (**baru**) · riwayat jabatan pegawai (jabatan, tanggal mulai, tanggal selesai). Prioritas rendah.

---

## 5. Migrasi & Tabel Baru

Enam tabel baru, dikelompokkan dalam 2 file migrasi agar konsisten dengan pola yang sudah ada (beberapa tabel per file, bukan satu tabel per file):

**`2026_09_XX_XXXXXX_create_student_record_tables.php`**

| Tabel | Kolom Inti |
|-------|-----------|
| `student_histories` | `id`, `student_id` FK, `school_class_id` FK nullable, `academic_year_id` FK, `status` enum(`naik`,`tinggal`,`pindah`,`keluar`,`lulus`), `notes`, timestamps |
| `student_violations` | `id`, `student_id` FK, `violation_type_id` FK, `date`, `description`, `point` int, `reported_by` FK employees nullable, `action_taken`, timestamps |
| `student_achievements` | `id`, `student_id` FK, `achievement_type_id` FK, `title`, `date`, `level` enum, `rank` string nullable, `organizer` string nullable, `photo_path` nullable, timestamps |
| `student_extracurriculars` | `id`, `student_id` FK, `extracurricular_id` FK, `academic_year_id` FK, `grade` string nullable, timestamps, **unique**(`student_id`,`extracurricular_id`,`academic_year_id`) |

**`2026_09_XX_XXXXXX_create_employee_record_tables.php`**

| Tabel | Kolom Inti |
|-------|-----------|
| `employee_attendances` | `id`, `employee_id` FK, `date`, `check_in` time nullable, `check_out` time nullable, `attendance_status_id` FK, `leave_type_id` FK nullable, `notes`, timestamps, **unique**(`employee_id`,`date`) |
| `employee_positions` | `id`, `employee_id` FK, `position`, `start_date`, `end_date` nullable, timestamps |

**Konvensi wajib** (mengikuti migrasi yang sudah ada):
- Semua FK memakai `constrained()` dengan aksi eksplisit: master → `restrictOnDelete()`, anak → `cascadeOnDelete()`, referensi opsional → `nullOnDelete()`
- Tabel yang punya `is_active` atau bersifat data pribadi memakai `softDeletes()`
- Tambahkan index pada kolom yang sering difilter (`student_id`, `date`)
- Selalu tulis `down()` yang membalik `up()` dengan urutan terbalik

**Seeder baru:** `StudentSeeder` (≥ 20 siswa), `EmployeeSeeder` (≥ 8 pegawai), `ClassSubjectSeeder`, `ScheduleSeeder`, `StudentRecordSeeder` (contoh pelanggaran/prestasi/ekskul).

---

## 6. Permission & RBAC

Semua menu Fase 2 **dapat memakai permission yang sudah ada** — tidak perlu seeder baru:

| Modul | Permission Tersedia | Dipakai Untuk |
|-------|--------------------|---------------|
| `academic` | view, create, update, delete | Periode, Kelas, Mapel per Kelas, Jadwal, Kenaikan Kelas |
| `student` | view, create, update, delete | Siswa, Wali, Pelanggaran, Prestasi, Ekskul |
| `employee` | view, create, update, delete | Pegawai, Absensi Pegawai |

**Catatan:** bila ingin memisahkan hak akses "Kenaikan Kelas" (biasanya hanya admin sekolah), tambahkan permission `academic.promote` di `RolePermissionSeeder` dan berikan hanya ke `super-admin` + `admin-sekolah`. Ini opsional.

**Role yang perlu diperiksa ulang:** `guru` dan `wali-kelas` punya `academic:view` + `student:view`. Pastikan mereka **hanya bisa melihat**, tidak bisa mengubah data siswa — uji dengan `RbacTest` tambahan.

---

## 7. Urutan Pengerjaan & Ketergantungan

```
2A Kepegawaian Dasar (Data Pegawai + seeder)
        │
        ├──▶ 2B Kesiswaan Dasar (Data Siswa + Wali Murid + seeder)
        │            │
        │            └──▶ 2D Proses & Catatan Siswa (Kenaikan Kelas, Pelanggaran, Prestasi, Ekskul)
        │
        └──▶ 2C Akademik Lanjutan (Mapel per Kelas → Jadwal Pelajaran)

2E Opsional (Absensi Pegawai, Beban Mengajar, Riwayat Jabatan) — setelah 2A–2D stabil
```

| Urutan | Sub-Fase | Alasan Urutan |
|:------:|----------|---------------|
| 1 | **2A** Data Pegawai | `employees` dipakai wali kelas & guru pengajar di Akademik — tanpa ini menu Akademik lanjutan buntu |
| 2 | **2B** Data Siswa + Wali Murid | `students` dipakai rombel, kenaikan kelas, semua catatan siswa |
| 3 | **2C** Mapel per Kelas | Dikerjakan sebelum Jadwal, karena jadwal memvalidasi mapel sudah terdaftar di kelas |
| 4 | **2C** Jadwal Pelajaran | Bergantung pada 2A (guru) + 2C mapel |
| 5 | **2D** Kenaikan Kelas | Bergantung pada 2B (siswa) + kelas tahun ajaran baru |
| 6 | **2D** Pelanggaran/Prestasi/Ekskul | Bergantung pada 2B saja — bisa jalan paralel dengan 2C |
| 7 | **2E** Opsional | Tidak memblokir apa pun |

**Aturan per sub-fase:** setiap menu harus tuntas **end-to-end** (migrasi → model → endpoint → halaman → test) sebelum lanjut. Jangan menumpuk banyak menu setengah jadi — itu yang membuat proyek sulit dilacak.

---

## 8. Definition of Done Fase 2

Sebuah menu dinyatakan selesai hanya bila **semua** poin ini terpenuhi:

- [ ] Migrasi jalan (`migrate` dan `migrate:rollback` bersih) untuk tabel baru
- [ ] Model dengan relasi lengkap (`belongsTo`/`hasMany`) + cast yang tepat
- [ ] FormRequest dengan aturan validasi + `attributes()` berbahasa Indonesia
- [ ] Controller dengan otorisasi `permission:` di **setiap** route
- [ ] API Resource untuk membentuk respons (jangan kembalikan model mentah)
- [ ] Halaman frontend dengan state loading, kosong, dan error
- [ ] **Feature test** untuk CRUD + aturan bisnis khusus (bentrok jadwal, primary wali, rollback kenaikan kelas)
- [ ] `php artisan test` hijau seluruhnya
- [ ] `npx tsc --noEmit` 0 error
- [ ] Entri menu di `frontend/src/constants/menu.ts` dan route di `frontend/src/routes/index.tsx` sudah ditambahkan
- [ ] `docs/PROGRESS.md` diperbarui

> Poin **feature test** bukan formalitas. Audit awal menemukan test hanya mencakup RBAC — `MasterDataController` yang menyangga 40 resource pun belum punya test. Fase 2 adalah kesempatan memperbaiki kebiasaan itu.

---

## 9. Risiko & Catatan Teknis

| # | Risiko | Mitigasi |
|---|--------|----------|
| 1 | **Registry master data ganda** — `config/master_data.php` (PHP) dan `constants/masterData.ts` (TS) harus dicerminkan manual | Untuk menu Fase 2 yang kompleks (siswa, jadwal), **jangan** paksakan masuk pola generik. Pakai controller & halaman khusus. Pola generik hanya untuk master data sederhana. |
| 2 | **Whitelist `filter` tidak ditegakkan** di `MasterDataController` (temuan audit) | ✅ **Sudah diperbaiki** pada 2A — `$config['filter']` kini benar-benar ditegakkan |
| 3 | **Belum ada Git** | Inisialisasi Git + commit baseline **sebelum** menulis kode Fase 2 |
| 4 | Validasi bentrok jadwal punya banyak kombinasi | Tulis test per jenis bentrok (kelas, guru, ruangan, jam terbalik). Jangan gabung jadi satu test. |
| 5 | Kode wilayah (`provinces`/`cities`/`districts`/`villages`) panjang kode 2/4/7/10 berbeda dari `students` | Verifikasi panjang kolom cocok sebelum membangun dropdown berjenjang. Uji dengan data wilayah asli, bukan data karangan. |
| 6 | Form siswa punya ±24 kolom → UI berat | Pakai stepper/tab, jangan satu form panjang |
| 7 | Kenaikan kelas massal berisiko merusak data | Wajib `DB::transaction` + halaman pratinjau + konfirmasi eksplisit sebelum eksekusi |

---

## 10. Checklist Progres Fase 2

### 2A — Kepegawaian Dasar
- [x] Perbaiki whitelist `filter` di `MasterDataController`
- [x] `EmployeeSeeder` + data contoh (10 pegawai: 8 guru, 2 staf)
- [x] CRUD pegawai (backend)
- [x] Halaman Data Pegawai + form (frontend)
- [ ] Detail pegawai + tab *(ditunda — daftar + modal sudah mencukupi untuk 2A)*
- [x] Akun login pegawai (endpoint `POST /employees/{id}/account`)
- [x] Feature test pegawai (12 test, 30 assertion)

### 2B — Kesiswaan Dasar
- [ ] `StudentSeeder` + data contoh
- [ ] CRUD siswa (backend)
- [ ] Halaman Data Siswa + form bertahap (frontend)
- [ ] Detail siswa + tab
- [ ] Dropdown wilayah berjenjang
- [ ] Upload foto siswa
- [ ] CRUD wali murid (backend)
- [ ] Halaman Wali Murid (frontend)
- [ ] Relasi siswa ↔ wali (satu primary)
- [ ] Feature test siswa + wali

### 2C — Akademik Lanjutan
- [x] Mapel per kelas (backend)
- [x] Modal Mapel per Kelas (frontend)
- [x] Salin mapel dari kelas lain
- [x] Jadwal pelajaran (backend) — CRUD + grid + `check-conflict` + perubahan jadwal
- [x] Validasi bentrok kelas/guru/ruangan (+ jam terbalik)
- [x] Grid jadwal (frontend) — per kelas / guru / ruang, dengan penanda perubahan per tanggal
- [x] Jam pelajaran (`time_slots`) + `TimeSlotSeeder` (47 slot Senin–Jumat)
- [x] Guru pengganti / pindah jam / pembatalan (`schedule_changes`)
- [x] Feature test mapel (12 test) · [x] feature test jadwal (**33 test, 113 assertion**)

### 2D — Proses & Catatan Siswa
- [ ] Migrasi `student_histories`, `student_violations`, `student_achievements`, `student_extracurriculars`
- [ ] Pratinjau kenaikan kelas
- [ ] Proses kenaikan kelas massal (transaksi)
- [ ] Mutasi siswa + riwayat kelas
- [ ] Pelanggaran & poin
- [ ] Prestasi siswa
- [ ] Ekstrakurikuler siswa
- [ ] Feature test kenaikan kelas + rollback

### 2E — Opsional
- [ ] Migrasi `employee_attendances`, `employee_positions`
- [ ] Absensi pegawai
- [ ] Beban mengajar guru
- [ ] Riwayat jabatan

### Penutup
- [ ] `php artisan test` hijau
- [ ] `npx tsc --noEmit` 0 error
- [ ] Menu & route frontend terdaftar
- [ ] `PROGRESS.md` diperbarui

---

## 11. Dokumen Terkait

- [`01-PLAN-UTAMA.md`](./01-PLAN-UTAMA.md) — Rencana utama, arsitektur, roadmap
- [`02-PLAN-MASTER-DATA.md`](./02-PLAN-MASTER-DATA.md) — Rencana 40 tabel master data
- [`03-RANCANGAN-DATABASE.md`](./03-RANCANGAN-DATABASE.md) — Skema database, relasi, ERD
- [`04-SPESIFIKASI-API-FRONTEND.md`](./04-SPESIFIKASI-API-FRONTEND.md) — Spesifikasi REST API & struktur frontend
- [`PROGRESS.md`](./PROGRESS.md) — Progres implementasi keseluruhan
