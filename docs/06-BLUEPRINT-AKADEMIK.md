# Syntaf School ERP — Blueprint Modul Akademik

> Dokumen ini memetakan **modul Akademik lengkap** (8 domain, 35 area) ke kode yang benar-benar ada di repo ini, menetapkan **5 engine inti**, dan menyusun pentahapan yang realistis.
>
> Dokumen ini **melengkapi** [`05-PLAN-FASE-2.md`](./05-PLAN-FASE-2.md). Fase 2 tetap jadi kerangka eksekusi; dokumen ini yang menentukan isi detail modul Akademik.

---

## 1. Prinsip

**1.1 Akademik bukan kumpulan menu CRUD.** Yang membuat sebuah ERP akademik terasa sungguhan adalah **engine** — komponen yang memegang aturan dan perhitungan, dipakai bersama oleh banyak menu. Karena itu lima engine berikut diperlakukan sebagai komponen inti, bukan halaman:

| Engine | Tanggung jawab | Kalau tidak dibuat sebagai engine |
|--------|---------------|-----------------------------------|
| **Curriculum Engine** | Struktur kurikulum per jenjang/kelas, alokasi JP, prasyarat mapel, versi kurikulum aktif | Struktur kurikulum jadi tabel statis yang tidak bisa berubah antar tahun ajaran |
| **Grading Engine** | Formula nilai, bobot komponen, KKM/KKTP, konversi skor → grade/predikat, pembulatan | Rumus nilai tersebar di controller; ubah kebijakan penilaian = ubah banyak tempat |
| **Assessment Engine** | Tipe & komponen penilaian, rubrik, threshold, aturan remedial, skema penilaian per kurikulum | Setiap jenis penilaian punya tabel & logika sendiri |
| **Promotion Engine** | Syarat naik kelas, academic standing, keputusan naik/tinggal/lulus, approval | Kenaikan kelas jadi skrip sekali pakai yang tidak bisa diaudit |
| **Academic Calendar Engine** | Kalender akademik, hari efektif, pekan ujian, event akademik | Hari efektif dihitung manual tiap semester |

**1.2 Buku nilai bukan tempat menyimpan kebijakan.** Nilai mentah disimpan; kebijakan (bobot, formula, KKM, konversi) disimpan sebagai **konfigurasi berversi**. Konsekuensinya: mengubah kebijakan penilaian tidak boleh mengubah nilai yang sudah dipublikasikan.

**1.3 Tidak ada nilai tanpa jejak audit.** Setiap perubahan nilai, keputusan kenaikan kelas, dan approval wajib tercatat (siapa, kapan, nilai sebelum/sesudah, alasan).

**1.4 Jangan bangun engine sebelum ada data yang mengalir.** Engine Grading tanpa satu pun penilaian nyata hanya menghasilkan abstraksi yang salah. Urutan di §8 menempatkan engine setelah modul dasarnya berjalan.

---

## 2. Kondisi Awal — Hasil Audit

Ini bagian terpenting dari dokumen ini. **Sebagian besar fondasi akademik belum punya data sama sekali.**

### 2.1 Tabel sudah ada, data kosong (memblokir)

> **Diperbarui setelah A0–A3.** Tabel berikut sudah terisi dan tidak lagi memblokir:
> `subjects` (23), `rooms` (14), `provinces`/`cities`/`districts`/`villages` (5/14/24/18),
> `achievement_types` (12), `class_subjects` (41), `time_slots` (47), `schedules` (39),
> `students` (40), `guardians` (40), `student_guardians` (80), `student_histories` (40),
> `class_students` (40).

**Tidak ada lagi tabel kosong yang memblokir.** Domain kesiswaan sudah punya data nyata
sehingga Presensi (A6), Penilaian (A7), Rapor (A9), dan Kenaikan Kelas (A10) bisa diuji
end-to-end.

### 2.2 Tabel sudah ada dan berisi data (siap pakai)

| Tabel | Baris | Catatan |
|-------|:-----:|---------|
| `curricula` | 2 | K13 + Kurikulum Merdeka (Merdeka aktif) |
| `education_levels` | 15 | SD/SMP/SMA/SMK × tingkat |
| `academic_years` | 1 | 2025/2026 (aktif) |
| `semesters` | 2 | Ganjil (aktif) + Genap |
| `days` | 7 | Senin–Minggu |
| `employees` | 10 | 8 guru + 2 staf (dari 2A) |
| `school_classes` | 4 | X IPA 1/2, XI IPA 1, XII IPA 1 |
| `students` | 40 | 10 siswa per kelas (dari A3) |
| `guardians` | 40 | 20 keluarga × ayah+ibu (dari A3) |
| `student_guardians` | 80 | tepat 40 bertanda wali utama |
| `student_histories` | 40 | satu per siswa untuk tahun ajaran aktif |
| `class_students` | 40 | seluruh siswa sudah punya rombel |
| `student_statuses` | 5 | — |
| `violation_types` | 6 | — |
| `extracurriculars` | 12 | — |
| `attendance_statuses` / `leave_types` | 5 / 4 | — |

> **Kesimpulan audit:** langkah pertama modul Akademik **bukan** membangun engine, melainkan **mengisi fondasi data yang kosong**. Tanpa mapel, ruang, dan wilayah, semua fitur akademik di atasnya tidak bisa diuji.

### 2.3 Yang sudah jadi

| Fitur | Status |
|-------|--------|
| Periode Akademik (tahun ajaran & semester, aktivasi tunggal) | ✅ |
| Kelas & Rombel (CRUD + kelola siswa) | ✅ |
| Data Pegawai (CRUD + akun login + seeder) | ✅ |
| Mapel per Kelas (termasuk salin dari kelas lain) | ✅ |
| Jam Pelajaran + Jadwal Pelajaran (grid mingguan, 4 deteksi bentrok, guru pengganti) | ✅ |
| Master data 40 resource (config-driven) | ✅ |
| RBAC 10 role / 12 modul / 47 permission | ✅ |

---

## 3. Pemetaan 8 Domain → Kode

Legenda status: ✅ ada · 🔶 sebagian · ⬜ belum · 🆕 butuh tabel baru

### Domain 1 — Academic Master & Curriculum

| Area | Tabel | Status |
|------|-------|--------|
| Tahun ajaran, Semester | `academic_years`, `semesters` | ✅ |
| Kurikulum | `curricula` | ✅ |
| Mata pelajaran | `subjects` | 🔶 tabel ada, **data kosong** |
| Tingkat/jenjang | `education_levels` | ✅ |
| Ruang kelas | `rooms` | 🔶 tabel ada, **data kosong** |
| **Kelompok mata pelajaran** | `subject_groups` | 🆕 |
| **Program/jurusan/peminatan** | `programs` | 🆕 |
| **Jam pelajaran** | `time_slots` | 🆕 |
| **Kalender akademik** | `academic_events` | 🆕 |
| **Hari efektif** | `effective_days` | 🆕 |
| **Kompetensi / CP / TP** | `competencies` | 🆕 |
| **Kriteria ketuntasan (KKM/KKTP)** | `passing_grade_rules` | 🆕 |
| **Bobot penilaian** | `assessment_weights` | 🆕 |
| **Skala nilai & grade** | `grade_scales`, `grade_scale_items` | 🆕 |

### Domain 2 — Student Academic Management

| Area | Tabel | Status |
|------|-------|--------|
| Profil akademik siswa, NIS/NISN | `students` | ✅ CRUD penuh + wilayah berjenjang (A3) |
| Status siswa, Angkatan | `students` | ✅ |
| Kelas/rombel | `class_students` | ✅ |
| Riwayat kelas / kenaikan / pindah | `student_histories` | ✅ A3 |
| Riwayat akademik & kurikulum | `student_histories` | 🔶 riwayat kelas jalan, kurikulum menunggu A5 |
| Wali murid & relasi | `guardians`, `student_guardians` | ✅ A3, satu wali utama per siswa |
| Program/peminatan siswa | `students.program_id` | 🆕 |
| Academic standing | `student_academic_standings` | 🆕 |

### Domain 3 — Class, Schedule & Resource Management

| Area | Tabel | Status |
|------|-------|--------|
| Daftar rombel, wali kelas, kapasitas | `school_classes` | ✅ |
| Siswa dalam rombel | `class_students` | ✅ |
| Mapel per kelas + guru pengampu | `class_subjects` | 🔶 tabel ada, **belum ada API/UI** |
| Jadwal kelas/guru/ruang/mapel | `schedules` | 🔶 tabel ada, **belum ada API/UI** |
| Deteksi bentrok guru/kelas/ruang/jam | — | ⬜ logika validasi |
| Penggantian & pembatalan jadwal, guru pengganti | `schedule_changes` | 🆕 |
| Kelompok belajar / praktikum | `study_groups` | 🆕 |
| Booking ruang & konflik penggunaan | `room_bookings` | 🆕 |

### Domain 4 — Teaching & Learning

| Area | Tabel | Status |
|------|-------|--------|
| Guru pengampu, kelas & mapel diajar, beban mengajar | `class_subjects` + `schedules` | 🔶 (turunan, tanpa tabel baru) |
| Jurnal mengajar | `teaching_journals` | 🆕 |
| RPP / modul ajar, CP/TP/ATP, media | `lesson_plans` | 🆕 |
| Presensi mengajar, realisasi JP | `teaching_journals` | 🆕 |
| Assignment & submission | `assignments`, `assignment_submissions` | 🆕 |
| E-learning / LMS (materi, video, course, progress) | — | ⬜ **di luar scope** — lihat §9 |

### Domain 5 — Assessment & Examination

| Area | Tabel | Status |
|------|-------|--------|
| Penilaian (harian, tugas, kuis, UTS, UAS, praktik, proyek, portofolio) | `assessments` | 🆕 |
| Nilai siswa | `scores` | 🆕 |
| Penilaian sikap / keterampilan / pengetahuan | `assessments.category` | 🆕 |
| Bobot, formula, KKM/KKTP, grade, predikat | engine, lihat §4.2 | 🆕 |
| Remedial & pengayaan | `remedials` | 🆕 |
| Bank soal, kisi-kisi, paket ujian, randomisasi | `questions`, `question_packages` | 🆕 |
| Jadwal/ruang/peserta/pengawas ujian, berita acara | `exam_schedules` | 🆕 |
| Approval & lock nilai, revisi + audit | `score_revisions` | 🆕 |

### Domain 6 — Attendance & Student Progress

| Area | Tabel | Status |
|------|-------|--------|
| Presensi siswa (hadir/izin/sakit/alpa/dispensasi/terlambat) | `student_attendances` | 🆕 |
| Presensi per mapel / per hari / per semester | `student_attendances` | 🆕 |
| Presensi guru | `employee_attendances` | 🆕 |
| Rekap, persentase kehadiran | turunan | 🆕 |
| Koreksi presensi + approval + riwayat | `attendance_corrections` | 🆕 |
| Academic risk / early warning | `academic_alerts` | 🆕 |
| Bimbingan akademik | `academic_advisories` | 🆕 |

### Domain 7 — Promotion, Graduation & Academic Documents

| Area | Tabel | Status |
|------|-------|--------|
| Kenaikan kelas, tinggal kelas, keputusan & approval | `promotion_decisions` | 🆕 |
| Kelulusan, nomor & tanggal, dokumen | `graduations` | 🆕 |
| Mutasi masuk/keluar, registrasi ulang, re-entry, alumni | `student_histories` + `students.status_id` | 🆕 |
| Rapor (semester/tengah/akhir), deskripsi, tanda tangan | `report_cards` | 🆕 |
| Transkrip akademik | `transcripts` (atau turunan `scores`) | 🆕 |
| Dokumen akademik (surat aktif, pindah, keterangan, sertifikat, legalisasi) | `academic_documents` | 🆕 |

### Domain 8 — Analytics, Workflow & Reporting

| Area | Tabel | Status |
|------|-------|--------|
| Dashboard & analytics akademik | turunan (query agregat) | 🆕 |
| Workflow & multi-level approval | `approval_requests`, `approval_steps` | 🆕 |
| Academic audit trail | `activity_log` (spatie, **sudah terpasang**) | 🔶 tinggal dipakai |
| Reporting & export | `maatwebsite/excel` + `barryvdh/laravel-dompdf` (**belum diinstall**) | 🆕 |

---

## 4. Spesifikasi 5 Engine Inti

### 4.1 Curriculum Engine

**Menjawab:** "Mapel apa saja yang wajib diambil kelas X IPA 1 pada Kurikulum Merdeka semester ganjil, dan berapa jam per pekan?"

| Tabel | Isi |
|-------|-----|
| `subject_groups` | Kelompok mapel (Wajib, MIPA, IPS, Bahasa, Muatan Lokal) |
| `curriculum_structures` | Struktur per (kurikulum, jenjang, tingkat) |
| `curriculum_structure_items` | Baris struktur: mapel + kelompok + alokasi JP + semester |
| `subject_prerequisites` | Mapel prasyarat |
| `programs` | Jurusan/peminatan (IPA, IPS, Bahasa) |

**API inti:** `GET /curricula/{id}/structures`, `GET /classes/{id}/curriculum`, `POST /classes/{id}/apply-curriculum`
**Keputusan desain:** struktur kurikulum **berversi per tahun ajaran**. Mengubah struktur tidak boleh mengubah kelas yang sudah berjalan.

### 4.2 Grading Engine

**Menjawab:** "Dengan bobot Tugas 20%, UTS 30%, UAS 40%, Praktik 10%, dan KKTP 75 — berapa nilai akhir dan predikat siswa ini?"

| Tabel | Isi |
|-------|-----|
| `grade_scales` | Skala nilai per (kurikulum, jenjang), mis. skala 0–100 atau 0–4 |
| `grade_scale_items` | Pemetaan rentang → grade huruf → predikat (A/90–100/Sangat Baik) |
| `assessment_weights` | Bobot per komponen penilaian |
| `passing_grade_rules` | KKM/KKTP per (mapel, tingkat, tahun ajaran) |
| `grading_formulas` | Formula & aturan pembulatan, **berversi** |

**Aturan:** nilai akhir dihitung dari nilai mentah + konfigurasi **pada saat itu**. Konfigurasi yang diubah tidak boleh mengubah nilai yang sudah dipublikasikan — simpan `grading_formula_id` pada hasil akhir.
**API inti:** `POST /grading/preview` (hitung tanpa simpan), `POST /grading/publish`, `GET /grade-scales`

### 4.3 Assessment Engine

**Menjawab:** "Jenis penilaian apa yang boleh dipakai untuk mapel ini, dan bagaimana aturan remedialnya?"

| Tabel | Isi |
|-------|-----|
| `assessment_types` | Harian, Tugas, Kuis, PTS, PAS, Praktik, Proyek, Portofolio |
| `assessment_components` | Komponen dalam satu penilaian (mis. rubrik dengan kriteria) |
| `assessment_rubrics` | Kriteria + indikator + level capaian |
| `remedial_rules` | Ambang remedial, jumlah percobaan maksimum, nilai maksimum remedial |

**Keputusan desain:** satu tabel `assessments` untuk semua jenis penilaian dengan `assessment_type_id` — **bukan** tabel terpisah per jenis. Jenis penilaian adalah data, bukan skema.

### 4.4 Promotion Engine

**Menjawab:** "Siswa ini naik kelas, tinggal kelas, atau lulus — dan atas dasar apa?"

| Tabel | Isi |
|-------|-----|
| `promotion_rules` | Syarat: nilai minimum, kehadiran minimum, status remedial, batas poin pelanggaran |
| `promotion_decisions` | Keputusan per siswa + alasan + siapa yang memutuskan |
| `promotion_approvals` | Approval berjenjang (wali kelas → sekolah) |

**Aturan:** keputusan **tidak boleh dihitung ulang** setelah disetujui — simpan snapshot kondisi saat keputusan diambil (nilai, kehadiran, poin) agar bisa diaudit bertahun-tahun kemudian.

### 4.5 Academic Calendar Engine

| Tabel | Isi |
|-------|-----|
| `academic_events` | Awal/akhir semester, libur, PTS, PAS, orientasi, rapat guru, pembagian rapor |
| `effective_days` | Hari efektif per (semester, tingkat) |
| `event_types` | Jenis event |

**API inti:** `GET /academic-calendar?semester_id=`, `GET /effective-days?semester_id=`
**Kaitan:** presensi & jurnal mengajar memakai hari efektif untuk menghitung persentase kehadiran yang benar.

---

## 5. Tabel Baru — Rekapitulasi

**Total 34 tabel baru**, dikelompokkan dalam 8 file migrasi agar konsisten dengan pola repo (beberapa tabel per file).

| # | File Migrasi | Tabel |
|---|--------------|-------|
| 1 | `create_curriculum_structure_tables` | `subject_groups`, `programs`, `curriculum_structures`, `curriculum_structure_items`, `subject_prerequisites` |
| 2 | `create_academic_time_tables` | `time_slots`, `academic_events`, `event_types`, `effective_days` |
| 3 | `create_grading_tables` | `grade_scales`, `grade_scale_items`, `assessment_types`, `assessment_weights`, `passing_grade_rules`, `grading_formulas` |
| 4 | `create_student_academic_tables` | `student_histories`, `student_academic_standings`, `academic_advisories`, `academic_alerts` |
| 5 | `create_assessment_tables` | `assessments`, `assessment_components`, `assessment_rubrics`, `scores`, `remedials`, `score_revisions` |
| 6 | `create_exam_tables` | `questions`, `question_packages`, `question_package_items`, `exam_schedules`, `exam_participants`, `exam_rooms` |
| 7 | `create_attendance_tables` | `student_attendances`, `employee_attendances`, `attendance_corrections` |
| 8 | `create_academic_output_tables` | `report_cards`, `report_card_items`, `transcripts`, `promotion_rules`, `promotion_decisions`, `graduations`, `academic_documents`, `teaching_journals`, `lesson_plans`, `assignments`, `assignment_submissions`, `schedule_changes`, `study_groups`, `room_bookings` |

**Konvensi wajib** (mengikuti migrasi yang ada):
- `constrained()` dengan aksi eksplisit: master → `restrictOnDelete()`, anak → `cascadeOnDelete()`, referensi opsional → `nullOnDelete()`
- `softDeletes()` untuk data pribadi & data yang punya `is_active`
- Index pada kolom filter yang sering dipakai (`student_id`, `date`, `semester_id`)
- `down()` selalu membalik `up()` dengan urutan terbalik

---

## 6. Sitemap Menu Akademik

Struktur menu sidebar yang dituju (menggantikan 3 menu Akademik saat ini):

```
Akademik
├── Periode Akademik                    ✅
├── Kurikulum
│   ├── Kurikulum & Versi               (curricula — master)
│   ├── Kelompok Mapel                  🆕
│   ├── Struktur Kurikulum              🆕
│   ├── Program & Peminatan             🆕
│   └── Prasyarat Mapel                 🆕
├── Kelas & Rombel                      ✅
│   ├── Daftar Kelas                    ✅
│   ├── Mapel per Kelas                 ✅
│   └── Kelompok Belajar                🆕
├── Data Siswa                          ✅
│   ├── Biodata & Wilayah Berjenjang    ✅
│   ├── Wali Murid                      ✅
│   ├── Riwayat Kelas                   ✅
│   ├── Upload Foto Siswa               🆕
│   └── Akun Portal Siswa               ✅
├── Jadwal Pelajaran                    ✅
│   ├── Jadwal per Kelas                ✅
│   ├── Jadwal per Guru                 ✅
│   ├── Jadwal per Ruang                ✅
│   └── Perubahan & Guru Pengganti      ✅
├── Jam Pelajaran                       ✅
├── Kalender Akademik                   🆕
│   ├── Event Akademik                  🆕
│   └── Hari Efektif                    🆕
├── Penilaian
│   ├── Jenis & Bobot Penilaian         🆕
│   ├── Skala Nilai & Grade             🆕
│   ├── KKM / KKTP                      🆕
│   └── Input Nilai                     🆕
├── Ujian
│   ├── Bank Soal                       🆕
│   ├── Paket Ujian                     🆕
│   └── Jadwal Ujian                    🆕
├── Presensi Siswa                      🆕
├── Kenaikan Kelas                      🆕
├── Kelulusan                           🆕
├── Rapor & Transkrip                   🆕
└── Jurnal Mengajar                     🆕
```

---

## 7. Permission Tambahan yang Dibutuhkan

Modul `academic` saat ini hanya punya `view/create/update/delete`. Untuk Akademik lengkap perlu ditambah di `RolePermissionSeeder`:

| Permission baru | Untuk |
|-----------------|-------|
| `academic.publish` | Publikasi nilai & rapor |
| `academic.approve` | Approval kenaikan kelas, kelulusan, koreksi presensi |
| `academic.promote` | Menjalankan proses kenaikan kelas |
| `academic.grade` | Input & revisi nilai (guru) |
| `academic.attendance` | Input & koreksi presensi |

Role yang terpengaruh: `guru` mendapat `academic.grade` + `academic.attendance`; `kepala-sekolah` mendapat `academic.approve`; `admin-sekolah` mendapat semuanya.

> Menambah permission **wajib** menjalankan ulang `RolePermissionSeeder`. Karena `syncPermissions()` dipakai, role yang sudah ada akan disinkronkan ulang — aman, tapi perlu `db:seed --class=RolePermissionSeeder` setelah deploy.

---

## 8. Pentahapan Realistis

**Prinsip pentahapan:** setiap tahap harus menghasilkan sesuatu yang **bisa dipakai & diuji**, bukan setengah jadi.

| Tahap | Isi | Ketergantungan | Status |
|:-----:|-----|----------------|--------|
| **A0** | **Seeder fondasi**: wilayah, mapel, ruang, jenis prestasi | — | ✅ |
| **A1** | **Mapel per Kelas** (`class_subjects`) + beban mengajar guru | A0 | ✅ |
| **A2** | **Jadwal Pelajaran** + 4 deteksi bentrok + jam pelajaran (`time_slots`) | A1 | ✅ |
| **A3** | **Data Siswa & Wali Murid** + riwayat kelas (`student_histories`) | A0 (wilayah) | ✅ |
| **A4** | **Kalender Akademik + Hari Efektif** | — | ⬜ |
| **A5** | **Kurikulum Engine**: kelompok mapel, struktur kurikulum, prasyarat, program | A1 | ⬜ |
| **A6** | **Presensi Siswa & Guru** + koreksi & approval | A2, A3, A4 | ⬜ |
| **A7** | **Grading + Assessment Engine**: jenis, bobot, skala nilai, KKM, input nilai, remedial | A5, A6 | ⬜ |
| **A8** | **Bank Soal & Ujian** | A7 | ⬜ |
| **A9** | **Rapor, Transkrip, Dokumen Akademik** (+ PDF) | A7 | ⬜ |
| **A10** | **Promotion Engine**: kenaikan kelas, kelulusan, approval | A7 | ⬜ |
| **A11** | **Jurnal Mengajar, RPP, Assignment** | A2, A3 | ⬜ |
| **A12** | **Analytics, Early Warning, Workflow, Export/Import** | A7, A9 | ⬜ |

**Kenapa A0–A3 sebelum A4?** Karena mapel, ruang, jadwal, dan data siswa tidak saling memblokir secara melingkar, sementara presensi (A6) membutuhkan jadwal (A2) **dan** siswa (A3) sekaligus. Mengerjakan yang tidak punya ketergantungan lebih dulu menghindari pekerjaan yang macet di tengah.

**Kenapa engine (A5, A7) baru di tengah?** Karena engine butuh data nyata untuk diuji. Membangun Grading Engine sebelum ada satu pun nilai hanya menghasilkan abstraksi yang salah dan harus ditulis ulang.

---

## 9. Yang Sengaja TIDAK Dikerjakan (dan Alasannya)

Jujur soal batas itu lebih berguna daripada menjanjikan semuanya.

| Item | Alasan |
|------|--------|
| **E-Learning / LMS** (materi, video, course, progress, diskusi) | Ini produk tersendiri, bukan modul ERP. Lebih baik integrasi ke LMS yang sudah ada (Moodle/Google Classroom) daripada membangun setengah matang |
| **Integrasi SIAKAD / sistem pemerintah (Dapodik)** | Butuh kredensial & spesifikasi resmi dari instansi. Tidak bisa dibangun tanpa akses |
| **SSO, Mobile App, Portal Siswa/Wali** | Fase 5 sesuai [`01-PLAN-UTAMA.md`](./01-PLAN-UTAMA.md) |
| **Digital signature & legalisasi dokumen** | Butuh sertifikat digital & kebijakan hukum sekolah — bukan keputusan teknis semata |
| **RFID / mesin absensi** | Butuh perangkat keras & protokol vendor |
| **IP/IPK (bobot SKS)** | Hanya relevan untuk perguruan tinggi. Sekolah memakai rata-rata nilai + predikat |
| **Plagiarism check pada assignment** | Butuh layanan pihak ketiga berbayar |
| **Multi-level approval penuh** | Ditunda sampai A10; approval sederhana (satu level) dulu supaya tidak over-engineering |

---

## 10. Keputusan Arsitektur yang Mengikat

1. **Satu tabel `assessments` untuk semua jenis penilaian.** Jenis penilaian adalah data (`assessment_types`), bukan tabel terpisah. Menambah jenis penilaian baru tidak boleh butuh migrasi.
2. **Kebijakan penilaian berversi.** `grading_formulas` & `curriculum_structures` punya `version` + `effective_academic_year_id`. Nilai & kelas yang sudah berjalan menunjuk ke versi yang dipakai saat itu.
3. **Nilai mentah tidak boleh diubah diam-diam.** Perubahan nilai masuk ke `score_revisions` dengan alasan. `activity_log` (spatie, sudah terpasang) dipakai untuk audit perubahan data akademik lain.
4. **Keputusan kenaikan kelas & kelulusan menyimpan snapshot.** Simpan nilai/kehadiran/poin saat keputusan diambil, jangan hitung ulang dari data terkini.
5. **Menu kompleks tidak memakai pola generik `MasterDataController`.** Pola generik hanya untuk master data sederhana. Penilaian, jadwal, presensi, rapor → controller khusus.
6. **Setiap tahap wajib punya feature test.** Aturan bentrok jadwal, satu wali utama, rollback kenaikan kelas, dan lock nilai adalah logika yang paling mudah rusak diam-diam.

---

## 11. Checklist

### A0 — Seeder Fondasi
- [x] `RegionSeeder` — provinsi, kabupaten, kecamatan, kelurahan
- [x] `SubjectSeeder` — mapel SMA lengkap per kurikulum & jenjang
- [x] `RoomSeeder` — ruang kelas, lab, perpustakaan, kantor
- [x] `AchievementTypeSeeder`
- [x] Daftarkan di `DatabaseSeeder`

### A1 — Mapel per Kelas
- [x] Backend: list / tambah / ubah guru / hapus mapel per kelas
- [x] Aksi "salin mapel dari kelas lain"
- [x] Validasi: guru harus `is_teacher`, mapel tidak boleh duplikat di kelas yang sama
- [x] Frontend: modal Mapel per Kelas + tombol di `ClassesPage`
- [x] Feature test

### A2 — Jadwal Pelajaran
- [x] Migrasi `time_slots` (+ `schedule_changes`, perluasan `schedules`)
- [x] CRUD jadwal + jadwal per kelas / guru / ruang
- [x] Deteksi bentrok: kelas, guru, ruang, jam terbalik (masing-masing satu test)
- [x] Grid jadwal mingguan
- [x] Perubahan & pembatalan jadwal, guru pengganti

### A3 — Data Siswa & Wali Murid
- [x] Migrasi `student_histories` (riwayat kelas per tahun ajaran)
- [x] CRUD siswa lengkap (list/filter/cari, detail, tambah, ubah, hapus, aktif–nonaktif)
- [x] Akun portal siswa (polymorphic `users.userable`)
- [ ] **Upload foto siswa** — kolom `students.photo_path` sudah ada, endpoint & UI belum
- [x] Dropdown wilayah berjenjang (provinsi → kota → kecamatan → desa, saling mengunci)
- [x] Validasi rantai wilayah + kode pos terisi otomatis dari desa
- [x] CRUD wali murid mandiri
- [x] Relasi siswa–wali (banyak-ke-banyak) dengan **satu wali utama per siswa** ditegakkan di server
- [x] Wali bisa ditautkan dari data yang ada **atau** dibuat sekaligus dari form siswa
- [x] `student_histories` + riwayat kelas, sinkron otomatis ke `class_students`
- [x] Seeder siswa (`StudentSeeder`) — 40 siswa, 40 wali, 40 riwayat
- [x] Feature test — 37 test / 116 assertion (termasuk tiap aturan wali utama)

**Catatan desain A3:** penghapusan riwayat kelas ikut menghapus keanggotaan rombel yang
dibuat oleh riwayat itu (simetris dengan saat pembuatannya). Tanpa ini, `class_students`
menjadi data yatim dan siswa tidak akan pernah bisa dihapus.

### A4 — Kalender Akademik
- [ ] Migrasi `academic_events`, `event_types`, `effective_days`
- [ ] CRUD event + tampilan kalender
- [ ] Perhitungan hari efektif per semester

### A5 — Kurikulum Engine
- [ ] Migrasi 5 tabel struktur kurikulum
- [ ] CRUD kelompok mapel, program, prasyarat
- [ ] Struktur kurikulum per jenjang/tingkat + alokasi JP
- [ ] Aksi "terapkan kurikulum ke kelas"
- [ ] Feature test versi kurikulum

### A6 — Presensi
- [ ] Migrasi presensi siswa, guru, koreksi
- [ ] Input presensi harian & per mapel
- [ ] Rekap + persentase kehadiran (memakai hari efektif)
- [ ] Koreksi + approval + riwayat
- [ ] Feature test

### A7 — Grading & Assessment Engine
- [ ] Migrasi skala nilai, jenis penilaian, bobot, KKM, formula
- [ ] CRUD penilaian + input nilai
- [ ] Endpoint preview perhitungan nilai (tanpa simpan)
- [ ] Publikasi & lock nilai + audit revisi
- [ ] Remedial & pengayaan
- [ ] Feature test perhitungan nilai

### A8 — Bank Soal & Ujian
- [ ] Migrasi soal, paket, jadwal ujian, peserta, ruang
- [ ] CRUD bank soal + tagging (topik, kompetensi, kesulitan, Bloom)
- [ ] Paket ujian + randomisasi
- [ ] Jadwal ujian + pengawas + berita acara

### A9 — Rapor & Dokumen
- [ ] Migrasi `report_cards`, `transcripts`, `academic_documents`
- [ ] Generate rapor semester + deskripsi
- [ ] Cetak PDF (install `barryvdh/laravel-dompdf`)
- [ ] Transkrip akademik

### A10 — Promotion Engine
- [ ] Migrasi aturan & keputusan kenaikan kelas, kelulusan
- [ ] Syarat naik kelas (nilai, kehadiran, remedial, pelanggaran)
- [ ] Halaman pratinjau + proses massal bertransaksi
- [ ] Keputusan + approval + riwayat
- [ ] Feature test rollback & snapshot keputusan

### A11 — Pembelajaran
- [ ] Migrasi jurnal mengajar, RPP, assignment, submission
- [ ] Jurnal mengajar harian
- [ ] RPP / modul ajar
- [ ] Assignment + submission + penilaian

### A12 — Analytics & Workflow
- [ ] Dashboard akademik
- [ ] Analytics: nilai per kelas/mapel/guru, distribusi, ketuntasan, tren
- [ ] Early warning (nilai rendah, kehadiran rendah, risiko tidak naik kelas)
- [ ] Workflow approval multi-level
- [ ] Export/Import Excel (install `maatwebsite/excel`)

---

## 12. Dokumen Terkait

- [`01-PLAN-UTAMA.md`](./01-PLAN-UTAMA.md) — arsitektur, roadmap keseluruhan
- [`03-RANCANGAN-DATABASE.md`](./03-RANCANGAN-DATABASE.md) — skema database & ERD
- [`04-SPESIFIKASI-API-FRONTEND.md`](./04-SPESIFIKASI-API-FRONTEND.md) — spesifikasi REST API
- [`05-PLAN-FASE-2.md`](./05-PLAN-FASE-2.md) — breakdown eksekusi Fase 2 (2A–2E)
- [`PROGRESS.md`](./PROGRESS.md) — progres implementasi
